<?php declare(strict_types=1);

namespace SpaceBooking\Controllers;

use SpaceBooking\Services\AvailabilityService;
use SpaceBooking\Services\BookingRepository;
use SpaceBooking\Services\InventoryService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /space-booking/v1/availability/multi → slots for multiple spaces+date
 * GET /space-booking/v1/extras         → available extras for space+date+time
 */
final class AvailabilityController extends WP_REST_Controller
{
	protected $namespace = 'space-booking/v1';

	private AvailabilityService $availability;
	private InventoryService $inventory;

	public function __construct()
	{
		$repo = new BookingRepository();
		$this->availability = new AvailabilityService($repo);
		$this->inventory = new InventoryService();
	}

	public function register_routes(): void
	{
		// Multi-space availability with intersection check
		register_rest_route($this->namespace, '/availability/multi', [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'get_multi_availability'],
				'permission_callback' => '__return_true',
				'args' => [
					'space_ids' => [
						'required' => false,  // FIX: Allow package-only (no explicit spaces)
						'type' => 'array',
						'default' => [],
						'sanitize_callback' => function ($input) {
							return array_map('absint', (array) ($input ?: []));
						},
					],
					'package_ids' => [
						'required' => false,
						'type' => 'array',
						'default' => [],
						'sanitize_callback' => function ($input) {
							return array_map('absint', (array) ($input ?: []));
						},
					],
					'date' => [
						'required' => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => [$this, 'validate_date'],
					],
				],
			],
		]);

		// Extras availability for a specific time window
		register_rest_route($this->namespace, '/extras', [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'get_extras'],
				'permission_callback' => '__return_true',
				'args' => [
					'space_id' => ['required' => true, 'sanitize_callback' => 'absint'],
					'date' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
					'start_time' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
					'end_time' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
				],
			],
		]);

		// All extras list (no filters - for package card display)
		register_rest_route($this->namespace, '/extras/all', [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'get_all_extras'],
				'permission_callback' => '__return_true',
			],
		]);

		// No pricing route here (handled by PricingController)
	}

	// Removed duplicate /pricing route

	// ── Handlers ─────────────────────────────────────────────────────────────

	/**
	 * NEW: Multi-space availability with INTERSECTION check.
	 * Returns only slots available in ALL selected spaces.
	 */
	public function get_multi_availability(WP_REST_Request $request): WP_REST_Response
	{
		$space_ids_raw = $request->get_param('space_ids');
		$package_ids_raw = $request->get_param('package_ids');
		$date = $request->get_param('date');
		$step_mins = (int) get_option('sb_slot_interval_minutes', 60);

		// DEBUG: Log raw input
		error_log('AVAIL CONTROLLER: RAW space_ids=' . json_encode($space_ids_raw) . ', package_ids=' . json_encode($package_ids_raw));

		// Handle both array and scalar inputs
		// Handle both array and single value inputs
		$space_ids = [];
		if ($space_ids_raw) {
			$space_ids = is_array($space_ids_raw) ? array_map('intval', $space_ids_raw) : [intval($space_ids_raw)];
		}
		
		$package_ids = [];
		if ($package_ids_raw) {
			$package_ids = is_array($package_ids_raw) ? array_map('intval', $package_ids_raw) : [intval($package_ids_raw)];
		}

		error_log('AVAIL CONTROLLER: FINAL space_ids=' . json_encode($space_ids) . ', package_ids=' . json_encode($package_ids) . ", date=$date");

		// Validate spaces only if we have space_ids (not for package-only)
		foreach ($space_ids as $space_id) {
			$post = get_post($space_id);
			if (!$post || $post->post_type !== 'sb_space') {
				return new WP_REST_Response([
					'message' => "Space #$space_id not found."
				], 404);
			}
		}

		error_log('AVAIL CONTROLLER MULTI: space_ids=' . json_encode($space_ids) . ', package_ids=' . json_encode($package_ids) . ", date=$date");

		// NEW: Use intersection method for multi-space with package_ids support
		$result = $this->availability->get_intersection_slots($space_ids, $date, $step_mins, $package_ids);

		$slots = $result['slots'];
		$blockers = $result['blockers'];
		$is_intersection = $result['is_intersection'];

		// Determine has_fixed_slots from primary space
		$primary_id = $space_ids[0];
		$has_fixed_slots = $this->availability->has_fixed_slots_defined($primary_id);

		// Compute open/close times
		[$open, $close] = $this->availability->resolve_effective_hours($primary_id, $date);
		if ($has_fixed_slots && !empty($slots)) {
			$open = min(array_map(fn($s) => $s['start'], $slots));
			$close = max(array_map(fn($s) => $s['end'], $slots));
		}

		// Build blocker message
		$message = null;
		if (!empty($blockers)) {
			$blocker_names = array_column($blockers, 'title');
			if (count($blockers) === 1) {
				$message = 'There is no available time slot for the selected spaces. Reason: ' . $blockers[0]['title'] . ' is currently booked.';
			} else {
				$message = 'There is no available time slot for the selected spaces. Reason: ' . implode(' and ', $blocker_names) . ' are currently booked.';
			}
			error_log('AVAIL CONTROLLER MULTI: Blocker message: ' . $message);
		} else if ($is_intersection && empty($slots)) {
			$message = 'No common time slots available for all selected spaces. Please choose different spaces or a different date.';
		}

		return rest_ensure_response([
			'date' => $date,
			'space_ids' => $space_ids,
			'is_multi' => true,
			'is_intersection' => $is_intersection,
			'open_time' => $open,
			'close_time' => $close,
			'slots' => $slots,
			'has_fixed_slots' => $has_fixed_slots,
			'is_fixed_slots' => !empty($slots) && isset($slots[0]['slot_id']),
			'blockers' => $blockers,
			'message' => $message,
		]);
	}

	public function get_extras(WP_REST_Request $request): WP_REST_Response
	{
		$space_id = $request->get_param('space_id');
		$date = $request->get_param('date');
		$start_time = $request->get_param('start_time');
		$end_time = $request->get_param('end_time');

		error_log("SB_DEBUG_EXTRAS: Fetching extras for Space ID: $space_id, Date: $date, Time: $start_time-$end_time");

		$extras = $this->inventory->get_available_extras(
			$space_id, $date, $start_time, $end_time
		);

		error_log('SB_DEBUG_EXTRAS: Found ' . count($extras) . " extras for space $space_id");
		foreach ($extras as $extra) {
			error_log('SB_DEBUG_EXTRAS: Extra ID ' . $extra['id'] . ' available: ' . ($extra['is_available'] ? 'yes' : 'no') . ' qty: ' . $extra['available_qty']);
		}

		return rest_ensure_response($extras);
	}

	/**
	 * Get all extras (no filters) - for package card display
	 */
	public function get_all_extras(WP_REST_Request $request): WP_REST_Response
	{
		$extras = get_posts([
			'post_type' => 'sb_extra',
			'post_status' => 'publish',
			'posts_per_page' => -1,
		]);

		$result = array_map(function($post) {
			return [
				'id' => $post->ID,
				'title' => $post->post_title,
				'description' => $post->post_content,
				'price' => (float) get_post_meta($post->ID, '_sb_extra_price', true),
				'available_qty' => (int) get_post_meta($post->ID, '_sb_extra_qty', true),
			];
		}, $extras);

		return rest_ensure_response($result);
	}

	// ── Validators ───────────────────────────────────────────────────────────

	public function validate_date($value): bool
	{
		$d = \DateTime::createFromFormat('Y-m-d', $value);
		return $d && $d->format('Y-m-d') === $value;
	}
}
