<?php declare(strict_types=1);

namespace SpaceBooking\Services;

use WP_Error;

/**
 * Repository for sb_bookings table + extras.
 * Handles CRUD for bookings, time conflicts, cleanup.
 */
class BookingRepository
{
	private const ALLOWED_STATUSES = ['pending', 'in_review', 'confirmed', 'cancelled', 'refunded', 'trashed', 'deleted'];

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
		// For backward compatibility, use primary space_id from the booking record as well as available metadata
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
		if (empty($selected_item_ids) && !empty($booking['space_id'])) {
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

		// SINGLE SOURCE: Only use _sb_price_breakdown for price breakdown
		// FIX: Removed _price_breakdown_enriched and display_extras to prevent price drift
		$price_breakdown = $this->get_meta($id, '_sb_price_breakdown');
		if ($price_breakdown) {
			$booking['_price_breakdown'] = json_decode($price_breakdown, true);
			error_log(sprintf('SpaceBooking DEBUG: _sb_price_breakdown found with %d items (SINGLE SOURCE)', count($booking['_price_breakdown'])));
		}
		$price_snapshot = $this->get_meta($id, '_sb_price_snapshot_v1');
		if ($price_snapshot) {
			$booking['_price_snapshot_v1'] = json_decode($price_snapshot, true);
		}

		// Expose package inclusions for frontend confirmation rendering.
		$package_inclusions_raw = $this->get_meta($id, '_sb_package_inclusions');
		$booking['_package_inclusions'] = [];
		if (!empty($package_inclusions_raw)) {
			$decoded_inclusions = json_decode($package_inclusions_raw, true);
			if (is_array($decoded_inclusions)) {
				$booking['_package_inclusions'] = array_values(array_filter(array_map(static function ($inc) {
					if (!is_array($inc)) {
						return null;
					}
					$type = sanitize_text_field((string) ($inc['type'] ?? ''));
					$title = sanitize_text_field((string) ($inc['title'] ?? ''));
					$label = sanitize_text_field((string) ($inc['label'] ?? ''));
					if ($type === '' || $title === '') {
						return null;
					}
					$normalized = [
						'type' => $type,
						'title' => $title,
					];
					if ($label !== '') {
						$normalized['label'] = $label;
					}
					return $normalized;
				}, $decoded_inclusions)));
			}
		}

		// DEBUG: Log what's being returned (updated - removed display_extras)
		error_log(sprintf(
			'SpaceBooking DEBUG findEnriched(#%d) RETURN: extras=%s, _extras=%s, _price_breakdown=%s, _package_inclusions=%s',
			$id,
			json_encode($booking['extras'] ?? []),
			json_encode($booking['_extras'] ?? []),
			json_encode($booking['_price_breakdown'] ?? []),
			json_encode($booking['_package_inclusions'] ?? [])
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

		// NEW SCHEMA: Handle both legacy (single ID) and new (array of IDs) formats
		// For the main booking record, use first space_id and first package_id as primary
		$space_id = null;
		$package_id = null;
		
		// Handle space_id: could be single value or array
		if (isset($data['space_id'])) {
			// Legacy format
			$space_id = $data['space_id'];
		} elseif (isset($data['space_ids']) && is_array($data['space_ids']) && !empty($data['space_ids'])) {
			// New format: get first space_id from array
			$space_id = (int) $data['space_ids'][0];
		}
		
		// Handle package_id: could be single value or array
		if (isset($data['package_id'])) {
			// Legacy format
			$package_id = $data['package_id'];
		} elseif (isset($data['package_ids']) && is_array($data['package_ids']) && !empty($data['package_ids'])) {
			// New format: get first package_id from array
			$package_id = (int) $data['package_ids'][0];
		}

		// Package-only bookings: resolve lead space from package so sb_bookings.space_id is never NULL.
		if ((empty($space_id) || (int) $space_id <= 0) && !empty($package_id)) {
			$resolved_space_id = (int) get_post_meta((int) $package_id, '_sb_package_space_id', true);
			if ($resolved_space_id > 0) {
				$space_id = $resolved_space_id;
			}
		}

		// NOTE: The 'extras' column was removed from insert because it's not in the database schema.
		// Extras are stored separately in the sb_booking_extras table via save_extras() call below.
		$result = $wpdb->insert(
			$wpdb->prefix . 'sb_bookings',
			[
				'space_id' => $space_id,
				'package_id' => $package_id,
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
				'status' => 'pending',  // Initial status
				'expired_at' => date('Y-m-d H:i:s', strtotime('+30 minutes')),  // Auto-expire
			],
			['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s']
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
             WHERE space_id = %d AND booking_date = %s AND status IN ('confirmed', 'in_review', 'paid')
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
			SELECT DISTINCT b.start_time as start, b.end_time as end
			FROM {$wpdb->prefix}sb_bookings b
			LEFT JOIN {$wpdb->prefix}sb_booking_spaces bs ON b.id = bs.booking_id
			LEFT JOIN {$wpdb->prefix}sb_booking_packages bp ON b.id = bp.booking_id
			WHERE (
				b.space_id IN ({$space_ids_placeholder})
				OR bs.space_id IN ({$space_ids_placeholder})
				OR bp.space_id IN ({$space_ids_placeholder})
			)
			AND b.booking_date = %s
			AND b.status IN ('confirmed', 'in_review', 'paid')
			ORDER BY b.start_time",
			...array_merge($space_ids_params, $space_ids_params, $space_ids_params, [$date])), ARRAY_A) ?: [];
	}

	/**
	 * Get pending (non-expired) time intervals for spaces.
	 * Used to block slots that are currently being booked by another customer.
	 *
	 * FIXED: Same logic as get_blocking_intervals - only non-expired pending bookings block.
	 */
	public function get_pending_intervals_for_spaces(array $space_ids, string $date): array
	{
		if (empty($space_ids)) {
			return [];
		}

		global $wpdb;

		$space_ids_placeholder = implode(',', array_fill(0, count($space_ids), '%d'));
		$space_ids_params = $space_ids;

		// Get pending bookings: ALSO check linked spaces in sb_booking_spaces and sb_booking_packages
		// ONLY valid & non-expired (no '0000-00-00' zombie condition!)
		$results = $wpdb->get_results($wpdb->prepare("
			SELECT DISTINCT b.start_time as start, b.end_time as end
			FROM {$wpdb->prefix}sb_bookings b
			LEFT JOIN {$wpdb->prefix}sb_booking_spaces bs ON b.id = bs.booking_id
			LEFT JOIN {$wpdb->prefix}sb_booking_packages bp ON b.id = bp.booking_id
			WHERE (
				b.space_id IN ({$space_ids_placeholder})
				OR bs.space_id IN ({$space_ids_placeholder})
				OR bp.space_id IN ({$space_ids_placeholder})
			)
			AND b.booking_date = %s 
			AND b.status = 'pending'
			AND b.expired_at > NOW()
			ORDER BY b.start_time",
			...array_merge($space_ids_params, $space_ids_params, $space_ids_params, [$date])), ARRAY_A) ?: [];

		// Normalize time strings
		foreach ($results as &$row) {
			$row['start'] = date('H:i', strtotime($row['start']));
			$row['end'] = date('H:i', strtotime($row['end']));
		}

		return $results;
	}

	/**
	 * UNIFIED: Get ALL blocking intervals for spaces.
	 * FIXED: Now also queries sb_booking_spaces table for linked spaces.
	 *
	 * When selecting multiple spaces (e.g., 223, 10, 224):
	 * space_id column (whether as lead or shadow), it's blocked for that time.
	 *
	 * When booking A+B+C:
	 * - Row 1: space_id=A (lead)
	 * - Row 2: space_id=B (shadow)
	 * - Row 3: space_id=C (shadow)
	 *
	 * Querying WHERE space_id=A finds Row 1 (no subquery needed!)
	 *
	 * STATUS BLOCKING LOGIC:
	 * - 'confirmed' - blocks (paid/confirmed bookings)
	 * - 'in_review' - blocks (awaiting admin review)
	 * - 'pending' (non-expired) - blocks (active cart holds)
	 *
	 * FIXED: Removed '0000-00-00' zombie condition - pending bookings
	 * without expiry should NOT block forever!
	 */
	public function get_blocking_intervals(array $space_ids, string $date): array
	{
		if (empty($space_ids)) {
			return [];
		}

		global $wpdb;

		$space_ids_placeholder = implode(',', array_fill(0, count($space_ids), '%d'));
		$space_ids_params = $space_ids;

		// STEP 1: Find all booking_ids where any of the requested spaces appear
		// Either in sb_bookings (direct) OR in sb_booking_spaces (linked) OR in sb_booking_packages (package bookings)
		$booking_id_query = $wpdb->prepare("
			SELECT DISTINCT b.id as booking_id
			FROM {$wpdb->prefix}sb_bookings b
			LEFT JOIN {$wpdb->prefix}sb_booking_spaces bs ON b.id = bs.booking_id
			LEFT JOIN {$wpdb->prefix}sb_booking_packages bp ON b.id = bp.booking_id
			WHERE (
				b.space_id IN ({$space_ids_placeholder})
				OR bs.space_id IN ({$space_ids_placeholder})
				OR bp.space_id IN ({$space_ids_placeholder})
			)
			AND b.booking_date = %s
			AND (
				b.status IN ('confirmed', 'in_review', 'paid')
				OR (b.status = 'pending' AND b.expired_at > NOW())
			)",
			...array_merge($space_ids_params, $space_ids_params, $space_ids_params, [$date]));

		error_log('SB_DEBUG: get_blocking_intervals booking_id query: ' . str_replace("\n", ' ', $booking_id_query));

		$booking_ids = $wpdb->get_col($booking_id_query) ?: [];

		if (empty($booking_ids)) {
			return [];
		}

		// STEP 2: Get blocking intervals for all found booking_ids
		$booking_ids_placeholder = implode(',', array_fill(0, count($booking_ids), '%d'));

		$query = $wpdb->prepare("
			SELECT start_time as start, end_time as end
			FROM {$wpdb->prefix}sb_bookings
			WHERE id IN ({$booking_ids_placeholder})
			ORDER BY start_time",
			...array_merge($booking_ids));

		error_log('SB_DEBUG: get_blocking_intervals SQL: ' . str_replace("\n", ' ', $query));
		error_log('SB_DEBUG: get_blocking_intervals params: ' . json_encode($booking_ids) . ', date: ' . $date);

		$results = $wpdb->get_results($query, ARRAY_A) ?: [];
		// FIX: Normalize time strings for consistent matching
		foreach ($results as &$row) {
			$row['start'] = date('H:i', strtotime($row['start']));
			$row['end'] = date('H:i', strtotime($row['end']));
		}

		return $results;
	}

	/**
	 * UNIFIED: Create additional booking row for multi-space booking.
	 * This replaces the old "shadow booking" concept with a simple unified model.
	 * Each space gets its own row, all linked by the same order_id for group tracking.
	 */
	public function create_booking_row(array $data): int
	{
		global $wpdb;

		$result = $wpdb->insert(
			$wpdb->prefix . 'sb_bookings',
			[
				'space_id' => $data['space_id'],
				'package_id' => $data['package_id'] ?? null,
				'order_id' => $data['order_id'] ?? null,
				'booking_date' => $data['booking_date'],
				'start_time' => $data['start_time'],
				'end_time' => $data['end_time'],
				'status' => $data['status'] ?? 'pending',
				'expired_at' => $data['expired_at'] ?? date('Y-m-d H:i:s', strtotime('+30 minutes')),
			],
			['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
		);

		if (false === $result) {
			throw new \RuntimeException('Failed to create booking row: ' . $wpdb->last_error);
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

	public function update_status(int $id, string $status, array $extra_data = []): bool
	{
		global $wpdb;

		if (!in_array($status, self::ALLOWED_STATUSES, true)) {
			return false;
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'sb_bookings',
			['status' => $status],
			['id' => $id],
			['%s'],
			['%d']
		);

		if (false === $result) {
			return false;
		}

		if (!empty($extra_data['admin_feedback'])) {
			$this->save_meta($id, 'admin_feedback', sanitize_textarea_field((string) $extra_data['admin_feedback']));
		}

		return true;
	}

	public function move_to_trash(int $booking_id, int $actor_user_id): bool
	{
		$booking = $this->find($booking_id);
		if (!$booking || $booking['status'] === 'trashed') {
			return false;
		}

		$updated = $this->update_status($booking_id, 'trashed');
		if ($updated) {
			$this->append_audit_log($booking_id, 'booking_trashed', $actor_user_id, [
				'from_status' => (string) $booking['status'],
				'to_status' => 'trashed',
			]);
		}

		return $updated;
	}

	public function restore_from_trash(int $booking_id, int $actor_user_id): bool
	{
		$booking = $this->find($booking_id);
		if (!$booking || $booking['status'] !== 'trashed') {
			return false;
		}

		$updated = $this->update_status($booking_id, 'pending');
		if ($updated) {
			$this->append_audit_log($booking_id, 'booking_restored', $actor_user_id, [
				'from_status' => 'trashed',
				'to_status' => 'pending',
			]);
		}

		return $updated;
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

	// ==================== NEW SCHEMA METHODS ====================
	// These methods support the new booking architecture without lead/shadow logic

	/**
	 * NEW: Link a space to a booking (sb_booking_spaces table).
	 * Falls back gracefully if table doesn't exist.
	 */
	public function link_space(int $booking_id, int $space_id, string $start_time, string $end_time, ?int $package_id = null): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'sb_booking_spaces';

		// Check if table exists
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
		if (!$table_exists) {
			error_log("SB WARNING: Table $table does not exist, skipping link_space for booking #$booking_id space $space_id");
			return;  // Gracefully skip instead of throwing
		}

		$result = $wpdb->insert(
			$table,
			[
				'booking_id' => $booking_id,
				'space_id' => $space_id,
				'package_id' => $package_id,
				'start_time' => $start_time,
				'end_time' => $end_time,
			],
			['%d', '%d', '%d', '%s', '%s']
		);

		if (false === $result) {
			error_log("SB WARNING: Failed to link space $space_id to booking $booking_id: " . $wpdb->last_error);
			throw new \RuntimeException('Failed to link space: ' . $wpdb->last_error);
		}
	}

	/**
	 * NEW: Link an extra to a booking with time slot (sb_booking_extras table).
	 */
	public function link_extra(int $booking_id, int $extra_id, string $start_time, string $end_time, int $quantity = 1, float $unit_price = 0.0): void
	{
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'sb_booking_extras',
			[
				'booking_id' => $booking_id,
				'extra_id' => $extra_id,
				'start_time' => $start_time,
				'end_time' => $end_time,
				'quantity' => $quantity,
				'unit_price' => $unit_price,
			],
			['%d', '%d', '%s', '%s', '%d', '%f']
		);
	}

	/**
	 * NEW: Link a package to a booking (sb_booking_packages table).
	 * Booking a package includes the space it belongs to.
	 */
	public function link_package(int $booking_id, int $package_id, int $space_id): void
	{
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'sb_booking_packages',
			[
				'booking_id' => $booking_id,
				'package_id' => $package_id,
				'space_id' => $space_id,
			],
			['%d', '%d', '%d']
		);
	}

	/**
	 * Persist the WooCommerce order reference for a booking.
	 */
	public function link_order(int $booking_id, int $order_id): void
	{
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'sb_bookings',
			['order_id' => $order_id],
			['id' => $booking_id],
			['%d'],
			['%d']
		);
	}

	/**
	 * NEW: Get all spaces linked to a booking.
	 */
	public function get_linked_spaces(int $booking_id): array
	{
		global $wpdb;

		$spaces = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_booking_spaces WHERE booking_id = %d",
			$booking_id
		), ARRAY_A) ?: [];

		foreach ($spaces as &$space) {
			$post = get_post($space['space_id']);
			$space['title'] = $post ? $post->post_title : 'Unknown Space';
		}

		return $spaces;
	}

	/**
	 * NEW: Get all packages linked to a booking.
	 */
	public function get_linked_packages(int $booking_id): array
	{
		global $wpdb;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_booking_packages WHERE booking_id = %d",
			$booking_id
		), ARRAY_A) ?: [];
	}

	/**
	 * Delete a booking and all its linked data.
	 */
	public function delete(int $booking_id): bool
	{
		global $wpdb;

		// Delete linked spaces
		$wpdb->delete(
			$wpdb->prefix . 'sb_booking_spaces',
			['booking_id' => $booking_id],
			['%d']
		);

		// Delete linked extras
		$wpdb->delete(
			$wpdb->prefix . 'sb_booking_extras',
			['booking_id' => $booking_id],
			['%d']
		);

		// Delete linked packages
		$wpdb->delete(
			$wpdb->prefix . 'sb_booking_packages',
			['booking_id' => $booking_id],
			['%d']
		);

		// Delete meta
		$wpdb->delete(
			$wpdb->prefix . 'sb_booking_meta',
			['booking_id' => $booking_id],
			['%d']
		);

		// Delete main booking
		$result = $wpdb->delete(
			$wpdb->prefix . 'sb_bookings',
			['id' => $booking_id],
			['%d']
		);

		return false !== $result;
	}

	public function delete_permanently(int $booking_id, int $actor_user_id): bool
	{
		$booking = $this->find($booking_id);
		if (!$booking) {
			return false;
		}

		$this->append_audit_log($booking_id, 'booking_delete_permanently', $actor_user_id, [
			'from_status' => (string) ($booking['status'] ?? ''),
		]);

		$deleted = $this->delete($booking_id);
		if ($deleted) {
			error_log(sprintf('SpaceBooking audit: booking #%d permanently deleted by user #%d', $booking_id, $actor_user_id));
		}

		return $deleted;
	}

	public function get_customer_bookings_by_email(string $email, int $limit = 50): array
	{
		global $wpdb;

		$email = strtolower(sanitize_email($email));
		if (!is_email($email)) {
			return [];
		}

		$limit = max(1, min(100, $limit));
		$status_allowlist = ['pending', 'in_review', 'confirmed', 'cancelled', 'refunded'];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, space_id, booking_date, start_time, end_time, total_price, status
				 FROM {$wpdb->prefix}sb_bookings
				 WHERE LOWER(customer_email) = %s
				 AND status IN ('pending','in_review','confirmed','cancelled','refunded')
				 ORDER BY booking_date DESC, start_time DESC
				 LIMIT %d",
				$email,
				$limit
			),
			ARRAY_A
		) ?: [];

		$results = [];
		foreach ($rows as $row) {
			$status = (string) ($row['status'] ?? '');
			if (!in_array($status, $status_allowlist, true)) {
				continue;
			}

			$space_id = (int) ($row['space_id'] ?? 0);
			$thumbnail = '';
			if ($space_id > 0 && has_post_thumbnail($space_id)) {
				$thumbnail = (string) get_the_post_thumbnail_url($space_id, 'medium');
			}

			$results[] = [
				'id' => (int) $row['id'],
				'space_name' => $space_id > 0 ? get_the_title($space_id) : __('Unknown Space', 'space-booking'),
				'booking_date' => sanitize_text_field((string) $row['booking_date']),
				'start_time' => sanitize_text_field((string) $row['start_time']),
				'end_time' => sanitize_text_field((string) $row['end_time']),
				'total_price' => (float) $row['total_price'],
				'status' => $status,
				'thumbnail' => esc_url_raw($thumbnail),
				'extras' => array_map(static function (array $extra): array {
					return [
						'extra_name' => sanitize_text_field((string) ($extra['title'] ?? 'Extra')),
						'quantity' => (int) ($extra['quantity'] ?? 1),
					];
				}, $this->get_extras((int) $row['id'])),
			];
		}

		return $results;
	}

	private function append_audit_log(int $booking_id, string $event, int $actor_user_id, array $context = []): void
	{
		$existing_json = $this->get_meta($booking_id, '_sb_audit_log');
		$entries = [];
		if (is_string($existing_json) && $existing_json !== '') {
			$decoded = json_decode($existing_json, true);
			if (is_array($decoded)) {
				$entries = $decoded;
			}
		}

		$entries[] = [
			'event' => sanitize_text_field($event),
			'actor_user_id' => $actor_user_id,
			'timestamp_gmt' => gmdate('Y-m-d H:i:s'),
			'context' => $context,
		];

		$this->save_meta($booking_id, '_sb_audit_log', wp_json_encode($entries));
		error_log(sprintf('SpaceBooking audit: %s booking #%d by user #%d', $event, $booking_id, $actor_user_id));
	}

	public function log_audit_event(int $booking_id, string $event, int $actor_user_id, array $context = []): void
	{
		$this->append_audit_log($booking_id, $event, $actor_user_id, $context);
	}

	public function get_audit_log(int $booking_id): array
	{
		$existing_json = $this->get_meta($booking_id, '_sb_audit_log');
		if (!is_string($existing_json) || $existing_json === '') {
			return [];
		}

		$decoded = json_decode($existing_json, true);
		if (!is_array($decoded)) {
			return [];
		}

		return array_values(array_filter($decoded, static function ($entry) {
			return is_array($entry) && !empty($entry['event']) && !empty($entry['timestamp_gmt']);
		}));
	}
}
