<?php declare(strict_types=1);

namespace SpaceBooking\Admin;

use SpaceBooking\Services\BookingRepository;

/**
 * Adds test email action to WooCommerce order actions.
 */
final class WooCommerceOrderActions
{
    public static function register(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_filter('woocommerce_order_actions', [self::class, 'add_test_email_action'], 10, 2);
        add_action('woocommerce_order_action_send_test_email_action', [self::class, 'handle_test_email']);
    }

    /**
     * Add "Send Test Email" action to ALL orders.
     */
    public static function add_test_email_action(array $actions, \WC_Order $order): array
    {
        $actions['send_test_email_action'] = __('Send Confirmation Email', 'space-booking');
        return $actions;
    }

    /**
     * Handle Test Email action.
     */
    public static function handle_test_email(\WC_Order $order): void
    {
        // Get the billing email from the order
        $to = $order->get_billing_email();
        $order_id = $order->get_id();

        // Define the email subject and content
        $subject = 'Test Email for Order #' . $order_id;

        // Simple HTML Template
        $message = '
            <h2>Hello ' . $order->get_billing_first_name() . ',</h2>
            <p>This is a test email sent manually from your order dashboard.</p>
            <p><strong>Order Details:</strong></p>
            <ul>
                <li>Order ID: ' . $order_id . '</li>
                <li>Total: ' . $order->get_formatted_order_total() . '</li>
            </ul>
            <p>Thank you for testing!</p>
        ';

        // Set headers for HTML email
        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Send the email using the standard WordPress mailer
        wp_mail($to, $subject, $message, $headers);

        // Add an order note so you know the email was sent
        $order->add_order_note(__('Test email sent to customer manually.', 'space-booking'));
    }
}
