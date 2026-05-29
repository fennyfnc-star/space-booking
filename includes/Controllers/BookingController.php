<?php declare(strict_types=1);

namespace SpaceBooking\Controllers;

use SpaceBooking\Services\BookingRepository;
use SpaceBooking\Services\RecaptchaService;
use SpaceBooking\Services\BookingSpamGuard;
use SpaceBooking\Services\InventoryService;
use SpaceBooking\Services\PricingService;
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
	private BookingSpamGuard $spam_guard;
	private RecaptchaService $recaptcha;
	private InventoryService $inventory;
	private PricingService $pricing;
	private \SpaceBooking\Services\WooCommerceService $wc;

	public function __construct()
	{
		$this->repo = new BookingRepository();
		$this->spam_guard = new BookingSpamGuard();
		$this->recaptcha = new RecaptchaService();
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
		// NEW SCHEMA: Use arrays instead of singular IDs
		$space_ids = array_values(array_filter(array_map('absint', (array) $request->get_param('space_ids'))));
		$package_ids = array_values(array_filter(array_map('absint', (array) $request->get_param('package_ids'))));
		$date = (string) $request->get_param('date');
		$start_time = (string) $request->get_param('start_time');
		$end_time = (string) $request->get_param('end_time');
		$extras_input = (array) ($request->get_param('extras') ?? []);
		$extras = array_values(array_filter(array_map(static function ($extra): ?array {
			if (!is_array($extra)) {
				return null;
			}
			$extra_id = absint($extra['extra_id'] ?? 0);
			$quantity = max(1, absint($extra['quantity'] ?? 1));
			if ($extra_id <= 0) {
				return null;
			}
			return [
				'extra_id' => $extra_id,
				'quantity' => $quantity,
			];
		}, $extras_input)));

		// DEBUG: Trace received extras
		error_log('SB_DEBUG BookingController: Received extras from request: ' . json_encode($extras));
		error_log('SB_DEBUG BookingController: Raw request params: ' . json_encode($request->get_body_params()));
		$name = sanitize_text_field($request->get_param('customer_name') ?? '');
		$email = sanitize_email($request->get_param('customer_email') ?? '');
		$phone = sanitize_text_field($request->get_param('customer_phone') ?? '');
		$notes = sanitize_textarea_field($request->get_param('notes') ?? '');
		$marketing_source = sanitize_text_field($request->get_param('marketing_source') ?? '');
		$frontend_breakdown = $request->get_param('price_breakdown') ?: [];
		$selected_item_ids = array_values(array_filter(array_map('absint', (array) $request->get_param('selected_item_ids'))));
		$honeypot = sanitize_text_field((string) ($request->get_param('website_url') ?? ''));
		$form_started_at = (int) $request->get_param('form_started_at');
		$recaptcha_token = sanitize_text_field((string) ($request->get_param('recaptcha_token') ?? ''));
		$client_nonce = $request->get_header('X-WP-Nonce');
		$request_ip = $this->spam_guard->get_request_ip();

		if (!$this->spam_guard->validate_nonce($client_nonce)) {
			$this->spam_guard->log_suspicious_attempt('invalid_nonce', [
				'email' => $email,
				'path' => '/bookings',
			]);
			return new WP_REST_Response(['message' => 'Invalid submission token. Refresh and try again.'], 403);
		}

		if ($this->spam_guard->is_honeypot_triggered($honeypot)) {
			$this->spam_guard->log_suspicious_attempt('honeypot_triggered', [
				'email' => $email,
				'honeypot' => $honeypot,
			]);
			return new WP_REST_Response(['message' => 'Submission rejected.'], 422);
		}

		if ($this->spam_guard->is_submit_too_fast($form_started_at)) {
			$this->spam_guard->log_suspicious_attempt('submit_too_fast', [
				'email' => $email,
				'form_started_at' => $form_started_at,
			]);
			return new WP_REST_Response(['message' => 'Please wait a moment and submit again.'], 429);
		}

		if ($this->spam_guard->is_rate_limited($request_ip, $email)) {
			$this->spam_guard->log_suspicious_attempt('rate_limited', [
				'email' => $email,
			]);
			return new WP_REST_Response(['message' => 'Too many attempts. Please try again later.'], 429);
		}

		$this->spam_guard->increment_rate_counters($request_ip, $email);

		if ($name === '' || !is_email($email)) {
			$this->spam_guard->log_suspicious_attempt('invalid_customer_fields', [
				'email' => $email,
				'name_empty' => $name === '',
			]);
			return new WP_REST_Response(['message' => 'Valid customer name and email are required.'], 422);
		}

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			return new WP_REST_Response(['message' => 'Invalid booking date format.'], 422);
		}
		if (!preg_match('/^\d{2}:\d{2}$/', $start_time) || !preg_match('/^\d{2}:\d{2}$/', $end_time)) {
			return new WP_REST_Response(['message' => 'Invalid booking time format.'], 422);
		}
		if ($end_time <= $start_time) {
			return new WP_REST_Response(['message' => 'End time must be later than start time.'], 422);
		}

		if (empty($selected_item_ids)) {
			return new WP_REST_Response(['message' => 'Missing selected_item_ids.'], 422);
		}

		// NEW SCHEMA: Validate that either space_ids OR package_ids is provided
		if (empty($space_ids) && empty($package_ids)) {
			return new WP_REST_Response(['message' => 'Either space_ids or package_ids must be provided.'], 422);
		}

		if ($this->spam_guard->has_recent_duplicate($email, $date, $start_time, $end_time)) {
			$this->spam_guard->log_suspicious_attempt('duplicate_booking_window', [
				'email' => $email,
				'date' => $date,
				'start_time' => $start_time,
				'end_time' => $end_time,
			]);
			return new WP_REST_Response(['message' => 'Duplicate booking detected. Please wait before submitting again.'], 409);
		}

		$recaptcha_config = $this->recaptcha->get_config();
		if (empty($recaptcha_config['has_keys'])) {
			$this->spam_guard->log_suspicious_attempt('recaptcha_keys_missing', ['email' => $email]);
			return new WP_REST_Response(['message' => 'Booking protection is not configured. Please contact site admin.'], 503);
		}

		$captcha_verification = $this->recaptcha->verify_token($recaptcha_token, $request_ip, 'space_booking_submit');
		if (empty($captcha_verification['success'])) {
			$this->spam_guard->log_suspicious_attempt('recaptcha_verify_failed', [
				'email' => $email,
				'reason' => $captcha_verification['message'] ?? 'unknown',
			]);
			return new WP_REST_Response(['message' => 'Captcha verification failed. Please try again.'], 422);
		}

		if ($this->spam_guard->has_recent_duplicate($email, $date, $start_time, $end_time)) {
			$this->spam_guard->log_suspicious_attempt('duplicate_after_captcha', [
				'email' => $email,
				'date' => $date,
				'start_time' => $start_time,
				'end_time' => $end_time,
			]);
			return new WP_REST_Response(['message' => 'Duplicate booking detected after verification. Please retry later.'], 409);
		}

		// Build data for booking creation
		$data = [
			'space_ids' => $space_ids,
			'package_ids' => $package_ids,
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

		// NEW SCHEMA: Validate all space IDs exist and are published
		foreach ($space_ids as $sid) {
			$post = get_post($sid);
			if (!$post || $post->post_type !== 'sb_space' || $post->post_status !== 'publish') {
				return new WP_REST_Response(['message' => 'Invalid space ID: ' . $sid], 422);
			}
		}

		// NEW SCHEMA: Validate all package IDs exist and are published
		foreach ($package_ids as $pid) {
			$post = get_post($pid);
			if (!$post || $post->post_type !== 'sb_package' || $post->post_status !== 'publish') {
				return new WP_REST_Response(['message' => 'Invalid package ID: ' . $pid], 422);
			}
		}

		// ── Guard: time window still available (check ALL selected spaces) ──
		// FIXED: Use selected_item_ids directly instead of get_conflict_groups()
		// CONSOLIDATED: Use single get_blocking_intervals() call instead of dual confirmed + pending
		$footprint_spaces = $selected_item_ids;
		$blocking = $this->repo->get_blocking_intervals($footprint_spaces, $date);
		foreach ($blocking as $b) {
			if ($start_time < $b['end'] && $end_time > $b['start']) {
				return new WP_REST_Response(['message' => 'Selected time is no longer available for one or more spaces.'], 409);
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
		// NEW SCHEMA: Pass arrays to pricing service
		$price = $this->pricing->calculate(
			null, // deprecated first param (was $lead_space_id)
			$date, $start_time, $end_time, $extras, $selected_item_ids, $package_ids, null
		);

		// DEBUG: Log pricing details
		error_log(sprintf(
			'SpaceBooking DEBUG: Booking price calc - space_ids:%s package_ids:%s - base:%.2f extras:%.2f modifier:%.2f total:%.2f',
			json_encode($space_ids),
			json_encode($package_ids),
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
		// FIX: Use 1% percentage tolerance instead of fixed 0.01
		$tolerance = max(0.01, $calculated_total * 0.01);
		if (abs($frontend_sum - $calculated_total) > $tolerance) {
			error_log(sprintf(
				'SpaceBooking: Frontend breakdown mismatch: frontend=%.2f vs backend=%.2f, tolerance=%.2f',
				$frontend_sum, $calculated_total, $tolerance
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
			'SpaceBooking DEBUG: Saving booking with total_price=%.2f (base=%.2f extras=%.2f)',
			$data['total_price'],
			$data['base_price'],
			$data['extras_price']
		));

		try {
			$booking_id = $this->repo->create($data);
			// Save selected_item_ids for WC multi-item
			$this->repo->save_meta($booking_id, '_sb_selected_item_ids', wp_json_encode($selected_item_ids));
			// Persist immutable booking snapshot + breakdown in booking meta.
			$this->repo->save_meta($booking_id, '_sb_price_breakdown', wp_json_encode($price['breakdown']));
			if ($frontend_breakdown && $price['breakdown']) {
				$this->repo->save_meta($booking_id, '_sb_price_breakdown_enriched', wp_json_encode($frontend_breakdown));
			}
			$snapshot_payload = [
				'version' => 1,
				'booking_id' => $booking_id,
				'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : get_option('woocommerce_currency', 'USD'),
				'base_price' => (float) $price['base_price'],
				'extras_price' => (float) $price['extras_price'],
				'modifier_price' => (float) ($price['modifier_price'] ?? 0),
				'total' => (float) $price['total_price'],
				'date' => $date,
				'start_time' => $start_time,
				'end_time' => $end_time,
				'line_items' => [],
				'captured_at_gmt' => gmdate('c'),
			];

			foreach (($price['items'] ?? []) as $item) {
				$subtotal = (float) ($item['subtotal'] ?? 0);
				if ($subtotal <= 0) {
					continue;
				}
				$snapshot_payload['line_items'][] = [
					'type' => (string) ($item['type'] ?? 'item'),
					'reference_id' => (int) ($item['id'] ?? 0),
					'label' => sanitize_text_field((string) ($item['title'] ?? 'Item')),
					'quantity' => 1,
					'unit_price' => $subtotal,
					'line_total' => $subtotal,
				];
			}
			foreach (($price['extras_breakdown'] ?? []) as $extra_item) {
				$amount = (float) ($extra_item['amount'] ?? 0);
				if ($amount <= 0) {
					continue;
				}
				$snapshot_payload['line_items'][] = [
					'type' => 'extra',
					'reference_id' => 0,
					'label' => sanitize_text_field((string) ($extra_item['label'] ?? 'Extra')),
					'quantity' => 1,
					'unit_price' => $amount,
					'line_total' => $amount,
				];
			}
			$this->repo->save_meta($booking_id, '_sb_price_snapshot_v1', wp_json_encode($snapshot_payload));
			$this->repo->save_meta($booking_id, '_sb_checkout_model', 'reusable_product_v1');
			// Save package inclusions for email display (spaces and extras included in package)
			$inclusions = [];
			if (!empty($price['items'])) {
				foreach ($price['items'] as $item) {
					// Check if this item has $0 breakdown entries (package inclusions)
					if (!empty($item['breakdown'])) {
						foreach ($item['breakdown'] as $bd) {
							if (isset($bd['amount']) && $bd['amount'] == 0 && strpos($bd['label'] ?? '', '(Package Inclusion)') !== false) {
								// Extract actual item name from label (remove " (Package Inclusion)" suffix)
								$included_title = str_replace(' (Package Inclusion)', '', $bd['label']);
								$inclusions[] = [
									'type' => $item['type'],
									'title' => $included_title,
									'label' => $bd['label']
								];
							}
						}
					}
				}
			}
			if (!empty($inclusions)) {
				$this->repo->save_meta($booking_id, '_sb_package_inclusions', wp_json_encode($inclusions));
				error_log('SpaceBooking: Saved ' . count($inclusions) . ' package inclusions for booking #' . $booking_id);
			}
		} catch (\RuntimeException $e) {
			return new WP_REST_Response(['message' => 'Could not save booking.'], 500);
		}

		// NEW SCHEMA: Link all spaces using link_space (iterate over array)
		foreach ($space_ids as $sid) {
			try {
				$this->repo->link_space($booking_id, $sid, $start_time, $end_time);
			} catch (\RuntimeException $e) {
				error_log('Failed to link space for booking #' . $booking_id . ' space ' . $sid . ': ' . $e->getMessage());
			}
		}

		// NEW SCHEMA: Link all packages using link_package (iterate over array)
		foreach ($package_ids as $pkg_id) {
			try {
				// Get the actual space_id associated with this package
			$pkg_space_id = (int) get_post_meta($pkg_id, '_sb_package_space_id', true);
			$this->repo->link_package($booking_id, $pkg_id, $pkg_space_id);
			} catch (\RuntimeException $e) {
				error_log('Failed to link package for booking #' . $booking_id . ' package ' . $pkg_id . ': ' . $e->getMessage());
			}
		}

		// ── Add to WooCommerce cart or session ──────────────────────────────
		$checkout_url = wc_get_cart_url();
		$cart_added = false;
		$wc_error = null;

		try {
			$checkout_url = $this->wc->add_booking_to_cart([
				'space_ids' => $space_ids,
				'package_ids' => $package_ids,
				'selected_item_ids' => $selected_item_ids,
				'date' => $date,
				'start_time' => $start_time,
				'end_time' => $end_time,
				'duration_hours' => $price['duration_hours'],
				'base_price' => $price['base_price'],
				'extras_price' => $price['extras_price'],
				'modifier_price' => $price['modifier_price'] ?? 0,
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
						'space_ids' => $space_ids,
						'package_ids' => $package_ids,
						'selected_item_ids' => $selected_item_ids,
						'date' => $date,
						'start_time' => $start_time,
						'end_time' => $end_time,
						'base_price' => $price['base_price'],
						'extras_price' => $price['extras_price'],
						'modifier_price' => $price['modifier_price'] ?? 0,
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
			// NEW SCHEMA: Use arrays instead of singular IDs
			'space_ids' => [
				'required' => false,
				'type' => 'array',
				'sanitize_callback' => function ($input) {
					return array_map('absint', (array) $input);
				}
			],
			'package_ids' => [
				'required' => false,
				'type' => 'array',
				'sanitize_callback' => function ($input) {
					return array_map('absint', (array) $input);
				}
			],
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
			'website_url' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
			'form_started_at' => ['required' => false, 'sanitize_callback' => 'absint'],
			'recaptcha_token' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
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
