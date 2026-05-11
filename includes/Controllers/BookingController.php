<?php declare(strict_types=1);

namespace SpaceBooking\Controllers;

use SpaceBooking\Services\BookingRepository;
use SpaceBooking\Services\InventoryService;
use SpaceBooking\Services\PricingService;
use SpaceBooking\Services\StripeService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /space-booking/v1/bookings   → Create pending booking + PaymentIntent
 */
final class BookingController extends WP_REST_Controller
{
	protected $namespace = 'space-booking/v1';
	protected $rest_base = 'bookings';

	private BookingRepository $repo;
	private InventoryService $inventory;
	private PricingService $pricing;
	private \SpaceBooking\Services\WooCommerceService $wc;

	public function __construct()
	{
		$this->repo = new BookingRepository();
		$this->inventory = new InventoryService();
		$this->pricing = new PricingService();
		$this->wc = new \SpaceBooking\Services\WooCommerceService();
	}

	public function register_routes(): void
	{
		register_rest_route($this->namespace, '/' . $this->rest_base, [
			[
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => [$this, 'create_booking'],
				'permission_callback' => '__return_true',
				'args' => $this->get_create_args(),
			],
		]);

		register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'get_booking'],
				'permission_callback' => '__return_true',
			],
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'get_booking_status'],
				'permission_callback' => '__return_true',
			],
		]);
	}

	// ── Get Booking Status ────────────────────────────────────────────────────

	public function get_booking_status(WP_REST_Request $request): WP_REST_Response
	{
		$id = (int) $request->get_param('id');
		$booking = $this->repo->find($id);

		if (!$booking) {
			return new WP_REST_Response(['message' => 'Booking not found.'], 404);
		}

		return rest_ensure_response([
			'id' => $booking['id'],
			'status' => $booking['status'],
			'booking' => $booking
		]);
	}

	// ── Create Booking ────────────────────────────────────────────────────────

	public function create_booking(WP_REST_Request $request): WP_REST_Response
	{
		$space_id = (int) $request->get_param('space_id');
		$package_id = $request->get_param('package_id') ? (int) $request->get_param('package_id') : null;
		$date = (string) $request->get_param('date');
		$start_time = (string) $request->get_param('start_time');
		$end_time = (string) $request->get_param('end_time');
		$extras = (array) ($request->get_param('extras') ?? []);

		// DEBUG: Trace received extras
		error_log('SB_DEBUG BookingController: Received extras from request: ' . json_encode($extras));
		error_log('SB_DEBUG BookingController: Raw request params: ' . json_encode($request->get_body_params()));
		$name = sanitize_text_field($request->get_param('customer_name') ?? '');
		$email = sanitize_email($request->get_param('customer_email') ?? '');
		$phone = sanitize_text_field($request->get_param('customer_phone') ?? '');
		$notes = sanitize_textarea_field($request->get_param('notes') ?? '');
		$marketing_source = sanitize_text_field($request->get_param('marketing_source') ?? '');
		$frontend_breakdown = $request->get_param('price_breakdown') ?: [];
		$selected_item_ids = array_map('intval', (array) $request->get_param('selected_item_ids'));
		if (empty($selected_item_ids)) {
			return new WP_REST_Response(['message' => 'Missing selected_item_ids.'], 422);
		}
		$lead_space_id = $space_id;

		$data = [
			'space_id' => $space_id,
			'package_id' => $package_id,
			'booking_date' => $date,
			'start_time' => $start_time,
			'end_time' => $end_time,
			'customer_name' => $name,
			'customer_email' => $email,
			'customer_phone' => $phone,
			'notes' => $notes,
			'marketing_source' => $marketing_source,
			'extras' => $extras,
		];

		// ── Guard: space or package exists ───────────────────────────────────────────────
		if ($package_id) {
			// Package selected - verify package exists
			$post = get_post($package_id);
			if (!$post || $post->post_type !== 'sb_package' || $post->post_status !== 'publish') {
				return new WP_REST_Response(['message' => 'Invalid package.'], 422);
			}
		} else {
			// Space only - verify space exists
			$post = get_post($space_id);
			if (!$post || $post->post_type !== 'sb_space' || $post->post_status !== 'publish') {
				return new WP_REST_Response(['message' => 'Invalid space.'], 422);
			}
		}

		// ── Guard: time window still available (check ALL selected spaces) ──
		// FIXED: Use selected_item_ids directly instead of get_conflict_groups()
		// This ensures we check availability for ALL selected spaces, not just those with resource dependencies.
		$footprint_spaces = $selected_item_ids;
		$booked = $this->repo->get_confirmed_intervals_for_spaces($footprint_spaces, $date);
		foreach ($booked as $b) {
			if ($start_time < $b['end'] && $end_time > $b['start']) {
				return new WP_REST_Response(['message' => 'Selected time is no longer available for one or more spaces.'], 409);
			}
		}

		// Guard: Also check PENDING bookings to prevent double booking
		$pending = $this->repo->get_pending_intervals_for_spaces($footprint_spaces, $date);
		error_log('SB_DEBUG: BookingController pending check count: ' . count($pending));
		foreach ($pending as $p) {
			if ($start_time < $p['end'] && $end_time > $p['start']) {
				return new WP_REST_Response(['message' => 'This time slot is currently being booked by another customer. Please choose a different time.'], 409);
			}
		}

		// ── Guard: extras inventory ───────────────────────────────────────────
		if (!empty($extras)) {
			$inv_check = $this->inventory->validate_extras($extras, $date, $start_time, $end_time);
			if (!$inv_check['valid']) {
				return new WP_REST_Response([
					'message' => 'Some extras are no longer available.',
					'conflicts' => $inv_check['conflicts'],
				], 409);
			}
		}

		// ── Calculate price USING ALL selected_item_ids ──────────────────────
		$price = $this->pricing->calculate(
			$lead_space_id, $date, $start_time, $end_time, $extras, $selected_item_ids, $package_id, null
		);

		// DEBUG: Log pricing details
		error_log(sprintf(
			'SpaceBooking DEBUG: Booking#%d price calc - base:%.2f extras:%.2f modifier:%.2f total:%.2f',
			$lead_space_id,
			$price['base_price'],
			$price['extras_price'],
			$price['modifier_price'] ?? 0,
			$price['total_price']
		));
		foreach ($price['breakdown'] as $idx => $item) {
			error_log(sprintf(
				'SpaceBooking DEBUG: breakdown[%d] label="%s" amount=%.2f type="%s"',
				$idx,
				$item['label'],
				$item['amount'],
				$item['context']['type'] ?? 'unknown'
			));
		}

		// ── Validate frontend breakdown (before create) ─────────────────────
		$frontend_sum = 0.0;
		foreach ($frontend_breakdown as $item) {
			$frontend_sum += (float) ($item['amount'] ?? 0);
		}
		$calculated_total = $price['total_price'];
		if (abs($frontend_sum - $calculated_total) > 0.01) {
			error_log(sprintf(
				'SpaceBooking: Frontend breakdown mismatch: frontend=%.2f vs backend=%.2f',
				$frontend_sum, $calculated_total
			));
			$frontend_breakdown = [];  // Fallback to raw
		}

		// ── Persist pending booking + selected_items meta ────────────────────
		// IMPORTANT: Include calculated prices in the data
		$data['base_price'] = $price['base_price'];
		$data['extras_price'] = $price['extras_price'];
		$data['modifier_price'] = $price['modifier_price'] ?? 0;
		$data['total_price'] = $price['total_price'];
		$data['duration_hours'] = $price['duration_hours'];

		error_log(sprintf(
			'SpaceBooking DEBUG: Saving booking#%d with total_price=%.2f (base=%.2f extras=%.2f)',
			$lead_space_id,
			$data['total_price'],
			$data['base_price'],
			$data['extras_price']
		));

		try {
			$booking_id = $this->repo->create($data);
			// Save selected_item_ids for WC multi-item
			$this->repo->save_meta($booking_id, '_sb_selected_item_ids', wp_json_encode($selected_item_ids));
			// Backend breakdown
			update_post_meta($booking_id, '_sb_price_breakdown', wp_json_encode($price['breakdown']));
			if ($frontend_breakdown && $price['breakdown']) {
				update_post_meta($booking_id, '_sb_price_breakdown_enriched', $frontend_breakdown);
			}
		} catch (\RuntimeException $e) {
			return new WP_REST_Response(['message' => 'Could not save booking.'], 500);
		}

		// ── NEW SCHEMA: Link additional spaces using link_space ────────
		// Instead of creating shadow rows, we link spaces to the booking
		// using the sb_booking_spaces table. Each space gets its own row
		// with the same booking_id for tracking.

		$other_spaces = array_values(array_diff($selected_item_ids, [$lead_space_id]));
		foreach ($other_spaces as $sid) {
			try {
				// Use new link_space method instead of create_booking_row
				$this->repo->link_space($booking_id, $sid, $start_time, $end_time, $package_id);
			} catch (\RuntimeException $e) {
				error_log('Failed to link space for booking #' . $booking_id . ' space ' . $sid . ': ' . $e->getMessage());
			}
		}

		// If booking a package, link it using the new link_package method
		if ($package_id) {
			try {
				$this->repo->link_package($booking_id, $package_id, $lead_space_id);
			} catch (\RuntimeException $e) {
				error_log('Failed to link package for booking #' . $booking_id . ' package ' . $package_id . ': ' . $e->getMessage());
			}
		}

		// ── Add to WooCommerce cart or session ──────────────────────────────
		$checkout_url = wc_get_cart_url();
		$cart_added = false;
		$wc_error = null;

		try {
			$checkout_url = $this->wc->add_booking_to_cart([
				'space_id' => $space_id,
				'package_id' => $package_id,
				'selected_item_ids' => $selected_item_ids,
				'date' => $date,
				'start_time' => $start_time,
				'end_time' => $end_time,
				'duration_hours' => $price['duration_hours'],
				'customer_name' => $name,
				'customer_email' => $email,
				'extras' => $extras,
				'items' => $price['items'] ?? [],
				'extras_breakdown' => $price['extras_breakdown'] ?? [],
				'breakdown' => $price['breakdown'],
			], $price['total_price'], $booking_id);

			$cart_added = true;
			error_log('SpaceBooking: Multi-item booking #' . $booking_id . ' (' . count($selected_item_ids) . ' items) added to cart');
		} catch (\RuntimeException $e) {
			$wc_error = $e->getMessage();
			error_log('SpaceBooking: WooCommerce cart add failed for #' . $booking_id . ': ' . $wc_error);
		} catch (\Exception $e) {
			$wc_error = $e->getMessage();
			error_log('SpaceBooking: ERROR - WooCommerce exception for #' . $booking_id . ': ' . $wc_error);
		}

		// If WooCommerce failed, store in transient as fallback (only if no error)
		if (!$cart_added) {
			try {
				$pending_data = [
					'booking_data' => [
						'space_id' => $space_id,
						'package_id' => $package_id,
						'selected_item_ids' => $selected_item_ids,
						'date' => $date,
						'start_time' => $start_time,
						'end_time' => $end_time,
						'customer_name' => $name,
						'customer_email' => $email,
						'extras' => $extras,
						'items' => $price['items'] ?? [],
						'extras_breakdown' => $price['extras_breakdown'] ?? [],
						'breakdown' => $price['breakdown'],
					],
					'total_price' => $price['total_price'],
				];
				set_transient('sb_pending_checkout_' . $booking_id, $pending_data, 1800);
				error_log('SpaceBooking: Multi-item #' . $booking_id . ' stored in transient (fallback)');
			} catch (\Exception $e) {
				error_log('SpaceBooking: CRITICAL - Failed to store transient for #' . $booking_id . ': ' . $e->getMessage());
			}
		}

		// SAFE: Get checkout URL with defensive error handling
		// This was the source of the JSON parse error - wc_get_checkout_url() can return HTML
		// if WooCommerce is in an inconsistent state
		try {
			// Only call wc_get_checkout_url if WC is properly initialized
			if (function_exists('WC') && WC() && method_exists(WC(), 'get_checkout')) {
				$checkout_url = wc_get_checkout_url();
			} else {
				$checkout_url = home_url('/checkout/');
				error_log('SpaceBooking: WC not fully initialized, using fallback checkout URL');
			}
		} catch (\Exception $e) {
			// Fallback to a known-safe checkout URL
			$checkout_url = home_url('/checkout/');
			error_log('SpaceBooking: wc_get_checkout_url() failed with: ' . $e->getMessage() . ', using fallback');
		}

		// DEFENSIVE: Validate $checkout_url is actually a string and not HTML
		if (!is_string($checkout_url) || !filter_var($checkout_url, FILTER_VALIDATE_URL) || strlen($checkout_url) < 10) {
			error_log('SpaceBooking: CRITICAL - checkout_url is invalid: ' . (is_string($checkout_url) ? substr($checkout_url, 0, 100) : gettype($checkout_url)));
			$checkout_url = home_url('/checkout/');
		}

		// DEFENSIVE: Check if the URL looks like HTML (another symptom of the bug)
		if (is_string($checkout_url) && preg_match('/<[^>]*>/', $checkout_url)) {
			error_log('SpaceBooking: CRITICAL - checkout_url contains HTML, resetting to fallback');
			$checkout_url = home_url('/checkout/');
		}

		error_log('SpaceBooking: Booking #' . $booking_id . ' → ' . $checkout_url . ' (direct: ' . ($cart_added ? 'yes' : 'transient') . ')');

		// ALWAYS return valid JSON response - even on WooCommerce errors
		$response_data = [
			'booking_id' => $booking_id,
			'checkout_url' => $checkout_url,
			'price' => $price,
			'cart_added_directly' => $cart_added,
		];

		// Include error info if WooCommerce had issues (but don't fail the request)
		if ($wc_error) {
			$response_data['wc_warning'] = $wc_error;
			error_log('SpaceBooking: Returning response with WC warning: ' . $wc_error);
		}

		return new WP_REST_Response($response_data, 201);
	}

	// ── Get single booking ────────────────────────────────────────────────────

	public function get_booking(WP_REST_Request $request): WP_REST_Response
	{
		$id = (int) $request->get_param('id');
		$booking = $this->repo->findEnriched($id);

		if (!$booking) {
			return new WP_REST_Response(['message' => 'Booking not found.'], 404);
		}

		return rest_ensure_response($booking);
	}

	// ── Arg schema ───────────────────────────────────────────────────────────

	private function get_create_args(): array
	{
		return [
			'space_id' => ['required' => true, 'sanitize_callback' => 'absint'],
			'package_id' => ['required' => false, 'sanitize_callback' => 'absint'],
			'date' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
			'start_time' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
			'end_time' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
			'customer_name' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
			'customer_email' => [
				'required' => false,
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => static fn($v) => empty($v) || is_email($v),
			],
			'customer_phone' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
			'marketing_source' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
			'notes' => ['required' => false, 'sanitize_callback' => 'sanitize_textarea_field'],
			'extras' => ['required' => false, 'default' => []],
			'price_breakdown' => ['required' => false, 'default' => []],
			'selected_item_ids' => [
				'required' => true,
				'type' => 'array',
				'sanitize_callback' => function ($input) {
					return array_map('absint', (array) $input);
				}
			],
		];
	}
}
