<?php declare(strict_types=1);

namespace SpaceBooking\Integrations;

use SpaceBooking\Services\BookingRepository;
use SpaceBooking\Services\WooCommerceService;

/**
 * WooCommerce hooks for booking fulfillment.
 */
final class WooCommerceIntegration
{
    public static function init(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Use template_redirect for cart/checkout page load after session ready
        add_action('template_redirect', [self::class, 'populate_pending_cart'], 20);
        add_action('woocommerce_checkout_order_processed', [self::class, 'save_order_meta'], 10, 3);
        add_action('woocommerce_payment_complete', [self::class, 'confirm_booking'], 10, 1);
        add_action('woocommerce_order_status_pending_to_processing', [self::class, 'confirm_booking'], 10, 1);
        add_action('woocommerce_order_status_processing', [self::class, 'confirm_booking'], 10, 1);
        add_action('woocommerce_order_status_on-hold', [self::class, 'confirm_booking'], 10, 1);
        add_action('woocommerce_order_status_completed', [self::class, 'confirm_booking'], 10, 1);
        // Late redirect check after all payment hooks
        add_action('wp_footer', [self::class, 'late_redirect_check'], 9999);

        // Line item meta backup
        \SpaceBooking\Services\WooCommerceService::register_hooks();

        // GUARANTEED order meta save after checkout processing
        add_action('woocommerce_checkout_update_order_meta', [self::class, 'save_booking_meta_guaranteed'], 10, 1);

        // Admin display for enriched breakdown
        add_action('woocommerce_admin_order_data_after_billing_address', [self::class, 'display_booking_breakdown']);

        // Custom order action - DISABLED to avoid duplicate with WooCommerceOrderActions.php
        // add_filter('woocommerce_order_actions', [self::class, 'add_confirmation_action'], 10, 2);
        // add_action('woocommerce_order_action_send_sb_confirmation_email', [self::class, 'handle_confirmation_action'], 10, 1);
    }

    // public static function add_confirmation_action($actions, $order)
    // {
    //     if (!$order instanceof \WC_Order) {
    //         return $actions;
    //     }

    //     $booking_id = $order->get_meta('_sb_booking_id');
    //     if (!$booking_id) {
    //         return $actions;
    //     }

    //     $actions['send_sb_confirmation_email'] = 'Send Booking Confirmation Email';
    //     return $actions;
    // }

    public static function handle_confirmation_action($order)
    {
        if (!$order instanceof \WC_Order) {
            return;
        }

        $booking_id = $order->get_meta('_sb_booking_id');
        if (!$booking_id) {
            $order->add_order_note('ERROR: No booking ID found.', 1);
            return;
        }

        $email_service = new \SpaceBooking\Services\EmailService();
        $email_service->send_confirmation_email($order->get_id());
    }

    /**
     * Display enriched price breakdown in WooCommerce order admin view
     */
    public static function display_booking_breakdown($order): void
    {
        $enriched_breakdown = $order->get_meta('_sb_price_breakdown_enriched');
        if (!$enriched_breakdown || !is_array($enriched_breakdown)) {
            return;
        }

        echo '<div class="sb-order-breakdown">';
        echo '<h4 style="margin-bottom: 10px;">🧾 Space Booking Price Breakdown</h4>';
        echo '<table class="wp-list-table widefat fixed striped" style="margin-bottom: 10px;">';
        echo '<thead><tr><th>Item</th><th style="text-align: right;">Amount</th></tr></thead>';
        echo '<tbody>';
        $grand_total = 0.0;
        foreach ($enriched_breakdown as $item) {
            $amount = (float) ($item['amount'] ?? 0);
            $grand_total += $amount;
            $formatted_amount = wc_price($amount);
            echo '<tr>';
            echo '<td>' . esc_html($item['label'] ?? 'Unknown') . '</td>';
            echo '<td style="text-align: right;">' . $formatted_amount . '</td>';
            echo '</tr>';
        }
        echo '<tr style="font-weight: bold; border-top: 2px solid #999;">';
        echo '<td>Total</td>';
        echo '<td style="text-align: right;">' . wc_price($grand_total) . '</td>';
        echo '</tr>';
        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * Guaranteed booking ID save after order is fully saved
     */
    public static function save_booking_meta_guaranteed($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_sb_booking_id')) {
            return;
        }

        // Check line items (same logic as save_order_meta)
        foreach ($order->get_items() as $item) {
            $booking_id = $item->get_meta('sb_booking_id', true);
            if ($booking_id) {
                $order->update_meta_data('_sb_booking_id', $booking_id);
                $order->save();
                error_log('SpaceBooking WC: GUARANTEED save booking_id ' . $booking_id . ' to order #' . $order_id);
                return;
            }
        }
    }

    /**
     * Mirror booking contact data into WooCommerce order data once.
     * This keeps the order usable for customer support without repeating the same
     * values on every line item.
     */
    private static function sync_booking_contact_to_order(\WC_Order $order, int $booking_id, BookingRepository $repo): void
    {
        $booking = $repo->find($booking_id);
        if (!$booking) {
            return;
        }

        $changed = false;
        $booking_name = trim((string) ($booking['customer_name'] ?? ''));
        $booking_email = trim((string) ($booking['customer_email'] ?? ''));
        $booking_phone = trim((string) ($booking['customer_phone'] ?? ''));
        $booking_notes = trim((string) ($booking['notes'] ?? ''));
        $marketing_source = trim((string) $repo->get_meta($booking_id, '_sb_marketing_source'));

        if ($booking_name !== '') {
            [$first_name, $last_name] = self::split_customer_name($booking_name);
            if ($first_name !== '' && $order->get_billing_first_name() !== $first_name) {
                $order->set_billing_first_name($first_name);
                $changed = true;
            }
            if ($last_name !== '' && $order->get_billing_last_name() !== $last_name) {
                $order->set_billing_last_name($last_name);
                $changed = true;
            }
        }

        if ($booking_email !== '' && $order->get_billing_email() !== $booking_email) {
            $order->set_billing_email($booking_email);
            $changed = true;
        }

        if ($booking_phone !== '' && $order->get_billing_phone() !== $booking_phone) {
            $order->set_billing_phone($booking_phone);
            $changed = true;
        }

        $contact_payload = [
            'booking_id' => $booking_id,
            'name' => $booking_name,
            'email' => $booking_email,
            'phone' => $booking_phone,
            'notes' => $booking_notes,
            'marketing_source' => $marketing_source,
        ];

        $order->update_meta_data('_sb_booking_contact', wp_json_encode($contact_payload));

        if ($booking_notes !== '') {
            $order->update_meta_data('_sb_booking_notes', $booking_notes);
            if ($order->get_customer_note() !== $booking_notes) {
                $order->set_customer_note($booking_notes);
                $changed = true;
            }
        }

        if ($marketing_source !== '') {
            $order->update_meta_data('_sb_marketing_source', $marketing_source);
        }

        $order->save();
    }

    /**
     * Split a full customer name into first and last parts.
     */
    private static function split_customer_name(string $full_name): array
    {
        $full_name = trim(preg_replace('/\s+/', ' ', $full_name) ?? '');
        if ($full_name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $full_name) ?: [];
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $first_name = array_shift($parts);
        $last_name = implode(' ', $parts);

        return [$first_name ?: '', $last_name ?: ''];
    }

    /**
     * Helper to get booking ID from order meta or line items
     */
    private static function get_booking_id_from_order($order): ?int
    {
        $booking_id = $order->get_meta('_sb_booking_id');
        if (!$booking_id) {
            foreach ($order->get_items() as $item) {
                $booking_id = $item->get_meta('sb_booking_id', true);
                if ($booking_id) {
                    break;
                }
            }
        }
        return $booking_id ? (int) $booking_id : null;
    }

    /**
     * Late check for confirmed booking on order-received pages
     */
    public static function late_redirect_check(): void
    {
        if (!is_wc_endpoint_url('order-received')) {
            return;
        }

        global $wp_query;
        $order_id = get_query_var('order-received');
        if (!$order_id || !is_numeric($order_id)) {
            return;
        }

        $order = wc_get_order((int) $order_id);
        if (!$order) {
            return;
        }

        $booking_id = self::get_booking_id_from_order($order);
        if (!$booking_id) {
            error_log('SpaceBooking WC late_redirect: No booking_id found in order #' . $order_id);
            return;
        }

        $repo = new \SpaceBooking\Services\BookingRepository();
        $booking = $repo->find((int) $booking_id);
        if ($booking && $booking['status'] === 'in_review') {
            $confirmation_url = home_url('/space-booking/?booking_id=' . $booking_id . '&status=in_review');
            error_log('SpaceBooking WC late_redirect: Redirecting order #' . $order_id . ' → ' . $confirmation_url);
            wp_redirect($confirmation_url);
            exit;
        }
    }

    public static function populate_pending_cart(): void
    {
        error_log('SpaceBooking: Template Redirect Heartbeat - URL: ' . $_SERVER['REQUEST_URI']);

        if (!is_cart() && !is_checkout() && !is_account_page()) {
            return;
        }

        if (null === WC()->session) {
            return;
        }

        error_log('SpaceBooking WC populate_pending_cart triggered on ' . (is_cart() ? 'cart' : 'checkout'));
        // Check for pending via booking ID link (fixes guest session mismatch)
        // Try session first
        $pending_id = WC()->session->get('sb_pending_booking_id');

        if (!$pending_id) {
            // Fallback: scan recent transients
            error_log('SpaceBooking WC no session ID, scanning transients...');
            $transients = get_transient('sb_pending_checkout_list');
            if (!$transients) {
                $all_transients = [];
                global $wpdb;
                $results = $wpdb->get_results("
                    SELECT option_name, option_value 
                    FROM {$wpdb->options} 
                    WHERE option_name LIKE '_transient_sb_pending_checkout_%' 
                    AND option_value != ''
                    ORDER BY option_id DESC 
                    LIMIT 5
                ");
                foreach ($results as $row) {
                    $id = str_replace('_transient_sb_pending_checkout_', '', $row->option_name);
                    if (is_numeric($id)) {
                        $all_transients[] = (int) $id;
                    }
                }
                $transients = $all_transients;
            }

            foreach ($transients as $possible_id) {
                $pending = get_transient('sb_pending_checkout_' . $possible_id);
                if ($pending) {
                    $pending_id = $possible_id;
                    error_log('SpaceBooking WC found pending transient fallback ID: ' . $pending_id);
                    break;
                }
            }
        }

        if (!$pending_id) {
            error_log('SpaceBooking WC no pending booking found (session or transient)');
            return;
        }

        $pending = get_transient('sb_pending_checkout_' . $pending_id);
        if (!$pending) {
            error_log('SpaceBooking WC no pending data in transient for #' . $pending_id);
            WC()->session?->set('sb_pending_booking_id', null);
            return;
        }

        error_log('SpaceBooking WC populating pending booking #' . $pending_id . ' from transient');

        $wc = new \SpaceBooking\Services\WooCommerceService();
        try {
            $wc->add_booking_to_cart(
                $pending['booking_data'],
                $pending['total_price'],
                $pending_id
            );
            error_log('SpaceBooking WC populate success for #' . $pending_id);
        } catch (Exception $e) {
            error_log('SpaceBooking WC populate failed for #' . $pending_id . ': ' . $e->getMessage());
        }

        // Clear transient and session
        delete_transient('sb_pending_checkout_' . $pending_id);
        WC()->session->set('sb_pending_booking_id', null);
        error_log('SpaceBooking WC pending cleared (transient + session)');
    }

    /**
     * Save booking ID to order meta during checkout.
     */
    public static function save_order_meta($order_id, $posted_data, $order)
    {
        error_log('SpaceBooking WC: save_order_meta called for order #' . $order_id);

        // Direct check session/transient for recent booking
        $session_booking = WC()->session ? WC()->session->get('sb_pending_booking_id') : null;
        if ($session_booking) {
            $order->update_meta_data('_sb_booking_id', $session_booking);
            $order->save();
            error_log('SpaceBooking WC: Saved session booking_id ' . $session_booking . ' to order #' . $order_id);
            $repo = new BookingRepository();
            $repo->link_order((int) $session_booking, (int) $order_id);
            self::sync_booking_contact_to_order($order, (int) $session_booking, $repo);
            WC()->session->set('sb_pending_booking_id', null);
            return;
        }

        // Fallback existing meta
        $existing_booking_id = $order->get_meta('_sb_booking_id');
        if ($existing_booking_id) {
            error_log('SpaceBooking WC: Booking ID already in order meta: ' . $existing_booking_id);
            $repo = new BookingRepository();
            $repo->link_order((int) $existing_booking_id, (int) $order_id);
            self::sync_booking_contact_to_order($order, (int) $existing_booking_id, $repo);
            return;
        }

        $enriched_breakdown = null;

        // Loop ALL line items (including fees, shipping, etc.)
        foreach ($order->get_items() as $item) {
            $booking_id = $item->get_meta('sb_booking_id', true);
            if ($booking_id) {
                $order->update_meta_data('_sb_booking_id', $booking_id);
                $order->save();
                error_log('SpaceBooking WC: Saved from line item booking_id ' . $booking_id . ' to order #' . $order_id);

                $enriched = $item->get_meta('sb_price_breakdown_enriched', true);
                if ($enriched) {
                    $enriched_breakdown = $enriched;
                }
                $repo = new BookingRepository();
                $repo->link_order((int) $booking_id, (int) $order_id);
                self::sync_booking_contact_to_order($order, (int) $booking_id, $repo);
                break;
            }
        }

        if ($enriched_breakdown) {
            $order->update_meta_data('_sb_price_breakdown_enriched', $enriched_breakdown);
            $order->save();
            error_log('SpaceBooking WC: Saved enriched breakdown to order #' . $order_id);
        }

        // Fallback: check order TAXABLE line item meta directly
        $order_items = $order->get_items('line_item');
        foreach ($order_items as $item_id => $item) {
            $item_meta = $item->get_meta_data();
            foreach ($item_meta as $meta) {
                if ($meta->key === 'sb_booking_id') {
                    $booking_id = $meta->value;
                    $order->update_meta_data('_sb_booking_id', $booking_id);
                    $order->save();
                    error_log('SpaceBooking WC: Saved from TAXABLE line item meta booking_id ' . $booking_id . ' to order #' . $order_id);
                    $repo = new BookingRepository();
                    $repo->link_order((int) $booking_id, (int) $order_id);
                    self::sync_booking_contact_to_order($order, (int) $booking_id, $repo);
                    return;
                }
            }
        }

        error_log('SpaceBooking WC: No booking_id found in order #' . $order_id);
    }

    /**
     * Handle WooCommerce "Thank You" page - redirect to confirmation if booking confirmed.
     */
    public static function handle_thankyou_redirect($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order)
            return;

        $booking_id = self::get_booking_id_from_order($order);
        if (!$booking_id)
            return;

        $repo = new \SpaceBooking\Services\BookingRepository();
        $booking = $repo->find((int) $booking_id);
        if ($booking && $booking['status'] === 'in_review') {
            $confirmation_url = home_url('/space-booking/?booking_id=' . $booking_id . '&status=in_review');
            wp_redirect($confirmation_url);
            exit;
        }
    }

    /**
     * Confirm booking when order is paid/processing.
     */
    public static function confirm_booking($order_id)
    {
        error_log('SpaceBooking WC: confirm_booking called for order #' . $order_id);

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('SpaceBooking WC: Order not found #' . $order_id);
            return;
        }

        $booking_id = self::get_booking_id_from_order($order);
        error_log('SpaceBooking WC: Order #' . $order_id . ' booking_id: ' . ($booking_id ?: 'MISSING'));

        if (!$booking_id) {
            error_log('SpaceBooking WC: No booking_id found on order #' . $order_id);
            return;
        }

        $repo = new \SpaceBooking\Services\BookingRepository();
        $booking = $repo->find((int) $booking_id);
        if (!$booking) {
            error_log('SpaceBooking WC: Booking #' . $booking_id . ' not found');
            return;
        }

        // Sync billing details from WooCommerce order if booking lacks customer info
        self::sync_booking_contact_to_order($order, (int) $booking_id, $repo);
        self::sync_billing_details_to_booking($order, $booking_id, $repo);

        if ($booking['status'] === 'pending') {
            $updated = $repo->update_status($booking_id, 'in_review');
            if ($updated) {
                error_log('SpaceBooking WC: Booking #' . $booking_id . ' set to in_review from order #' . $order_id);
            } else {
                error_log('SpaceBooking WC: Failed to update status for booking #' . $booking_id);
            }
        } else {
            error_log('SpaceBooking WC: Booking #' . $booking_id . ' already ' . $booking['status'] . ', skipping');
        }
    }

    /**
     * Sync billing details from WooCommerce order to booking.
     * This allows skipping Step4Details - customer info comes from WC checkout.
     */
    private static function sync_billing_details_to_booking($order, int $booking_id, $repo): void
    {
        // Get current booking data
        $booking = $repo->find($booking_id);
        if (!$booking) {
            return;
        }

        // Check if we need to update (booking missing customer info)
        $needs_update = false;
        $customer_name = '';
        $customer_email = '';
        $customer_phone = '';
        $booking_notes = '';

        if (empty($booking['customer_name']) || $booking['customer_name'] === 'Guest' || $booking['customer_name'] === '') {
            // Get billing name from WC order
            $first_name = $order->get_billing_first_name();
            $last_name = $order->get_billing_last_name();
            $customer_name = trim($first_name . ' ' . $last_name);

            // Fallback to shipping name if billing is empty
            if (empty($customer_name)) {
                $customer_name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
            }

            // Final fallback to guest placeholder
            if (empty($customer_name)) {
                $customer_name = 'Guest';
            }
            $needs_update = true;
        } else {
            $customer_name = $booking['customer_name'];
        }

        if (empty($booking['customer_email']) || $booking['customer_email'] === '') {
            // Get billing email from WC order
            $customer_email = $order->get_billing_email();

            // Final fallback to placeholder if still empty
            if (empty($customer_email)) {
                $customer_email = 'customer@checkout.com';
            }
            $needs_update = true;
        } else {
            $customer_email = $booking['customer_email'];
        }

        if (empty($booking['customer_phone']) || $booking['customer_phone'] === '') {
            $customer_phone = $order->get_billing_phone();
            if (!empty($customer_phone)) {
                $needs_update = true;
            }
        } else {
            $customer_phone = $booking['customer_phone'];
        }

        if (empty($booking['notes']) || $booking['notes'] === '') {
            $booking_notes = (string) $order->get_customer_note();
            if (!empty($booking_notes)) {
                $needs_update = true;
            }
        } else {
            $booking_notes = $booking['notes'];
        }

        if ($needs_update) {
            global $wpdb;
            $result = $wpdb->update(
                $wpdb->prefix . 'sb_bookings',
                [
                    'customer_name' => $customer_name,
                    'customer_email' => $customer_email,
                    'customer_phone' => $customer_phone,
                    'notes' => $booking_notes,
                ],
                ['id' => $booking_id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );

            if ($result !== false) {
                error_log("SpaceBooking WC: Synced billing details to booking #{$booking_id}: name='{$customer_name}', email='{$customer_email}'");
            } else {
                error_log("SpaceBooking WC: Failed to sync billing details to booking #{$booking_id}: " . $wpdb->last_error);
            }
        } else {
            error_log("SpaceBooking WC: Booking #{$booking_id} already has customer info, skipping sync");
        }
    }
}
