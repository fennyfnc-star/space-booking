<?php
declare(strict_types=1);

namespace SpaceBooking\Services;

/**
 * Thin wrapper around the Stripe HTTP API (no SDK dependency).
 * Uses wp_remote_post / wp_remote_get for HTTP calls.
 */
final class StripeService {

	private const API_BASE = 'https://api.stripe.com/v1';

	private string $secret_key;

	public function __construct() {
		$this->secret_key = (string) get_option( 'sb_stripe_secret_key', '' );
	}

	// ── PaymentIntent ────────────────────────────────────────────────────────

	/**
	 * Create a Stripe PaymentIntent.
	 *
	 * @param  int    $amount_cents  e.g. 5000 for $50.00
	 * @param  string $currency      e.g. 'usd'
	 * @param  array  $metadata      Key-value metadata stored on the intent
	 * @return array  { id, client_secret, status }
	 * @throws \RuntimeException
	 */
	public function create_payment_intent(
		int $amount_cents,
		string $currency = '',
		array $metadata = []
	): array {
		if ( empty( $currency ) ) {
			$currency = \SpaceBooking\Services\CurrencyService::get_currency();
		}

		$body = [
			'amount'               => $amount_cents,
			'currency'             => strtolower( $currency ),
			'payment_method_types' => [ 'card' ],
			'capture_method'       => 'automatic',
			'metadata'             => $metadata,
		];

		$response = $this->request( 'POST', '/payment_intents', $body );

		return [
			'id'            => $response['id'],
			'client_secret' => $response['client_secret'],
			'status'        => $response['status'],
		];
	}

	/**
	 * Retrieve a PaymentIntent by ID.
	 */
	public function retrieve_payment_intent( string $pi_id ): array {
		return $this->request( 'GET', "/payment_intents/{$pi_id}" );
	}

	/**
	 * Issue a full refund on a PaymentIntent.
	 */
	public function refund_payment_intent( string $pi_id ): array {
		$pi = $this->retrieve_payment_intent( $pi_id );

		if ( empty( $pi['latest_charge'] ) ) {
			throw new \RuntimeException( 'No charge found to refund.' );
		}

		return $this->request( 'POST', '/refunds', [
			'charge' => $pi['latest_charge'],
		] );
	}

	// ── Webhook verification ─────────────────────────────────────────────────

	/**
	 * Verify the Stripe webhook signature and return the parsed event.
	 *
	 * @param  string $payload      Raw request body
	 * @param  string $sig_header   Value of `Stripe-Signature` header
	 * @return array  Parsed event
	 * @throws \RuntimeException   On signature failure
	 */
	public function verify_webhook( string $payload, string $sig_header ): array {
		$secret = (string) get_option( 'sb_stripe_webhook_secret', '' );

		if ( ! $secret ) {
			throw new \RuntimeException( 'Webhook secret not configured.' );
		}

		// Parse the Stripe-Signature header
		$parts = [];
		foreach ( explode( ',', $sig_header ) as $part ) {
			[ $k, $v ] = array_pad( explode( '=', $part, 2 ), 2, '' );
			$parts[ trim( $k ) ] = trim( $v );
		}

		$timestamp = (int) ( $parts['t'] ?? 0 );
		$sig       = $parts['v1'] ?? '';

		if ( ! $timestamp || ! $sig ) {
			throw new \RuntimeException( 'Invalid Stripe-Signature header.' );
		}

		// Reject events older than 5 minutes
		if ( abs( time() - $timestamp ) > 300 ) {
			throw new \RuntimeException( 'Webhook timestamp too old.' );
		}

		$signed_payload  = "{$timestamp}.{$payload}";
		$expected_sig    = hash_hmac( 'sha256', $signed_payload, $secret );

		if ( ! hash_equals( $expected_sig, $sig ) ) {
			throw new \RuntimeException( 'Webhook signature mismatch.' );
		}

		$event = json_decode( $payload, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new \RuntimeException( 'Invalid webhook JSON payload.' );
		}

		return $event;
	}

	// ── HTTP Helper ──────────────────────────────────────────────────────────

	/**
	 * @throws \RuntimeException
	 */
	private function request( string $method, string $endpoint, array $body = [] ): array {
		if ( ! $this->secret_key ) {
			throw new \RuntimeException( 'Stripe secret key not configured.' );
		}

		$args = [
			'method'  => $method,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->secret_key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Stripe-Version' => '2024-04-10',
			],
			'timeout' => 30,
		];

		if ( $method === 'POST' && ! empty( $body ) ) {
			$args['body'] = $this->flatten_params( $body );
		}

		$url      = self::API_BASE . $endpoint;
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Stripe HTTP error: ' . $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $data['error'] ) ) {
			throw new \RuntimeException(
				'Stripe API error: ' . ( $data['error']['message'] ?? 'Unknown error' )
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException( "Stripe returned HTTP {$status}" );
		}

		return $data;
	}

	/**
	 * Recursively flatten nested arrays for application/x-www-form-urlencoded.
	 * e.g. ['metadata' => ['key' => 'val']] → ['metadata[key]' => 'val']
	 */
	private function flatten_params( array $params, string $prefix = '' ): array {
		$result = [];

		foreach ( $params as $key => $value ) {
			$full_key = $prefix ? "{$prefix}[{$key}]" : $key;

			if ( is_array( $value ) ) {
				$result += $this->flatten_params( $value, $full_key );
			} else {
				$result[ $full_key ] = $value;
			}
		}

		return $result;
	}
}
