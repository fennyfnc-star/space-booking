<?php declare(strict_types=1);

namespace SpaceBooking\Controllers;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /space-booking/v1/cart/has-booking → Check if cart has space booking item.
 */
final class CartController extends WP_REST_Controller
{
    protected $namespace = 'space-booking/v1';
    protected $rest_base = 'cart/has-booking';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'has_cart_booking'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    public function has_cart_booking(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('WC') || !WC()) {
            return new WP_REST_Response(['hasCartBooking' => false], 200);
        }

        // FIX: Check session first (reliable), then fallback to cart items
        $wc_service = new \SpaceBooking\Services\WooCommerceService();
        $booking_id = $wc_service->get_booking_id_from_session();

        if ($booking_id) {
            return new WP_REST_Response([
                'hasCartBooking' => true,
                'bookingId' => $booking_id
            ], 200);
        }

        return new WP_REST_Response(['hasCartBooking' => false], 200);
    }
}