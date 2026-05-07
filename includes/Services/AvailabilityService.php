<?php declare(strict_types=1);

namespace SpaceBooking\Services;

use SpaceBooking\Services\BookingRepository;
use DateInterval;
use DateTime;

/**
 * Generates available time slots for a given Space + Date.
 *
 * Merges global hours with per-space day overrides, then subtracts
 * confirmed bookings to return an array of open 1-hour (or custom) slots.
 */
final class AvailabilityService
{
	private BookingRepository $repo;

	public function __construct(BookingRepository $repo = null)
	{
		if ($repo === null) {
			$this->repo = new BookingRepository();
		} else {
			$this->repo = $repo;
		}
	}

	/**
	 * Get all space IDs in the bidirectional conflict group for a space (downstream deps + upstream parents + recursion)
	 * @return array<int> Unique space IDs
	 */
	public function get_conflict_group_ids(int $space_id): array
	{
		$group = [$space_id];
		$visited = [$space_id => true];

		// Bidirectional DFS
		$this->collect_conflicts($space_id, $group, $visited);

		return array_values($group);
	}

	private function collect_conflicts(int $id, array &$group, array &$visited): void
	{
		global $wpdb;

		// Downstream: my dependencies
		$my_deps = get_post_meta($id, '_sb_resource_dependencies', true) ?: [];
		foreach ((array) $my_deps as $child_id) {
			$child_id = (int) $child_id;
			if ($child_id && !isset($visited[$child_id])) {
				$visited[$child_id] = true;
				$group[] = $child_id;
				$this->collect_conflicts($child_id, $group, $visited);
			}
		}

		// Upstream: spaces that depend on me (reverse edges)
		$parents = $wpdb->get_col($wpdb->prepare("
			SELECT pm.post_id 
			FROM {$wpdb->postmeta} pm
			JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_sb_resource_dependencies' 
		\t  AND pm.meta_value LIKE %s
		\t  AND p.post_type = 'sb_space'
		\t  AND pm.post_id != %d
		", '%i:' . $id . ';%', $id));
		foreach ($parents as $parent_id) {
			if (!isset($visited[$parent_id])) {
				$visited[$parent_id] = true;
				$group[] = $parent_id;
				$this->collect_conflicts($parent_id, $group, $visited);
			}
		}
	}

	/**
	 * Get unioned conflict groups for multiple space IDs
	 */
	public function get_conflict_groups(array $space_ids): array
	{
		$all_conflicts = [];
		$master_visited = [];

		foreach ($space_ids as $id) {
			if (!isset($master_visited[$id])) {
				$this->collect_conflicts($id, $all_conflicts, $master_visited);
			}
		}

		return array_unique($all_conflicts);
	}

	/**
	 * NEW: Get INTERSECTION of available slots for multiple spaces.
	 * Returns only slots that are available in ALL selected spaces.
	 * Also identifies which spaces are blocking availability.
	 *
	 * NEW: Also checks GLOBAL resources (Bouncy Castles, etc) that block across ALL spaces.
	 *
	 * @param array $space_ids Array of space IDs to check
	 * @param string $date Date string Y-m-d
	 * @param int $step_mins Slot interval in minutes
	 * @return array {
	 *   'slots' => array of common available slots,
	 *   'blockers' => array of blocker space info [{id, title, reason}],
	 *   'is_intersection' => bool
	 * }
	 */
	public function get_intersection_slots(array $space_ids, string $date, int $step_mins = 60): array
	{
		if (empty($space_ids)) {
			return ['slots' => [], 'blockers' => [], 'is_intersection' => false];
		}

		// NEW: Check global resources that block across ALL spaces
		// Global resources are booked extras (sb_extra) with _sb_is_global_resource = 1
		$global_blockers = $this->get_global_resource_blockers($date, '10:00', '12:00');
		if (!empty($global_blockers)) {
			// Global resource is booked - BLOCK all spaces
			$blockers = [];
			foreach ($global_blockers as $resource) {
				$title = get_the_title($resource['space_id']) ?: "Global Resource #{$resource['space_id']}";
				$blockers[] = [
					'id' => $resource['space_id'],
					'title' => $title,
					'reason' => 'global_resource',
					'message' => "Reason: {$title} is already booked for this time."
				];
			}
			return [
				'slots' => [],
				'blockers' => $blockers,
				'is_intersection' => true
			];
		}

		// FIXED: For ALL space checks (including single), detect blockers
		// This ensures blocker messages are returned even for single-space queries
		$primary_id = $space_ids[0] ?? 0;
		$slots_result = $this->get_slots($primary_id, $date, $step_mins);
		$raw_slots = $slots_result['slots'] ?? [];

		// Check if there are any blocked slots - that's our signal for blockers
		$has_blocked = false;
		foreach ($raw_slots as $slot) {
			if (empty($slot['available'])) {
				$has_blocked = true;
				break;
			}
		}

		// If single space and has blocked slots, return blockers
		if (count($space_ids) === 1 && $has_blocked) {
			$blocking_intervals = $this->repo->get_blocking_intervals($space_ids, $date);
			$blockers = [];

			foreach ($blocking_intervals as $block) {
				$title = get_the_title($primary_id) ?: "Space #$primary_id";
				$blockers[] = [
					'id' => $primary_id,
					'title' => $title,
					'reason' => 'booked',
					'message' => "Reason: {$title} is already booked for this time."
				];
				break;  // Only need one blocker entry
			}

			return [
				'slots' => array_filter($raw_slots, fn($s) => !empty($s['available'])),
				'blockers' => $blockers,
				'is_intersection' => true
			];
		}

		// Single space with no blocks - use existing behavior
		if (count($space_ids) === 1) {
			return [
				'slots' => $slots_result['slots'],
				'blockers' => [],
				'is_intersection' => false
			];
		}

		error_log('AVAIL INTERSECTION: Checking ' . count($space_ids) . ' spaces for common slots');

		// Get slots for EACH space individually
		$per_space_slots = [];
		$available_counts = [];

		foreach ($space_ids as $space_id) {
			$slots_result = $this->get_slots($space_id, $date, $step_mins);
			$raw_slots = $slots_result['slots'];

			// Filter to only available slots for this space
			$available = array_filter($raw_slots, fn($s) => !empty($s['available']));

			$per_space_slots[$space_id] = array_values($available);
			$available_counts[$space_id] = count($available);

			error_log("AVAIL INTERSECTION: Space $space_id has " . count($available) . ' available slots');
		}

		// Identify blockers (spaces with NO availability)
		$blockers = [];
		foreach ($space_ids as $space_id) {
			if ($available_counts[$space_id] === 0) {
				$title = get_the_title($space_id) ?: "Space #$space_id";
				$blockers[] = [
					'id' => $space_id,
					'title' => $title,
					'reason' => 'fully_booked',
					'message' => "There is no available time slot for the selected spaces. Reason: {$title} is currently booked."
				];
				error_log("AVAIL INTERSECTION: BLOCKER - Space $space_id ($title) has no availability");
			}
		}

		// If any space has no slots, return empty with blockers
		if (!empty($blockers)) {
			return [
				'slots' => [],
				'blockers' => $blockers,
				'is_intersection' => true
			];
		}

		// Find INTERSECTION: slots available in ALL spaces
		// Normalize slot keys for comparison (use start-end as key)
		$common_slot_keys = null;

		foreach ($space_ids as $space_id) {
			$slot_keys = [];
			foreach ($per_space_slots[$space_id] as $slot) {
				$key = $slot['start'] . '-' . $slot['end'];
				$slot_keys[$key] = $slot;
			}

			if ($common_slot_keys === null) {
				$common_slot_keys = array_keys($slot_keys);
			} else {
				$common_slot_keys = array_intersect($common_slot_keys, array_keys($slot_keys));
			}
		}

		// Build common slots list
		$common_slots = [];
		if (!empty($common_slot_keys)) {
			// Use first space's slot data as template
			$first_space = $space_ids[0];
			$first_slots_by_key = [];
			foreach ($per_space_slots[$first_space] as $slot) {
				$key = $slot['start'] . '-' . $slot['end'];
				$first_slots_by_key[$key] = $slot;
			}

			foreach ($common_slot_keys as $key) {
				if (isset($first_slots_by_key[$key])) {
					$common_slots[] = $first_slots_by_key[$key];
				}
			}
		}

		// Check for partial blockers (spaces with some unavailable slots)
		$min_available = min($available_counts);
		if ($min_available > 0 && count($common_slots) === 0) {
			// All spaces have some slots, but no common overlap
			foreach ($space_ids as $space_id) {
				if ($available_counts[$space_id] === $min_available && !isset(array_combine($space_ids, $available_counts)[$space_id])) {
					// Already counted as full blocker
				}
			}
			// The spaces with the fewest slots are causing the issue
			$min_count = $min_available;
			foreach ($space_ids as $space_id) {
				if ($available_counts[$space_id] === $min_count && !in_array($space_id, array_column($blockers, 'id'))) {
					$title = get_the_title($space_id) ?: "Space #$space_id";
					$blockers[] = [
						'id' => $space_id,
						'title' => $title,
						'reason' => 'limited_availability'
					];
				}
			}
		}

		error_log('AVAIL INTERSECTION: Found ' . count($common_slots) . ' common slots from ' . count($space_ids) . ' spaces');

		return [
			'slots' => $common_slots,
			'blockers' => $blockers,
			'is_intersection' => true
		];
	}

	/**
	 * New fixed slots logic - load from meta, check availability against booked
	 */
	public function get_fixed_slots(array|int $space_ids, string $date): array
	{
		if (!is_array($space_ids)) {
			$space_ids = [$space_ids];
		}

		$primary_id = $space_ids[array_key_first($space_ids)] ?? $space_ids[0] ?? 0;

		if ($primary_id === 0) {
			return [];
		}

		$date_overrides = get_post_meta($primary_id, '_sb_date_overrides', true);
		if (is_array($date_overrides) && isset($date_overrides[$date])) {
			$override = $date_overrides[$date];
			if ($override['status'] === 'closed') {
				return [];
			}
			if ($override['status'] === 'custom' && !empty($override['slots'])) {
				$fixed_slots = $override['slots'];
			} else {
				return [];
			}
		} else {
			$fixed_slots = get_post_meta($primary_id, '_sb_fixed_slots', true);
			if (!is_array($fixed_slots) || empty($fixed_slots)) {
				return [];  // No fixed slots defined
			}
		}

		// UNIFIED: Use single method to get ALL blocking intervals
		$all_blocked = $this->repo->get_blocking_intervals($space_ids, $date);
		error_log('SB_DEBUG: get_fixed_slots blocking_intervals count: ' . count($all_blocked) . ' for date: ' . $date);
		if (!empty($all_blocked)) {
			error_log('SB_DEBUG: First blocking: ' . json_encode($all_blocked[0]));
		}

		$space_pre_buf = (int) get_post_meta($primary_id, '_sb_buffer_pre_minutes', true) ?: (int) get_option('sb_buffer_pre_minutes', 0);
		$space_post_buf = (int) get_post_meta($primary_id, '_sb_buffer_post_minutes', true) ?: (int) get_option('sb_buffer_post_minutes', 0);

		$slots = [];
		foreach ($fixed_slots as $slot_data) {
			$pre_buf = $slot_data['pre_buffer'] ?? $space_pre_buf;
			$post_buf = $slot_data['post_buffer'] ?? $space_post_buf;

			$slot_start = $this->add_minutes($slot_data['start_time'], -$pre_buf);
			$slot_end = $this->add_minutes($slot_data['end_time'], $post_buf);

			$is_available = !self::overlaps($slot_start, $slot_end, $all_blocked);

			// Since we now get ALL blocking intervals in one query, we can't easily distinguish
			// pending vs confirmed. For simplicity, just mark as unavailable if blocked.
			$has_pending = false;

			// Detailed slot status logging
			$slot_status = $is_available ? 'AVAILABLE' : 'BLOCKED';
			error_log(sprintf('SB_DEBUG: Slot %s-%s → available=%d status=%s',
				$slot_data['start_time'], $slot_data['end_time'],
				$is_available ? 1 : 0,
				$slot_status));

			$slots[] = [
				'slot_id' => $slot_data['slot_id'],
				'start' => $slot_data['start_time'],
				'end' => $slot_data['end_time'],
				'available' => $is_available,
				'has_pending' => $has_pending,
				'override_price' => $slot_data['override_price'],
				'pre_buffer' => $pre_buf,
				'post_buffer' => $post_buf,
				'capacity' => $slot_data['capacity'] ?? 1
			];
		}

		return $slots;
	}

	public function has_fixed_slots_defined(array|int $space_ids): bool
	{
		if (!is_array($space_ids)) {
			$space_ids = [$space_ids];
		}

		$primary_id = $space_ids[array_key_first($space_ids)] ?? $space_ids[0] ?? 0;

		if ($primary_id === 0) {
			return false;
		}

		// Check default fixed slots
		$fixed_slots = get_post_meta($primary_id, '_sb_fixed_slots', true);
		if (is_array($fixed_slots) && !empty($fixed_slots)) {
			return true;
		}

		// Check date-specific overrides
		$date_overrides = get_post_meta($primary_id, '_sb_date_overrides', true);
		if (is_array($date_overrides)) {
			foreach ($date_overrides as $override) {
				if (isset($override['status']) && $override['status'] === 'custom' && !empty($override['slots'])) {
					return true;
				}
			}
		}

		return false;
	}

	public function get_slots(int|array $space_ids, string $date, int $step_mins = 60): array
	{
		if (!is_array($space_ids))
			$space_ids = [$space_ids];

		$primary_id = $space_ids[array_key_first($space_ids)] ?? $space_ids[0] ?? 0;

		error_log('AVAIL DEBUG: get_slots called for space_ids=' . print_r($space_ids, true) . ", primary_id=$primary_id, date=$date");

		// 1. FIRST: Attempt fixed slots (now using conflict_ids inside get_fixed_slots)
		$fixed = $this->get_fixed_slots($space_ids, $date);
		error_log('AVAIL DEBUG: Fixed slots count: ' . count($fixed));

		if (count($fixed) > 0) {
			error_log('AVAIL DEBUG: Using FIXED slots');
			return [
				'slots' => $fixed,
				'has_fixed_slots' => true
			];
		}

		// 2. FALLBACK: No fixed slots found → dynamic generation
		error_log("AVAIL DEBUG: No fixed slots found for ID $primary_id, falling back to dynamic generation.");
		$dynamic = $this->generate_dynamic_slots($space_ids, $date, $step_mins);
		return [
			'slots' => $dynamic,
			'has_fixed_slots' => false
		];
	}

	/**
	 * Returns open/close times for the given space & date, applying overrides.
	 * Returns [ '09:00', '22:00' ] or [ null, null ] if closed.
	 */
	public function resolve_buffers(int $space_id): array
	{
		$pre = (int) get_post_meta($space_id, '_sb_buffer_pre_minutes', true);
		$post = (int) get_post_meta($space_id, '_sb_buffer_post_minutes', true);

		if ($pre === 0) {
			$pre = (int) get_option('sb_buffer_pre_minutes', 0);
		}
		if ($post === 0) {
			$post = (int) get_option('sb_buffer_post_minutes', 0);
		}

		return [$pre, $post];
	}

	/**
	 * Generate dynamic slots using global/space hours as fallback when fixed slots empty
	 */
	public function generate_dynamic_slots(int|array $space_ids, string $date, int $step_mins = 60): array
	{
		if (!is_array($space_ids))
			$space_ids = [$space_ids];

		// FIXED: Use requested space_ids directly instead of get_conflict_groups()
		// The shadow booking system ensures that ANY booking (lead OR shadow) for a space_id
		// is marked in the database. We don't need resource dependencies to find conflicts.
		// This prevents the availability logic leak where single-space searches bypass shadows.

		// Primary space for meta
		$primary_id = $space_ids[array_key_first($space_ids)] ?? $space_ids[0] ?? 0;

		error_log('AVAIL DEBUG: generate_dynamic_slots for primary_id=' . $primary_id);

		[$open, $close] = $this->resolve_effective_hours($primary_id, $date);
		error_log("AVAIL DEBUG: dynamic effective open=$open, close=$close");

		if (!$open || !$close) {
			error_log('AVAIL DEBUG: Space closed for dynamic, empty slots');
			return [];  // Primary space closed
		}

		$slots = $this->generate_slots($open, $close, $step_mins);
		error_log('AVAIL DEBUG: Generated raw dynamic slots count: ' . count($slots));

		// UNIFIED: Use single method to get ALL blocking intervals
		$all_blocked = $this->repo->get_blocking_intervals($space_ids, $date);
		error_log('SB_DEBUG: generate_dynamic_slots blocking_intervals count: ' . count($all_blocked) . ' for date: ' . $date . ', space_ids: ' . json_encode($space_ids));
		if (!empty($all_blocked)) {
			error_log('SB_DEBUG: First blocking: ' . json_encode($all_blocked[0]));
		}

		[$pre_buf, $post_buf] = $this->resolve_buffers($primary_id);
		error_log("AVAIL DEBUG: Dynamic buffers pre=$pre_buf post=$post_buf");

		// Inflate blocking intervals with buffers
		$inflated_intervals = array_map(function ($b) use ($pre_buf, $post_buf) {
			return [
				'start' => $this->add_minutes($b['start'], -$pre_buf),
				'end' => $this->add_minutes($b['end'], $post_buf),
			];
		}, $all_blocked);

		$available_count = 0;
		$final_slots = array_map(static function (array $slot) use ($inflated_intervals, &$available_count): array {
			$is_available = !self::overlaps($slot['start'], $slot['end'], $inflated_intervals);

			$slot_status = $is_available ? 'AVAILABLE' : 'BLOCKED';
			error_log(sprintf('SB_DEBUG: Slot %s-%s → available=%d status=%s',
				$slot['start'], $slot['end'],
				$is_available ? 1 : 0,
				$slot_status));

			if ($is_available)
				$available_count++;
			$slot['available'] = $is_available;
			$slot['has_pending'] = false;  // Unified: simplify since we can't distinguish
			return $slot;
		}, $slots);

		error_log("AVAIL DEBUG: Final dynamic available slots: $available_count / " . count($slots));

		return $final_slots;
	}

	public function resolve_effective_hours(int $space_id, string $date): array
	{
		[$raw_open, $raw_close] = $this->resolve_hours($space_id, $date);
		error_log("AVAIL DEBUG: raw hours open=$raw_open close=$raw_close for space $space_id date $date");

		[$pre_buf, $post_buf] = $this->resolve_buffers($space_id);
		error_log("AVAIL DEBUG: buffers pre=$pre_buf post=$post_buf");

		if (!$raw_open || !$raw_close) {
			error_log('AVAIL DEBUG: Raw hours null, returning null');
			return [null, null];
		}

		$effective_open = $this->add_minutes($raw_open, $pre_buf);
		$effective_close = $this->add_minutes($raw_close, -$post_buf);
		error_log("AVAIL DEBUG: effective open=$effective_open close=$effective_close");

		// Allow if buffers eat entire day (still generate slots in raw window)
		$raw_open_min = $this->time_to_minutes($raw_open);
		$raw_close_min = $this->time_to_minutes($raw_close);
		if ($raw_open_min >= $raw_close_min) {
			error_log('AVAIL DEBUG: Raw duration invalid, returning null');
			return [null, null];
		}
		return [$effective_open, $effective_close];
	}

	public function resolve_hours(int $space_id, string $date): array
	{
		$day_of_week = (int) (new DateTime($date))->format('w');  // 0=Sun … 6=Sat
		error_log("AVAIL DEBUG: resolve_hours space_id=$space_id date=$date day=$day_of_week");

		// Check per-space day overrides stored in post meta
		$overrides = get_post_meta($space_id, '_sb_day_overrides', true);
		error_log('AVAIL DEBUG: day_overrides=' . print_r($overrides, true));
		if (is_array($overrides) && isset($overrides[$day_of_week])) {
			$override = $overrides[$day_of_week];
			error_log('AVAIL DEBUG: found override=' . print_r($override, true));
			if (isset($override['closed']) && $override['closed']) {
				error_log('AVAIL DEBUG: Override closed=true');
				return [null, null];
			}
			$open = $override['open'] ?? null;
			$close = $override['close'] ?? null;
			error_log("AVAIL DEBUG: Override hours open=$open close=$close");
			return [$open, $close];
		}

		// Fallback to global defaults
		$global_open = get_option('sb_global_open_time', '09:00');
		$global_close = get_option('sb_global_close_time', '22:00');
		error_log("AVAIL DEBUG: Global fallback open=$global_open close=$global_close");

		return [$global_open, $global_close];
	}

	private function time_to_minutes(string $time): int
	{
		[$h, $m] = explode(':', $time);
		return (int) $h * 60 + (int) $m;
	}

	// ── Internal helpers ─────────────────────────────────────────────────────

	private function generate_slots(string $open, string $close, int $step_mins): array
	{
		$slots = [];
		$cursor = new DateTime("1970-01-01 {$open}");
		$end = new DateTime("1970-01-01 {$close}");
		$step = new DateInterval('PT' . $step_mins . 'M');

		while (true) {
			$slot_end = (clone $cursor)->add($step);
			if ($slot_end > $end) {
				break;
			}
			$slots[] = [
				'start' => $cursor->format('H:i'),
				'end' => $slot_end->format('H:i'),
			];
			$cursor->add($step);
		}

		return $slots;
	}

	private function add_minutes(string $time_str, int $minutes): string
	{
		$dt = new DateTime("1970-01-01 {$time_str}");
		$interval_str = 'PT' . abs($minutes) . 'M';
		if ($minutes < 0) {
			$dt->sub(new DateInterval($interval_str));
		} else {
			$dt->add(new DateInterval($interval_str));
		}
		$hours = (int) $dt->format('H');
		$mins = (int) $dt->format('i');
		if ($hours < 0)
			$hours = 0;
		if ($hours > 23)
			$hours = 23;
		return sprintf('%02d:%02d', $hours, $mins);
	}

	/**
	 * Returns true if [slotStart, slotEnd) overlaps any of the booked intervals.
	 */
	private static function overlaps(string $slot_start, string $slot_end, array $booked): bool
	{
		foreach ($booked as $b) {
			// Overlap condition: slot_start < b_end AND slot_end > b_start
			if ($slot_start < $b['end'] && $slot_end > $b['start']) {
				return true;
			}
		}
		return false;
	}

	/**
	 * NEW: Get global resources that are booked for the given time.
	 * Global resources (Bouncy Castles, Projectors, etc) block across ALL spaces.
	 * They are stored in sb_bookings table with space_id = extra_id.
	 *
	 * @param string $date Y-m-d
	 * @param string $start_time H:i
	 * @param string $end_time H:i
	 * @return array Array of booked global resource space_ids
	 */
	private function get_global_resource_blockers(string $date, string $start_time, string $end_time): array
	{
		global $wpdb;

		// Find all extras marked as global resources
		$global_resources = $wpdb->get_col("
			SELECT p.ID 
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'sb_extra'
			AND p.post_status = 'publish'
			AND pm.meta_key = '_sb_is_global_resource'
			AND pm.meta_value = '1'
		");

		if (empty($global_resources)) {
			return [];
		}

		// Check if any of these global resources are booked
		$placeholders = implode(',', array_fill(0, count($global_resources), '%d'));
		$results = $wpdb->get_results($wpdb->prepare("
			SELECT DISTINCT space_id 
			FROM {$wpdb->prefix}sb_bookings 
			WHERE space_id IN ({$placeholders})
			AND booking_date = %s 
			AND status IN ('confirmed', 'in_review')
			AND start_time < %s 
			AND end_time > %s
		", array_merge($global_resources, [$date, $end_time, $start_time])), ARRAY_A);

		return $results ?: [];
	}
}
