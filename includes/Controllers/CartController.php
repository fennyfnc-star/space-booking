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

        register_rest_route($this->namespace, '/cart/clear-booking', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'clear_cart_booking'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    public function has_cart_booking(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('WC') || !WC()) {
            return new WP_REST_Response(['hasCartBooking' => false], 200);
        }

        if (WC()->cart && WC()->cart->get_cart_contents_count() > 0) {
            return new WP_REST_Response([
                'hasCartBooking' => true,
                'bookingId' => null,
            ], 200);
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

    public function clear_cart_booking(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('WC') || !WC()) {
            return new WP_REST_Response(['cleared' => false, 'message' => 'WooCommerce not available.'], 200);
        }

        if (WC()->cart) {
            WC()->cart->empty_cart();
        }

        if (WC()->session) {
            WC()->session->set('sb_booking_id', null);
            WC()->session->set('sb_pending_booking_id', null);
        }

        $this->clear_pending_checkout_transients();

        return new WP_REST_Response(['cleared' => true], 200);
    }

    private function clear_pending_checkout_transients(): void
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return;
        }

        // Clear direct list transient if present.
        delete_transient('sb_pending_checkout_list');

        $prefix = '_transient_sb_pending_checkout_';
        $timeout_prefix = '_transient_timeout_sb_pending_checkout_';
        $like = $wpdb->esc_like($prefix) . '%';

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            )
        );

        if (!is_array($rows) || empty($rows)) {
            return;
        }

        foreach ($rows as $option_name) {
            $name = (string) $option_name;
            if (strpos($name, $timeout_prefix) === 0) {
                continue;
            }

            $transient_key = str_replace($prefix, '', $name);
            if ($transient_key === '') {
                continue;
            }
            delete_transient('sb_pending_checkout_' . $transient_key);
        }
    }
}
