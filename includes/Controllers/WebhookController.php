<?php
declare(strict_types=1);

namespace SpaceBooking\Controllers;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use SpaceBooking\Services\BookingRepository;
use SpaceBooking\Services\EmailService;
use SpaceBooking\Services\StripeService;

/**
 * POST /space-booking/v1/payment/webhook
 *
 * Verifies Stripe's signature, then transitions booking status:
 *   payment_intent.succeeded  → confirmed  → sends confirmation email
 *   payment_intent.canceled   → cancelled
 *   charge.refunded           → refunded
 */
final class WebhookController extends WP_REST_Controller {

	protected $namespace = 'space-booking/v1';

	private BookingRepository $repo;
	private StripeService     $stripe;
	private EmailService      $email;

	public function __construct() {
		$this->repo   = new BookingRepository();
		$this->stripe = new StripeService();
		$this->email  = new EmailService();
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/payment/webhook', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			],
		] );
	}

	// ── Handler ───────────────────────────────────────────────────────────────

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$payload    = $request->get_body();
		$sig_header = $request->get_header( 'stripe-signature' );

		if ( ! $sig_header ) {
			return new WP_REST_Response( [ 'message' => 'Missing Stripe-Signature header.' ], 400 );
		}

		try {
			$event = $this->stripe->verify_webhook( $payload, $sig_header );
		} catch ( \RuntimeException $e ) {
			return new WP_REST_Response( [ 'message' => $e->getMessage() ], 400 );
		}

		$event_type = $event['type']                   ?? '';
		$pi_id      = $event['data']['object']['id']   ?? '';

		// We may receive charge events; resolve PI from charge's payment_intent field
		if ( str_starts_with( $pi_id, 'ch_' ) ) {
			$pi_id = $event['data']['object']['payment_intent'] ?? '';
		}

		if ( ! $pi_id ) {
			return new WP_REST_Response( [ 'message' => 'No PaymentIntent ID in event.' ], 200 );
		}

		$booking = $this->repo->find_by_stripe_pi( $pi_id );

		if ( ! $booking ) {
			// Not our booking — acknowledge silently
			return new WP_REST_Response( [ 'received' => true ], 200 );
		}

		switch ( $event_type ) {
			case 'payment_intent.succeeded':
				$this->repo->update_status( (int) $booking['id'], 'confirmed' );
				$booking['status'] = 'confirmed';
				$this->email->send_confirmation( $booking );
				break;

			case 'payment_intent.payment_failed':
			case 'payment_intent.canceled':
				$this->repo->update_status( (int) $booking['id'], 'cancelled' );
				break;

			case 'charge.refunded':
				$this->repo->update_status( (int) $booking['id'], 'refunded' );
				break;
		}

		return new WP_REST_Response( [ 'received' => true ], 200 );
	}
}
