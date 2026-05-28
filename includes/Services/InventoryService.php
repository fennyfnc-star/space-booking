<?php declare(strict_types=1);

namespace SpaceBooking\Services;

final class InventoryService
{
	private function time_to_minutes(string $time): int
	{
		[$h, $m] = explode(':', $time);
		return (int) $h * 60 + (int) $m;
	}

	private function is_override_blocked(int $extra_id, int $space_id, string $date, string $start_time, string $end_time): bool
	{
		$overrides = get_post_meta($extra_id, '_sb_space_avail_overrides', true) ?: [];
		if (!is_array($overrides))
			return false;

		$day_of_week = (int) (new \DateTime($date))->format('w');  // 0=Sun..6=Sat

		foreach ($overrides as $ov) {
			if ((int) ($ov['space_id'] ?? 0) !== $space_id)
				continue;
			if (!in_array($day_of_week, $ov['days'] ?? [], true))
				continue;

			if (!empty($ov['closed'])) {
				return true;  // Closed override
			}

			// Time window restriction
			$ov_start = $this->time_to_minutes($ov['start_time'] ?? '');
			$ov_end = $this->time_to_minutes($ov['end_time'] ?? '');
			if (!$ov_start || !$ov_end)
				continue;

			$request_start = $this->time_to_minutes($start_time);
			$request_end = $this->time_to_minutes($end_time);

			// AVAILABILITY window: block if request does NOT fully contain/overlap window? No:
			// Block if OUTSIDE window (not overlapping)
			if ($ov_start && $ov_end && ($request_end <= $ov_start || $request_start >= $ov_end)) {
				return true;  // Outside availability window
			}
		}
		return false;
	}

	public function get_available_extras(int $space_id, string $date, string $start_time, string $end_time): array
	{
		$args = [
			'post_type' => 'sb_extra',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		];

		$extras = get_posts($args);
		$result = [];

		foreach ($extras as $extra) {
			$allowed_spaces = get_post_meta($extra->ID, '_sb_allowed_spaces', true);
			if (is_array($allowed_spaces) && !empty($allowed_spaces)) {
				if (!in_array($space_id, array_map('intval', $allowed_spaces), true)) {
					continue;
				}
			}

			// Check space/day/time overrides FIRST
			if ($this->is_override_blocked($extra->ID, $space_id, $date, $start_time, $end_time)) {
				$result[] = [
					'id' => $extra->ID,
					'title' => $extra->post_title,
					'description' => $extra->post_excerpt ?: wp_trim_words($extra->post_content, 20),
					'price' => (float) get_post_meta($extra->ID, '_sb_extra_price', true),
					'inventory' => (int) get_post_meta($extra->ID, '_sb_inventory', true),
					'booked_qty' => 0,
					'available_qty' => 0,
					'is_available' => false,
					'unavailable_reason' => 'space_override',
					'thumbnail' => get_the_post_thumbnail_url($extra->ID, 'thumbnail') ?: null,
				];
				continue;
			}

			$inventory = max(1, (int) get_post_meta($extra->ID, '_sb_inventory', true));
			$booked_qty = $this->get_booked_quantity($extra->ID, $date, $start_time, $end_time);
			$available_qty = max(0, $inventory - $booked_qty);

			// Get package ownership
			$pkg_ids = get_post_meta($extra->ID, '_sb_package_ids', true);
			if (!is_array($pkg_ids)) {
				$pkg_ids = [];
			}

			$result[] = [
				'id' => $extra->ID,
				'title' => $extra->post_title,
				'description' => $extra->post_excerpt ?: wp_trim_words($extra->post_content, 20),
				'price' => (float) get_post_meta($extra->ID, '_sb_extra_price', true),
				'inventory' => $inventory,
				'booked_qty' => $booked_qty,
				'available_qty' => $available_qty,
				'is_available' => $available_qty > 0,
				'unavailable_reason' => $available_qty === 0 ? 'sold_out' : null,
				'thumbnail' => get_the_post_thumbnail_url($extra->ID, 'thumbnail') ?: null,
				'package_ids' => array_map('intval', $pkg_ids),
			];
		}

		return $result;
	}

	public function get_booked_quantity(int $extra_id, string $date, string $start_time, string $end_time): int
	{
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COALESCE(SUM(be.quantity), 0) 
			FROM {$wpdb->prefix}sb_booking_extras be
			JOIN {$wpdb->prefix}sb_bookings b ON b.id = be.booking_id 
			WHERE be.extra_id = %d AND b.booking_date = %s 
			AND b.status IN ('pending', 'confirmed') 
			AND b.start_time < %s AND b.end_time > %s",
			$extra_id, $date, $end_time, $start_time
		));
	}

	public function validate_extras(array $extras, string $date, string $start_time, string $end_time, int $exclude_booking_id = 0): array
	{
		$conflicts = [];

		foreach ($extras as $item) {
			$extra_id = (int) $item['extra_id'];
			$quantity = max(1, (int) ($item['quantity'] ?? 1));

			$inventory = max(1, (int) get_post_meta($extra_id, '_sb_inventory', true));
			$booked_qty = $this->get_booked_quantity_excluding($extra_id, $date, $start_time, $end_time, $exclude_booking_id);

			if (($booked_qty + $quantity) > $inventory) {
				$conflicts[] = get_the_title($extra_id) ?: "Extra #{$extra_id}";
			}
		}

		return [
			'valid' => empty($conflicts),
			'conflicts' => $conflicts,
		];
	}

	private function get_booked_quantity_excluding(int $extra_id, string $date, string $start_time, string $end_time, int $exclude_booking_id): int
	{
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COALESCE(SUM(be.quantity), 0) 
			FROM {$wpdb->prefix}sb_booking_extras be
			JOIN {$wpdb->prefix}sb_bookings b ON b.id = be.booking_id 
			WHERE be.extra_id = %d AND b.booking_date = %s AND b.id != %d 
			AND b.status IN ('pending', 'confirmed') 
			AND b.start_time < %s AND b.end_time > %s",
			$extra_id, $date, $exclude_booking_id, $end_time, $start_time
		));
	}
}
