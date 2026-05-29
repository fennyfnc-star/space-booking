<?php declare(strict_types=1);

namespace SpaceBooking\Controllers;

use SpaceBooking\Services\BookingRepository;
use SpaceBooking\Services\BookingSpamGuard;
use SpaceBooking\Services\CustomerFieldsService;
use SpaceBooking\Services\EmailService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CustomerController extends WP_REST_Controller
{
	private const LOOKUP_RATE_WINDOW_SECONDS = 600;
	private const LOOKUP_RATE_MAX_PER_IP = 6;
	private const LOOKUP_RATE_MAX_PER_EMAIL = 4;
	private const LOOKUP_TOKEN_TTL_DEFAULT_MINUTES = 30;
	private const LOOKUP_TOKEN_TTL_MIN_MINUTES = 10;
	private const LOOKUP_TOKEN_TTL_MAX_MINUTES = 30;

	protected $namespace = 'space-booking/v1';
	protected $rest_base = 'customer/fields';
	private BookingRepository $repo;
	private BookingSpamGuard $spam_guard;
	private EmailService $email_service;

	public function __construct()
	{
		$this->repo = new BookingRepository();
		$this->spam_guard = new BookingSpamGuard();
		$this->email_service = new EmailService();
	}

	public function register_routes(): void
	{
		register_rest_route($this->namespace, '/' . $this->rest_base, [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'get_customer_fields'],
				'permission_callback' => '__return_true',
			],
		]);

		register_rest_route($this->namespace, '/customer/lookup', [
			[
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => [$this, 'request_lookup_link'],
				'permission_callback' => '__return_true',
			],
		]);

		register_rest_route($this->namespace, '/customer/bookings', [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'get_customer_bookings'],
				'permission_callback' => '__return_true',
			],
		]);
	}

	public function get_customer_fields(WP_REST_Request $request): WP_REST_Response
	{
		$service = new CustomerFieldsService();
		$fields = $service->get_fields();

		return rest_ensure_response([
			'fields' => $fields
		]);
	}

	public function request_lookup_link(WP_REST_Request $request): WP_REST_Response
	{
		$email = sanitize_email((string) $request->get_param('email'));
		$client_nonce = $request->get_header('X-WP-Nonce');
		$request_ip = $this->spam_guard->get_request_ip();
		$generic_message = __('If an account exists for that email, a secure link has been sent.', 'space-booking');

		if (!$this->spam_guard->validate_nonce($client_nonce)) {
			$this->log_lookup_event('lookup_request_invalid_nonce', ['email' => $email]);
			return new WP_REST_Response(['message' => __('Invalid submission token. Refresh and try again.', 'space-booking')], 403);
		}

		if (!is_email($email)) {
			return new WP_REST_Response(['message' => __('Please enter a valid email address.', 'space-booking')], 422);
		}

		if ($this->is_lookup_rate_limited($request_ip, $email)) {
			$this->log_lookup_event('lookup_rate_limited', ['email' => $email]);
			return new WP_REST_Response(['message' => __('Too many requests. Please try again later.', 'space-booking')], 429);
		}
		$this->increment_lookup_rate_counters($request_ip, $email);
		$this->log_lookup_event('lookup_requested', ['email' => $email]);

		$bookings = $this->repo->get_customer_bookings_by_email($email, 1);
		if (!empty($bookings)) {
			$token = $this->create_lookup_token($email);
			$this->email_service->send_magic_link($email, $token);
			$this->log_lookup_event('lookup_link_sent', ['email' => $email]);
		}

		return new WP_REST_Response(['message' => $generic_message], 200);
	}

	public function get_customer_bookings(WP_REST_Request $request): WP_REST_Response
	{
		$token = sanitize_text_field((string) $request->get_param('token'));
		if ($token === '') {
			return new WP_REST_Response(['message' => __('Lookup token is required.', 'space-booking')], 422);
		}

		$email = $this->consume_lookup_token($token);
		if ($email === null) {
			$this->log_lookup_event('lookup_token_invalid_or_expired');
			return new WP_REST_Response(['message' => __('This booking link is invalid or expired. Request a new link.', 'space-booking')], 403);
		}

		$bookings = $this->repo->get_customer_bookings_by_email($email);
		$this->log_lookup_event('lookup_access_granted', [
			'email' => $email,
			'booking_count' => count($bookings),
		]);

		return new WP_REST_Response([
			'email' => $email,
			'bookings' => $bookings,
		], 200);
	}

	private function create_lookup_token(string $email): string
	{
		$token = bin2hex(random_bytes(32));
		$ttl_minutes = (int) get_option('sb_lookup_token_ttl_minutes', self::LOOKUP_TOKEN_TTL_DEFAULT_MINUTES);
		$ttl_minutes = max(self::LOOKUP_TOKEN_TTL_MIN_MINUTES, min(self::LOOKUP_TOKEN_TTL_MAX_MINUTES, $ttl_minutes));
		$ttl_seconds = $ttl_minutes * 60;

		$payload = [
			'email' => strtolower($email),
			'issued_at' => time(),
		];

		set_transient($this->lookup_token_key($token), $payload, $ttl_seconds);
		return $token;
	}

	private function consume_lookup_token(string $token): ?string
	{
		$key = $this->lookup_token_key($token);
		$payload = get_transient($key);
		delete_transient($key);

		if (!is_array($payload) || empty($payload['email'])) {
			return null;
		}

		return sanitize_email((string) $payload['email']) ?: null;
	}

	private function is_lookup_rate_limited(string $ip, string $email): bool
	{
		$ip_count = (int) get_transient($this->lookup_ip_key($ip));
		$email_count = (int) get_transient($this->lookup_email_key($email));
		return $ip_count >= self::LOOKUP_RATE_MAX_PER_IP || $email_count >= self::LOOKUP_RATE_MAX_PER_EMAIL;
	}

	private function increment_lookup_rate_counters(string $ip, string $email): void
	{
		$this->bump_counter($this->lookup_ip_key($ip));
		$this->bump_counter($this->lookup_email_key($email));
	}

	private function bump_counter(string $key): void
	{
		$count = (int) get_transient($key);
		set_transient($key, $count + 1, self::LOOKUP_RATE_WINDOW_SECONDS);
	}

	private function lookup_token_key(string $token): string
	{
		return 'sb_lookup_token_' . hash('sha256', $token);
	}

	private function lookup_ip_key(string $ip): string
	{
		return 'sb_lookup_rate_ip_' . md5($ip);
	}

	private function lookup_email_key(string $email): string
	{
		return 'sb_lookup_rate_email_' . md5(strtolower($email));
	}

	private function log_lookup_event(string $event, array $context = []): void
	{
		$payload = [
			'timestamp_gmt' => gmdate('Y-m-d H:i:s'),
			'event' => sanitize_text_field($event),
			'ip' => $this->spam_guard->get_request_ip(),
			'context' => $context,
		];
		error_log('SpaceBooking lookup audit: ' . wp_json_encode($payload));
	}
}
?>
