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
	 *
	 * @param array $space_ids Space IDs to check availability for
	 * @param string $date Date to check (Y-m-d format)
	 * @param int $step_mins Slot interval in minutes
	 * @param array $package_ids Package IDs selected alongside spaces (for conflict detection)
	 */
	public function get_intersection_slots(array $space_ids, string $date, int $step_mins = 60, array $package_ids = []): array
	{
		if (empty($space_ids)) {
			return ['slots' => [], 'blockers' => [], 'is_intersection' => false];
		}

		// NEW: Resolve packages to their included spaces
		$package_included_spaces = [];
		foreach ($package_ids as $package_id) {
			$included_space_id = (int) get_post_meta($package_id, '_sb_package_space_id', true);
			if ($included_space_id) {
				$package_included_spaces[$package_id] = $included_space_id;
			}
		}

		// NEW: Check for EXISTING package bookings that include any of the spaces
		// This handles the case where a package was already booked (previous booking)
		// and now user tries to book a space that the package includes
		// FIX BUG 1: Only check the specific packages being selected, not ALL packages
		$existing_package_blockers = $this->get_existing_package_blockers($space_ids, $date, $package_ids);
		if (!empty($existing_package_blockers)) {
			error_log('AVAIL INTERSECTION: Existing packages blocking spaces: ' . json_encode($existing_package_blockers));
			return [
				'slots' => [],
				'blockers' => $existing_package_blockers,
				'is_intersection' => true
			];
		}

		// SCENARIO C FIX: When package + multiple spaces selected together,
		// we need to check availability for BOTH the explicit space_ids AND
		// the spaces covered by packages. The intersection should include ALL spaces.
		// This ensures Package A (Space #10) + Space #20 checks availability for BOTH #10 and #20.
		$all_space_ids_to_check = $space_ids;
		foreach ($package_included_spaces as $included_space_id) {
			if (!in_array($included_space_id, $all_space_ids_to_check)) {
				$all_space_ids_to_check[] = $included_space_id;
			}
		}
		$all_space_ids_to_check = array_unique($all_space_ids_to_check);

		error_log('AVAIL INTERSECTION: All spaces to check (including package spaces): ' . json_encode($all_space_ids_to_check));

		// SCENARIO A FIX: Check if any explicitly selected space_ids are included in selected packages
		// If user selects Space #10 directly AND Package A (includes #10), that's a conflict
		// FIX BUG 2: Only check conflicts when space was EXPLICITLY selected (not derived from package)
		// We need to compare against the ORIGINAL space_ids passed to the function, not all_space_ids_to_check
		$spaces_blocked_by_packages = [];
		foreach ($space_ids as $space_id) {
			foreach ($package_included_spaces as $pkg_id => $included_space_id) {
				if ($included_space_id === $space_id) {
					$spaces_blocked_by_packages[$space_id] = $pkg_id;
					break;
				}
			}
		}

		if (!empty($spaces_blocked_by_packages)) {
			$blockers = [];
			foreach ($spaces_blocked_by_packages as $blocked_space_id => $blocking_package_id) {
				$space_title = get_the_title($blocked_space_id) ?: "Space #$blocked_space_id";
				$package_title = get_the_title($blocking_package_id) ?: "Package #$blocking_package_id";
				$blockers[] = [
					'id' => $blocked_space_id,
					'title' => $space_title,
					'reason' => 'included_in_package',
					'message' => "Reason: This space is included in package '{$package_title}'."
				];
			}
			error_log('AVAIL INTERSECTION: Spaces blocked by packages: ' . json_encode($spaces_blocked_by_packages));
			return [
				'slots' => [],
				'blockers' => $blockers,
				'is_intersection' => true
			];
		}

		// SCENARIO D FIX: Check if packages are blocked by existing bookings
		// Use ALL spaces (explicit + package-included) for the blocking check
		// This ensures: Space #10 booked + Package A + Space #20 → Package A blocked, but we still check #20
		if (!empty($package_included_spaces)) {
			$package_blockers = $this->get_package_blockers_from_bookings($package_ids, $all_space_ids_to_check, $date);
			if (!empty($package_blockers)) {
				error_log('AVAIL INTERSECTION: Packages blocked by existing bookings: ' . json_encode($package_blockers));
				return [
					'slots' => [],
					'blockers' => $package_blockers,
					'is_intersection' => true
				];
			}
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

		// SCENARIO C: Use all_space_ids_to_check when packages are involved
		// This ensures Package A (Space #10) + Space #20 checks intersection of BOTH spaces
		$spaces_to_check = !empty($package_included_spaces) ? $all_space_ids_to_check : $space_ids;

		$primary_id = $spaces_to_check[0] ?? 0;
		$slots_result = $this->get_slots($primary_id, $date, $step_mins);
		$raw_slots = $slots_result['slots'] ?? [];

		$has_blocked = false;
		foreach ($raw_slots as $slot) {
			if (empty($slot['available'])) {
				$has_blocked = true;
				break;
			}
		}

		if (count($spaces_to_check) === 1 && $has_blocked) {
			$blocking_intervals = $this->repo->get_blocking_intervals($spaces_to_check, $date);
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

		if (count($spaces_to_check) === 1) {
			return [
				'slots' => $slots_result['slots'],
				'blockers' => [],
				'is_intersection' => false
			];
		}

		error_log('AVAIL INTERSECTION: Checking ' . count($spaces_to_check) . ' spaces for common slots');

		$per_space_slots = [];
		$available_counts = [];

		foreach ($spaces_to_check as $space_id) {
			$slots_result = $this->get_slots($space_id, $date, $step_mins);
			$raw_slots = $slots_result['slots'];

			// Store ALL slots (not just available) for proper intersection
			$per_space_slots[$space_id] = array_values($raw_slots);

			$available = array_filter($raw_slots, fn($s) => !empty($s['available']));
			$available_counts[$space_id] = count($available);
			error_log("AVAIL INTERSECTION: Space $space_id has " . count($available) . ' available slots out of ' . count($raw_slots));
		}

		$blockers = [];
		foreach ($spaces_to_check as $space_id) {
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

		// Find intersection: only slots that are available in ALL spaces
		$common_slots = [];
		
		// Get the first space's available slots as base
		$first_space_id = $spaces_to_check[0];
		$first_space_slots = $per_space_slots[$first_space_id];
		
		// Filter to only available slots in the first space
		$available_in_first = array_filter($first_space_slots, function($slot) {
			return !empty($slot['available']);
		});
		
		foreach ($available_in_first as $first_slot) {
			$slot_start = $first_slot['start'];
			$slot_end = $first_slot['end'];
			$slot_key = $slot_start . '-' . $slot_end;
			
			// Check if this slot is available in ALL other spaces
			$is_available_in_all = true;
			
			for ($i = 1; $i < count($spaces_to_check); $i++) {
				$space_id = $spaces_to_check[$i];
				$space_has_slot = false;
				$slot_available_in_space = false;
				
				// Find this exact time slot in the other space
				foreach ($per_space_slots[$space_id] as $space_slot) {
					if ($space_slot['start'] === $slot_start && $space_slot['end'] === $slot_end) {
						$space_has_slot = true;
						$slot_available_in_space = !empty($space_slot['available']);
						break;
					}
				}
				
				// If the slot doesn't exist in this space or isn't available, it's not in intersection
				if (!$space_has_slot || !$slot_available_in_space) {
					$is_available_in_all = false;
					break;
				}
			}
			
			// Only include if available in ALL spaces
			if ($is_available_in_all) {
				$common_slots[] = $first_slot; // Use the first space's slot data as the master
			}
		}
		
		error_log('SB_DEBUG: Common slots after intersection: ' . count($common_slots) . ' from ' . count($available_in_first) . ' available in first space');

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

		// Handle multi-space differently - calculate slots for each space individually
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

		// For multi-space scenarios, return combined result with individual slots
		$combined_slots = [];
		foreach ($all_results as $space_id => $result) {
			$combined_slots[$space_id] = $result['slots'];
		}

		return [
			'slots' => $combined_slots,
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

	/**
	 * NEW: Check if any selected packages are blocked by existing bookings for the given spaces.
	 * This handles the case where a space is booked directly, making packages
	 * that include that space unavailable.
	 *
	 * @param array $package_ids Package IDs to check
	 * @param array $space_ids Space IDs that are being booked
	 * @param string $date Date to check
	 * @return array Blocker info for packages
	 */
	private function get_package_blockers_from_bookings(array $package_ids, array $space_ids, string $date): array
	{
		if (empty($package_ids) || empty($space_ids)) {
			return [];
		}

		// Get existing bookings that block the spaces
		$existing_bookings = $this->repo->get_blocking_intervals($space_ids, $date);

		if (empty($existing_bookings)) {
			return [];
		}

		$blockers = [];

		// For each package, check if its included space has a booking
		foreach ($package_ids as $package_id) {
			$included_space_id = (int) get_post_meta($package_id, '_sb_package_space_id', true);

			// If this package has an included space and it's in our space_ids
			if ($included_space_id && in_array($included_space_id, $space_ids)) {
				// Check if there's a conflicting booking
				foreach ($existing_bookings as $booking) {
					$package_title = get_the_title($package_id) ?: "Package #$package_id";
					$space_title = get_the_title($included_space_id) ?: "Space #$included_space_id";

					$blockers[] = [
						'id' => $package_id,
						'title' => $package_title,
						'reason' => 'space_already_booked',
						'message' => "Reason: Space '{$space_title}' is already booked."
					];
					break; // Only one blocker per package needed
				}
			}
		}

		return $blockers;
	}

	/**
	 * NEW: Check for existing package bookings that include any of the given spaces.
	 * This handles the case where a package was already booked (previous booking)
	 * and now user tries to book a space that the package includes.
	 *
	 * FIX BUG 1: Now accepts $package_ids to only check the SPECIFIC packages being selected,
	 * not ALL packages that include the space.
	 *
	 * @param array $space_ids Space IDs to check
	 * @param string $date Date to check
	 * @param array $package_ids Specific package IDs to check (only these, not all packages)
	 * @return array Blocker info for packages
	 */
	private function get_existing_package_blockers(array $space_ids, string $date, array $package_ids = []): array
	{
		if (empty($space_ids)) {
			return [];
		}

		global $wpdb;

		// FIX BUG 1: If specific package_ids provided, only check those
		// Otherwise, find all packages that include any of these spaces
		if (!empty($package_ids)) {
			$packages_to_check = $package_ids;
		} else {
			// Fallback: Find all packages that include any of these spaces (original behavior)
			$packages_to_check = [];
			foreach ($space_ids as $space_id) {
				$packages = get_posts([
					'post_type' => 'sb_package',
					'posts_per_page' => -1,
					'meta_query' => [
						[
							'key' => '_sb_package_space_id',
							'value' => $space_id,
						],
					],
					'fields' => 'ids',
				]);
				foreach ($packages as $package_id) {
					if (!in_array($package_id, $packages_to_check)) {
						$packages_to_check[] = $package_id;
					}
				}
			}
		}

		if (empty($packages_to_check)) {
			return [];
		}

		// Check if any of these packages have existing bookings
		$placeholders = implode(',', array_fill(0, count($packages_to_check), '%d'));
		$bookings = $wpdb->get_results($wpdb->prepare("
			SELECT DISTINCT bp.package_id, bp.space_id, b.start_time, b.end_time, b.status
			FROM {$wpdb->prefix}sb_booking_packages bp
			JOIN {$wpdb->prefix}sb_bookings b ON bp.booking_id = b.id
			WHERE bp.package_id IN ({$placeholders})
			AND b.booking_date = %s
			AND (
				b.status IN ('confirmed', 'in_review', 'paid')
				OR (b.status = 'pending' AND b.expired_at > NOW())
			)
		", array_merge($packages_to_check, [$date])), ARRAY_A);

		if (empty($bookings)) {
			return [];
		}

		$blockers = [];
		foreach ($bookings as $booking) {
			$package_id = (int) $booking['package_id'];
			$space_id = (int) $booking['space_id'];
			$package_title = get_the_title($package_id) ?: "Package #$package_id";
			$space_title = get_the_title($space_id) ?: "Space #$space_id";

			// Avoid duplicates
			if (!isset($blockers[$package_id])) {
				$blockers[$package_id] = [
					'id' => $package_id,
					'title' => $package_title,
					'reason' => 'package_already_booked',
					'message' => "Reason: Package '{$package_title}' (includes {$space_title}) is already booked for this time."
				];
			}
		}

		return array_values($blockers);
	}
}
