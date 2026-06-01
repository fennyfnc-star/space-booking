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

        // Add "Send Booking Confirmation" action to WooCommerce order actions dropdown
        // add_filter('woocommerce_order_actions', [self::class, 'add_booking_confirmation_action'], 10, 2);

        // Handle the booking confirmation action when triggered
        add_action('woocommerce_order_action_sb_send_booking_confirmation', [self::class, 'handle_booking_confirmation']);
    }

    /**
     * Add "Send Booking Confirmation" action to ALL orders.
     */
    public static function add_booking_confirmation_action(array $actions, \WC_Order $order): array
    {
        $actions['sb_send_booking_confirmation'] = __('Send Booking Confirmation', 'space-booking');
        return $actions;
    }

    /**
     * Handle Booking Confirmation action.
     * Sends confirmation email for bookings associated with this order.
     */
    public static function handle_booking_confirmation(\WC_Order $order): void
    {
        $order_id = $order->get_id();

        // Try to find associated booking(s) by order ID or customer email
        $repo = new BookingRepository();
        global $wpdb;

        // Search for bookings by linked order ID or customer email.
        $customer_email = $order->get_billing_email();
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT b.id, b.order_id, b.space_id, b.booking_date, b.start_time, b.end_time, b.status, b.customer_name, b.customer_email
            FROM {$wpdb->prefix}sb_bookings b
            WHERE b.order_id = %d OR b.customer_email = %s
            ORDER BY b.id DESC
            LIMIT 10
        ", $order_id, $customer_email), ARRAY_A);

        if (empty($bookings)) {
            $order->add_order_note(__('No associated bookings found for this order.', 'space-booking'));
            return;
        }

        $sent_count = 0;
        foreach ($bookings as $booking) {
            // Send confirmation email for each booking
            $email_sent = self::send_booking_confirmation_email($booking, $order);
            if ($email_sent) {
                $sent_count++;
            }
        }

        $order->add_order_note(sprintf(
            __('Booking confirmation(s) sent: %d email(s).', 'space-booking'),
            $sent_count
        ));
    }

    /**
     * Send booking confirmation email.
     */
    private static function send_booking_confirmation_email(array $booking, \WC_Order $order): bool
    {
        $booking_id = $booking['id'];
        $space_id = $booking['space_id'];
        $space_title = get_the_title($space_id) ?: 'Space';

        $to = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

        $subject = get_option('sb_confirmation_email_subject', 'YourBooking is Confirmed!');
        if (empty($subject)) {
            $subject = 'Your Booking is Confirmed - ' . $space_title;
        }

        $subject = str_replace(
            ['{space_title}', '{booking_date}', '{start_time}'],
            [$space_title, $booking['booking_date'], $booking['start_time']],
            $subject
        );

        $repo = new BookingRepository();
        $package_answer_rows = \SpaceBooking\Services\EmailTemplateHelper::package_question_rows_from_meta_string(
            (string) $repo->get_meta((int) $booking_id, '_sb_package_question_answers')
        );
        $package_answers_html = \SpaceBooking\Services\EmailTemplateHelper::render_package_qa_html($package_answer_rows);
        $primary_color = \SpaceBooking\Services\EmailTemplateHelper::PRIMARY_COLOR;

        // Build email content
        $message = '<div style="font-family:Arial,sans-serif;background:#f4f4f4;padding:24px;">';
        $message .= '<div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;">';
        $message .= '<div style="background:' . esc_attr($primary_color) . ';color:#fff;padding:18px 24px;"><h2 style="margin:0;">' . esc_html__('Booking Confirmed', 'space-booking') . '</h2></div>';
        $message .= '<div style="padding:24px;color:#222;">';
        $message .= '<h2>Hello ' . esc_html($customer_name) . ',</h2>';
        $message .= '<p>Your booking has been confirmed!</p>';
        $message .= '<h3>Booking Details</h3>';
        $message .= '<ul>';
        $message .= '<li><strong>Space:</strong> ' . esc_html($space_title) . '</li>';
        $message .= '<li><strong>Date:</strong> ' . esc_html($booking['booking_date']) . '</li>';
        $message .= '<li><strong>Time:</strong> ' . esc_html($booking['start_time']) . ' - ' . esc_html($booking['end_time']) . '</li>';
        $message .= '<li><strong>Booking ID:</strong> ' . $booking_id . '</li>';
        $message .= '</ul>';
        if ($package_answers_html !== '') {
            $message .= $package_answers_html;
        }

        // Add order total
        $message .= '<p><strong>Total Paid:</strong> ' . $order->get_formatted_order_total() . '</p>';
        $message .= '<p>Thank you for your booking!</p>';
        $message .= '</div></div></div>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        return wp_mail($to, $subject, $message, $headers);
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
