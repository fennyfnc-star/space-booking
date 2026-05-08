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
						'required' => true,
						'type' => 'array',
						'sanitize_callback' => function ($input) {
							return array_map('absint', (array) $input);
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
		$date = $request->get_param('date');
		$step_mins = (int) get_option('sb_slot_interval_minutes', 60);

		// Validate all spaces exist
		foreach ((array) $space_ids_raw as $space_id) {
			$post = get_post($space_id);
			if (!$post || $post->post_type !== 'sb_space') {
				return new WP_REST_Response([
					'message' => "Space #$space_id not found."
				], 404);
			}
		}

		$space_ids = array_map('intval', (array) $space_ids_raw);
		error_log('AVAIL CONTROLLER MULTI: space_ids=' . json_encode($space_ids) . ", date=$date");

		// NEW: Use intersection method for multi-space
		$result = $this->availability->get_intersection_slots($space_ids, $date, $step_mins);

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

	// ── Validators ───────────────────────────────────────────────────────────

	public function validate_date($value): bool
	{
		$d = \DateTime::createFromFormat('Y-m-d', $value);
		return $d && $d->format('Y-m-d') === $value;
	}
}
