<?php declare(strict_types=1);

namespace SpaceBooking\Services;

use WP_Error;

/**
 * Repository for sb_bookings table + extras.
 * Handles CRUD for bookings, time conflicts, cleanup.
 */
class BookingRepository
{
	public function find(int $id): ?array
	{
		global $wpdb;

		$booking = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_bookings WHERE id = %d",
			$id
		), ARRAY_A);

		return $booking ?: null;
	}

	public function findEnriched(int $id): ?array
	{
		$booking = $this->find($id);
		if (!$booking) {
			return null;
		}

		// Get all meta first (includes _sb_selected_item_ids)
		$meta = $this->get_all_meta($id);
		$booking['_meta_data'] = $meta;

		// Enrich with space/package info
		$space = get_post($booking['space_id']);
		$package = $booking['package_id'] ? get_post($booking['package_id']) : null;

		$booking['_space_title'] = $space->post_title ?? 'Unknown Space';
		$booking['_package_title'] = $package ? $package->post_title : null;

		// Get selected_item_ids from meta (for multi-space support)
		$selected_item_ids = [];
		if (isset($meta['_sb_selected_item_ids'])) {
			$selected_item_ids = json_decode($meta['_sb_selected_item_ids'], true) ?: [];
		}

		// Fallback to space_id if no selected_item_ids (legacy bookings)
		if (empty($selected_item_ids)) {
			$selected_item_ids = [(int) $booking['space_id']];
		}

		// Build selected_items array with full details
		$selected_items = [];
		$space_titles = [];
		foreach ($selected_item_ids as $item_id) {
			$post = get_post($item_id);
			if ($post && in_array($post->post_type, ['sb_space', 'sb_package'], true)) {
				$selected_items[] = [
					'id' => $post->ID,
					'type' => $post->post_type,
					'title' => $post->post_title,
				];
				$space_titles[] = $post->post_title;
			}
		}
		$booking['_selected_items'] = $selected_items;
		$booking['_space_titles'] = $space_titles;

		// Get extras with proper validation - only include valid extras
		$extras = $this->get_extras($id);
		$valid_extras = [];
		foreach ($extras as $extra) {
			// Only include extras with valid extra_id and quantity > 0
			if (!empty($extra['extra_id']) && !empty($extra['quantity'])) {
				$extra_post = get_post($extra['extra_id']);
				if ($extra_post && $extra_post->post_type === 'sb_extra') {
					$valid_extras[] = $extra;
				}
			}
		}
		$booking['_extras'] = $valid_extras;
		$booking['extras'] = $valid_extras;  // Frontend expects 'extras' key

		// Include extra details (only for valid extras)
		$booking['_extras_details'] = array_map(function ($e) {
			return [
				'extra_id' => $e['extra_id'],
				'extra_name' => $e['title'] ?? 'Unknown',
				'quantity' => $e['quantity'],
				'unit_price' => $e['price'] ?? 0,
			];
		}, $valid_extras);

		// DEBUG: Log data sources
		error_log(sprintf(
			'SpaceBooking DEBUG findEnriched(#%d): db_extras_col="%s", extras_table_count=%d, valid_extras_count=%d',
			$id,
			is_array($booking['extras']) ? json_encode($booking['extras']) : ($booking['extras'] ?? 'empty'),
			count($extras),
			count($valid_extras)
		));

		// Include price breakdown if available
		$price_breakdown = $this->get_meta($id, '_sb_price_breakdown');
		if ($price_breakdown) {
			$booking['_price_breakdown'] = json_decode($price_breakdown, true);
			error_log(sprintf('SpaceBooking DEBUG: _sb_price_breakdown found with %d items', count($booking['_price_breakdown'])));
		}

		// Include enriched price breakdown if available (THIS IS LIKELY THE SOURCE OF INACCURATE EXTRAS)
		$price_breakdown_enriched = $this->get_meta($id, '_sb_price_breakdown_enriched');
		if ($price_breakdown_enriched) {
			$decoded = json_decode($price_breakdown_enriched, true);
			if ($decoded) {
				$booking['_price_breakdown_enriched'] = $decoded;
				$extra_items = array_filter($decoded, function ($item) {
					return isset($item['type']) && $item['type'] === 'extra';
				});
				error_log(sprintf('SpaceBooking DEBUG: _sb_price_breakdown_enriched found with %d EXTRA items (THIS IS LIKELY WRONG SOURCE)', count($extra_items)));
				$booking['display_extras'] = $extra_items;
			}
		}

		// DEBUG: Log what's being returned
		error_log(sprintf(
			'SpaceBooking DEBUG findEnriched(#%d) RETURN: extras=%s, _extras=%s, _extras_details=%s, display_extras=%s',
			$id,
			json_encode($booking['extras'] ?? []),
			json_encode($booking['_extras'] ?? []),
			json_encode($booking['_extras_details'] ?? []),
			json_encode($booking['display_extras'] ?? [])
		));

		return $booking;
	}

	/**
	 * Save meta value for booking
	 */
	public function save_meta(int $booking_id, string $meta_key, string $meta_value): void
	{
		global $wpdb;

		$table = $wpdb->prefix . 'sb_booking_meta';

		// Delete existing
		$wpdb->delete(
			$table,
			['booking_id' => $booking_id, 'meta_key' => $meta_key],
			['%d', '%s']
		);

		// Insert new
		if (!empty($meta_value)) {
			$wpdb->insert(
				$table,
				[
					'booking_id' => $booking_id,
					'meta_key' => $meta_key,
					'meta_value' => $meta_value
				],
				['%d', '%s', '%s']
			);
		}
	}

	/**
	 * Get single meta value
	 */
	public function get_meta(int $booking_id, string $meta_key): ?string
	{
		global $wpdb;

		$value = $wpdb->get_var($wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->prefix}sb_booking_meta WHERE booking_id = %d AND meta_key = %s",
			$booking_id, $meta_key
		));

		return $value ?: null;
	}

	/**
	 * Get all meta for booking
	 */
	public function get_all_meta(int $booking_id): array
	{
		global $wpdb;

		$meta = $wpdb->get_results($wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->prefix}sb_booking_meta WHERE booking_id = %d",
			$booking_id
		), ARRAY_A) ?: [];

		$result = [];
		foreach ($meta as $item) {
			$result[$item['meta_key']] = $item['meta_value'];
		}
		return $result;
	}

	public function create(array $data): int
	{
		global $wpdb;

		$result = $wpdb->insert(
			$wpdb->prefix . 'sb_bookings',
			[
				'space_id' => $data['space_id'],
				'package_id' => $data['package_id'] ?? null,
				'customer_name' => $data['customer_name'],
				'customer_email' => $data['customer_email'],
				'customer_phone' => $data['customer_phone'] ?? '',
				'booking_date' => $data['booking_date'],
				'start_time' => $data['start_time'],
				'end_time' => $data['end_time'],
				'duration_hours' => isset($data['duration_hours']) ? (float) $data['duration_hours'] : ((strtotime($data['end_time']) - strtotime($data['start_time'])) / 3600),
				'base_price' => isset($data['base_price']) ? (float) $data['base_price'] : 0.0,
				'extras_price' => isset($data['extras_price']) ? (float) $data['extras_price'] : 0.0,
				'modifier_price' => isset($data['modifier_price']) ? (float) $data['modifier_price'] : 0.0,
				'total_price' => isset($data['total_price']) ? (float) $data['total_price'] : 0.0,
				'notes' => isset($data['notes']) ? $data['notes'] : '',
				'extras' => !empty($data['extras']) ? wp_json_encode($data['extras']) : '[]',
				'status' => 'pending',  // Initial status
				'expired_at' => date('Y-m-d H:i:s', strtotime('+30 minutes')),  // Auto-expire
			],
			['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s']
		);

		if (false === $result) {
			throw new \RuntimeException('Failed to create booking: ' . $wpdb->last_error);
		}

		$booking_id = $wpdb->insert_id;

		// Save marketing_source meta if provided
		if (isset($data['marketing_source']) && !empty($data['marketing_source'])) {
			$this->save_meta($booking_id, '_sb_marketing_source', $data['marketing_source']);
		}

		// Insert extras if present
		if (!empty($data['extras'])) {
			$this->save_extras($booking_id, $data['extras']);
		}

		return $booking_id;
	}

	public function get_confirmed_intervals(int $space_id, string $date): array
	{
		global $wpdb;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT start_time as start, end_time as end
             FROM {$wpdb->prefix}sb_bookings 
             WHERE space_id = %d AND booking_date = %s AND status IN ('confirmed', 'in_review', 'shadow')
             ORDER BY start_time",
			$space_id, $date
		), ARRAY_A) ?: [];
	}

	public function get_confirmed_intervals_for_spaces(array $space_ids, string $date): array
	{
		if (empty($space_ids)) {
			return [];
		}

		global $wpdb;

		$space_ids_placeholder = implode(',', array_fill(0, count($space_ids), '%d'));
		$space_ids_params = $space_ids;

		return $wpdb->get_results($wpdb->prepare("
			SELECT start_time as start, end_time as end
            FROM {$wpdb->prefix}sb_bookings 
            WHERE space_id IN ({$space_ids_placeholder}) AND booking_date = %s AND status IN ('confirmed', 'in_review', 'shadow')
            ORDER BY start_time",
			...array_merge($space_ids_params, [$date])), ARRAY_A) ?: [];
	}

	public function create_shadow(int $parent_id, int $space_id, string $date, string $start_time, string $end_time): int
	{
		global $wpdb;

		$result = $wpdb->insert(
			$wpdb->prefix . 'sb_bookings',
			[
				'space_id' => $space_id,
				'parent_booking_id' => $parent_id,
				'booking_date' => $date,
				'start_time' => $start_time,
				'end_time' => $end_time,
				'status' => 'shadow',
				'expired_at' => date('Y-m-d H:i:s', strtotime('+30 minutes')),  // Same TTL as parent
			],
			['%d', '%d', '%s', '%s', '%s', '%s', '%s']
		);

		if (false === $result) {
			throw new \RuntimeException('Failed to create shadow booking: ' . $wpdb->last_error);
		}

		return $wpdb->insert_id;
	}

	public function cleanup_expired(): int
	{
		global $wpdb;
		$table = $wpdb->prefix . 'sb_bookings';
		$extras_table = $wpdb->prefix . 'sb_booking_extras';

		// Delete main bookings with safe prepared query
		$result = $wpdb->query($wpdb->prepare(
			"DELETE FROM {$table} WHERE status = %s AND expired_at <= NOW()",
			'pending'
		));

		// Delete associated extras
		if ($result !== false && $result > 0) {
			$wpdb->query($wpdb->prepare(
				"DELETE be FROM {$extras_table} be
			\t JOIN {$table} b ON b.id = be.booking_id
			\t WHERE b.status = %s AND b.expired_at <= NOW()",
				'pending'
			));
		}

		return (int) $result;
	}

	public function get_extras(int $booking_id): array
	{
		global $wpdb;

		$extras = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_booking_extras WHERE booking_id = %d",
			$booking_id
		), ARRAY_A);

		foreach ($extras as &$extra) {
			$post = get_post($extra['extra_id']);
			$extra['title'] = $post ? $post->post_title : 'Unknown Extra';
			$extra['price'] = (float) get_post_meta($extra['extra_id'], '_sb_extra_price', true);
		}

		return $extras ?: [];
	}

	public function update_status(int $id, string $status): bool
	{
		global $wpdb;

		$result = $wpdb->update(
			$wpdb->prefix . 'sb_bookings',
			['status' => $status],
			['id' => $id],
			['%s'],
			['%d']
		);

		return false !== $result;
	}

	private function save_extras(int $booking_id, array $extras): void
	{
		global $wpdb;

		// FIX: Delete existing extras BEFORE inserting new ones to prevent orphaned extras
		$wpdb->delete(
			$wpdb->prefix . 'sb_booking_extras',
			['booking_id' => $booking_id],
			['%d']
		);

		foreach ($extras as $extra) {
			$wpdb->insert(
				$wpdb->prefix . 'sb_booking_extras',
				[
					'booking_id' => $booking_id,
					'extra_id' => (int) $extra['extra_id'],
					'quantity' => (int) ($extra['quantity'] ?? 1),
				],
				['%d', '%d', '%d']
			);
		}
	}
}
