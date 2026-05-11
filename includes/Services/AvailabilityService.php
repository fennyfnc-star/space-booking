<?php declare(strict_types=1);

namespace SpaceBooking\Services;

use SpaceBooking\Services\Traits\HasConflictGroups;
use SpaceBooking\Services\Traits\HasDynamicSlots;
use SpaceBooking\Services\Traits\HasFixedSlots;
use SpaceBooking\Services\Traits\HasOverlapDetection;
use SpaceBooking\Services\Traits\HasSlotGeneration;
use SpaceBooking\Services\BookingRepository;
use DateInterval;
use DateTime;

/**
 * Generates available time slots for a given Space + Date.
 *
 * Merges global hours with per-space day overrides, then subtracts
 * confirmed bookings to return an array of open 1-hour (or custom) slots.
 *
 * Refactored to use traits for better maintainability.
 */
final class AvailabilityService
{
	use HasConflictGroups;
	use HasSlotGeneration;
	use HasOverlapDetection;
	use HasFixedSlots;
	use HasDynamicSlots;

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
	 * Get the repository instance (for trait compatibility).
	 */
	protected function getRepository(): BookingRepository
	{
		return $this->repo;
	}

	/**
	 * NEW: Get INTERSECTION of available slots for multiple spaces.
	 * Returns only slots that are available in ALL selected spaces.
	 * Also identifies which spaces are blocking availability.
	 */
	public function get_intersection_slots(array $space_ids, string $date, int $step_mins = 60): array
	{
		if (empty($space_ids)) {
			return ['slots' => [], 'blockers' => [], 'is_intersection' => false];
		}

		// Check global resources that block across ALL spaces
		$global_blockers = $this->get_global_resource_blockers($date, '10:00', '12:00');
		if (!empty($global_blockers)) {
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

		$primary_id = $space_ids[0] ?? 0;
		$slots_result = $this->get_slots($primary_id, $date, $step_mins);
		$raw_slots = $slots_result['slots'] ?? [];

		$has_blocked = false;
		foreach ($raw_slots as $slot) {
			if (empty($slot['available'])) {
				$has_blocked = true;
				break;
			}
		}

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
				break;
			}

			return [
				'slots' => array_values(array_filter($raw_slots, fn($s) => !empty($s['available']))),
				'blockers' => $blockers,
				'is_intersection' => true
			];
		}

		if (count($space_ids) === 1) {
			return [
				'slots' => $slots_result['slots'],
				'blockers' => [],
				'is_intersection' => false
			];
		}

		error_log('AVAIL INTERSECTION: Checking ' . count($space_ids) . ' spaces for common slots');

		$per_space_slots = [];
		$available_counts = [];

		foreach ($space_ids as $space_id) {
			$slots_result = $this->get_slots($space_id, $date, $step_mins);
			$raw_slots = $slots_result['slots'];

			// Store ALL slots (not just available) for proper intersection
			$per_space_slots[$space_id] = array_values($raw_slots);

			$available = array_filter($raw_slots, fn($s) => !empty($s['available']));
			$available_counts[$space_id] = count($available);
			error_log("AVAIL INTERSECTION: Space $space_id has " . count($available) . ' available slots out of ' . count($raw_slots));
		}

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

		if (!empty($blockers)) {
			return [
				'slots' => [],
				'blockers' => $blockers,
				'is_intersection' => true
			];
		}

		// Find intersection using start-end keys
		$common_slot_keys = null;
		foreach ($space_ids as $space_id) {
			$slot_keys = [];
			foreach ($per_space_slots[$space_id] as $slot) {
				$key = $slot['start'] . '-' . $slot['end'];
				$slot_keys[$key] = [
					'start' => $slot['start'],
					'end' => $slot['end'],
					'available' => $slot['available'] ?? true,
					'slot_id' => $slot['start'] . '|' . $slot['end'],
				];
			}

			error_log("SB_DEBUG: Space $space_id slot keys: " . json_encode(array_keys($slot_keys)));

			if ($common_slot_keys === null) {
				$common_slot_keys = array_keys($slot_keys);
			} else {
				$common_slot_keys = array_intersect($common_slot_keys, array_keys($slot_keys));
			}
		}

		error_log('SB_DEBUG: Common slot keys after intersection: ' . json_encode($common_slot_keys));

		$common_slots = [];
		if (!empty($common_slot_keys)) {
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

		if (count($common_slots) > 0) {
			foreach ($space_ids as $space_id) {
				$already_blocked = false;
				foreach ($blockers as $b) {
					if ($b['id'] === $space_id) {
						$already_blocked = true;
						break;
					}
				}
				if ($already_blocked) {
					continue;
				}

				$blocking_intervals = $this->repo->get_blocking_intervals([$space_id], $date);
				if (!empty($blocking_intervals)) {
					$title = get_the_title($space_id) ?: "Space #$space_id";
					$blockers[] = [
						'id' => $space_id,
						'title' => $title,
						'reason' => 'booked',
						'message' => "Reason: {$title} is already booked for this time."
					];
				}
			}
		}

		if (empty($blockers)) {
			$min_available = min($available_counts);
			if ($min_available > 0 && count($common_slots) === 0) {
				$min_count = $min_available;
				foreach ($space_ids as $space_id) {
					if ($available_counts[$space_id] === $min_count) {
						$title = get_the_title($space_id) ?: "Space #$space_id";
						$blockers[] = [
							'id' => $space_id,
							'title' => $title,
							'reason' => 'limited_availability',
							'message' => "Reason: {$title} has limited availability at this time."
						];
					}
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

	public function get_slots(int|array $space_ids, string $date, int $step_mins = 60): array
	{
		if (!is_array($space_ids)) {
			$space_ids = [$space_ids];
		}

		$space_ids = array_values($space_ids);
		error_log('AVAIL DEBUG: get_slots called for space_ids=' . json_encode($space_ids) . ", date=$date");

		$all_results = [];
		foreach ($space_ids as $space_id) {
			$fixed = $this->get_fixed_slots([$space_id], $date);

			if (count($fixed) > 0) {
				$all_results[$space_id] = [
					'slots' => $fixed,
					'has_fixed_slots' => true
				];
			} else {
				$dynamic = $this->generate_dynamic_slots([$space_id], $date, $step_mins);
				$all_results[$space_id] = [
					'slots' => $dynamic,
					'has_fixed_slots' => false
				];
			}
		}

		if (count($space_ids) === 1) {
			return $all_results[$space_ids[0]];
		}

		return [
			'slots' => $all_results,
			'has_fixed_slots' => false,
			'multi_space' => true
		];
	}

	/**
	 * Get global resources that are booked for the given time.
	 */
	private function get_global_resource_blockers(string $date, string $start_time, string $end_time): array
	{
		global $wpdb;

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
