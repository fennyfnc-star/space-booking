<?php
/** Admin AJAX Handlers for Booking Updates */

/**
 * Send booking confirmation email with admin feedback
 * 
 * @param int $booking_id Booking ID
 * @param string $admin_feedback Optional admin feedback message
 * @return bool True if email sent successfully
 */
function sb_send_booking_confirmation_email(int $booking_id, string $admin_feedback = ''): bool {
    global $wpdb;
    
    $repo = new \SpaceBooking\Services\BookingRepository();
    $booking = $repo->findEnriched($booking_id);
    
    if (!$booking) {
        return false;
    }
    
    $to = $booking['customer_email'];
    $first_name = $booking['customer_name'];
    
    if (!$to) {
        return false;
    }
    
    // Elementor-like style colors with safe defaults.
    $primary_color = '#557da1';
    $accent_color = '#e5e5e5';

    // Currency symbol for fallback rows.
    $currency_symbol = function_exists('get_woocommerce_currency_symbol')
        ? get_woocommerce_currency_symbol()
        : '£';

    $subject = sprintf(__('Your Booking is Confirmed! - Booking #%d', 'space-booking'), $booking_id);

    // Build booking details HTML.
    $selected_items = is_array($booking['_selected_items'] ?? null) ? $booking['_selected_items'] : [];
    $extras_details = is_array($booking['_extras_details'] ?? null) ? $booking['_extras_details'] : [];
    $price_breakdown = is_array($booking['_price_breakdown'] ?? null) ? $booking['_price_breakdown'] : [];
    $meta_data = is_array($booking['_meta_data'] ?? null) ? $booking['_meta_data'] : [];

    // Format time/date.
    $start_time = date('g:i A', strtotime((string) $booking['start_time']));
    $end_time = date('g:i A', strtotime((string) $booking['end_time']));
    $time_display = $start_time . ' - ' . $end_time;
    $date_display = date_i18n(get_option('date_format'), strtotime((string) $booking['booking_date']));

    // Build selected items list.
    $items_list = '';
    foreach ($selected_items as $item) {
        $label = esc_html((string) ($item['title'] ?? 'Item'));
        $type = (string) ($item['type'] ?? '');
        if ($type === 'sb_package') {
            $label .= ' <small style="color:#666;">(Package)</small>';
        }
        $items_list .= '<li style="margin:0 0 4px;">' . $label . '</li>';
    }
    if ($items_list === '') {
        $items_list = '<li>' . esc_html(get_the_title((int) $booking['space_id']) ?: __('Space', 'space-booking')) . '</li>';
    }

    // Build extras list.
    $extras_list = '';
    foreach ($extras_details as $extra) {
        $extra_name = (string) ($extra['extra_name'] ?? $extra['title'] ?? __('Extra', 'space-booking'));
        $extra_qty = (int) ($extra['quantity'] ?? 1);
        $unit_price = isset($extra['unit_price']) ? (float) $extra['unit_price'] : 0.0;
        $extras_list .= '<li style="margin:0 0 4px;">' .
            esc_html($extra_name) .
            ' × ' . esc_html((string) $extra_qty) .
            ($unit_price > 0 ? ' <small style="color:#666;">(' . wp_kses_post(wc_price($unit_price)) . ')</small>' : '') .
            '</li>';
    }

    // Package inclusions.
    $package_inclusions_html = '';
    $package_inclusions_raw = $meta_data['_sb_package_inclusions'] ?? '';
    if (is_string($package_inclusions_raw) && $package_inclusions_raw !== '') {
        $inclusions = json_decode($package_inclusions_raw, true);
        if (is_array($inclusions) && !empty($inclusions)) {
            $items = '';
            foreach ($inclusions as $inc) {
                $inc_title = (string) ($inc['title'] ?? $inc['label'] ?? '');
                if ($inc_title === '') {
                    continue;
                }
                $inc_type = (string) ($inc['type'] ?? '');
                $suffix = $inc_type === 'sb_extra'
                    ? __(' (Extra - Package)', 'space-booking')
                    : ($inc_type === 'sb_space' ? __(' (Space - Package)', 'space-booking') : __(' (Package)', 'space-booking'));
                $items .= '<li style="margin:0 0 4px;">' . esc_html($inc_title . $suffix) . '</li>';
            }
            if ($items !== '') {
                $package_inclusions_html = '<h3 style="font-size:15px;margin:16px 0 10px;color:#777;">' . esc_html__('Package Inclusions', 'space-booking') . '</h3><ul style="margin:0 0 20px;padding-left:20px;font-size:13px;">' . $items . '</ul>';
            }
        }
    }

    // Build price breakdown (supports both old and new shapes).
    $breakdown_html = '';
    foreach ($price_breakdown as $item) {
        $label = (string) ($item['label'] ?? $item['name'] ?? __('Line Item', 'space-booking'));
        $amount = isset($item['amount']) ? (float) $item['amount'] : (isset($item['price']) ? (float) $item['price'] : 0.0);
        $breakdown_html .= '<tr>' .
            '<td style="text-align:left;padding:8px;border-bottom:1px solid #eee;">' . esc_html($label) . '</td>' .
            '<td style="text-align:right;padding:8px;border-bottom:1px solid #eee;">' . wp_kses_post(wc_price($amount)) . '</td>' .
            '</tr>';
    }

    if ($breakdown_html === '') {
        $base_price = (float) ($booking['base_price'] ?? 0);
        $extras_price = (float) ($booking['extras_price'] ?? 0);
        $modifier_price = (float) ($booking['modifier_price'] ?? 0);
        $breakdown_html .= '<tr><td style="text-align:left;padding:8px;border-bottom:1px solid #eee;">' . esc_html__('Base', 'space-booking') . '</td><td style="text-align:right;padding:8px;border-bottom:1px solid #eee;">' . wp_kses_post(wc_price($base_price)) . '</td></tr>';
        if ($extras_price > 0) {
            $breakdown_html .= '<tr><td style="text-align:left;padding:8px;border-bottom:1px solid #eee;">' . esc_html__('Extras', 'space-booking') . '</td><td style="text-align:right;padding:8px;border-bottom:1px solid #eee;">' . wp_kses_post(wc_price($extras_price)) . '</td></tr>';
        }
        if ($modifier_price !== 0.0) {
            $breakdown_html .= '<tr><td style="text-align:left;padding:8px;border-bottom:1px solid #eee;">' . esc_html__('Modifiers', 'space-booking') . '</td><td style="text-align:right;padding:8px;border-bottom:1px solid #eee;">' . wp_kses_post(wc_price($modifier_price)) . '</td></tr>';
        }
    }

    // Pull linked WooCommerce order when available to include complete checkout fields.
    $order = null;
    $order_id = isset($booking['order_id']) ? (int) $booking['order_id'] : 0;
    if ($order_id > 0 && function_exists('wc_get_order')) {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            $order = null;
        }
    }
    $order_date = ($order instanceof WC_Order && $order->get_date_created())
        ? wc_format_datetime($order->get_date_created())
        : $date_display;
    $order_ref = $order instanceof WC_Order ? (string) $order->get_id() : (string) $booking_id;

    $checkout_fields_rows = '';
    if ($order instanceof WC_Order) {
        $checkout_fields = [
            __('Email', 'space-booking') => $order->get_billing_email(),
            __('Phone', 'space-booking') => $order->get_billing_phone(),
            __('Company', 'space-booking') => $order->get_billing_company(),
            __('Address 1', 'space-booking') => $order->get_billing_address_1(),
            __('Address 2', 'space-booking') => $order->get_billing_address_2(),
            __('City', 'space-booking') => $order->get_billing_city(),
            __('State', 'space-booking') => $order->get_billing_state(),
            __('Postcode', 'space-booking') => $order->get_billing_postcode(),
            __('Country', 'space-booking') => $order->get_billing_country(),
            __('Payment Method', 'space-booking') => $order->get_payment_method_title(),
        ];
        foreach ($checkout_fields as $key => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $checkout_fields_rows .= '<tr><td style="padding:6px 0;color:#777;font-size:12px;width:38%;">' . esc_html($key) . '</td><td style="padding:6px 0;font-size:12px;">' . esc_html($value) . '</td></tr>';
        }
    }

    // Booking-side customer fields.
    $booking_fields_rows = '';
    $booking_fields = [
        __('Name', 'space-booking') => (string) ($booking['customer_name'] ?? ''),
        __('Email', 'space-booking') => (string) ($booking['customer_email'] ?? ''),
        __('Phone', 'space-booking') => (string) ($booking['customer_phone'] ?? ''),
        __('Booking Date', 'space-booking') => $date_display,
        __('Booking Time', 'space-booking') => $time_display,
        __('Duration', 'space-booking') => number_format((float) ($booking['duration_hours'] ?? 0), 1) . ' ' . __('hours', 'space-booking'),
        __('Status', 'space-booking') => (string) ($booking['status'] ?? ''),
        __('Booking ID', 'space-booking') => (string) $booking_id,
    ];
    foreach ($booking_fields as $key => $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $booking_fields_rows .= '<tr><td style="padding:6px 0;color:#777;font-size:12px;width:38%;">' . esc_html($key) . '</td><td style="padding:6px 0;font-size:12px;">' . esc_html($value) . '</td></tr>';
    }

    // Customer notes from both booking and order.
    $booking_note = trim((string) ($booking['notes'] ?? ''));
    $order_note = ($order instanceof WC_Order) ? trim((string) $order->get_customer_note()) : '';
    $customer_notes_html = '';
    if ($booking_note !== '' || $order_note !== '') {
        $customer_notes_html .= '<div style="margin-top:20px;padding:15px;background:#fafafa;border-left:4px solid ' . esc_attr($accent_color) . ';">';
        $customer_notes_html .= '<h4 style="margin:0 0 6px;font-size:14px;">' . esc_html__('Customer Notes', 'space-booking') . '</h4>';
        if ($booking_note !== '') {
            $customer_notes_html .= '<p style="margin:0 0 6px;font-size:13px;color:#666;"><strong>' . esc_html__('Booking form:', 'space-booking') . '</strong><br>' . nl2br(esc_html($booking_note)) . '</p>';
        }
        if ($order_note !== '') {
            $customer_notes_html .= '<p style="margin:0;font-size:13px;color:#666;"><strong>' . esc_html__('Checkout note:', 'space-booking') . '</strong><br>' . nl2br(esc_html($order_note)) . '</p>';
        }
        $customer_notes_html .= '</div>';
    }

    // Admin feedback section.
    $feedback_html = '';
    if ($admin_feedback !== '') {
        $feedback_html = '
        <div style="margin-top:20px;padding:15px;background:#fafafa;border-left:4px solid ' . esc_attr($accent_color) . ';">
            <h4 style="margin:0 0 5px;font-size:14px;">' . esc_html__('Message from the team', 'space-booking') . '</h4>
            <p style="margin:0;font-size:13px;color:#666;">' . nl2br(esc_html($admin_feedback)) . '</p>
        </div>';
    }

    $order_items_html = '';
    if ($order instanceof WC_Order) {
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $image_id = $product ? $product->get_image_id() : 0;
            $image_url = $image_id ? (wp_get_attachment_image_url($image_id, 'thumbnail') ?: wc_placeholder_img_src()) : wc_placeholder_img_src();
            $order_items_html .= "
            <tr>
                <td style='text-align:left; border-bottom: 1px solid #eee; padding:12px; vertical-align: middle;'>
                    <img src='" . esc_url($image_url) . "' width='40' height='40' style='vertical-align:middle; margin-right: 10px; border-radius:4px;'>
                    <span style='display:inline-block; vertical-align:middle;'>
                        <strong>" . esc_html($item->get_name()) . "</strong> × " . esc_html((string) $item->get_quantity()) . "
                    </span>
                </td>
                <td style='text-align:right; border-bottom: 1px solid #eee; padding:12px; vertical-align: middle;'>" . wp_kses_post(wc_price((float) $item->get_total())) . "</td>
            </tr>";
        }
    }

    if ($order_items_html === '') {
        $order_items_html = "<tr><td style='text-align:left; border-bottom: 1px solid #eee; padding:12px;'>" . esc_html__('Booking', 'space-booking') . " #" . esc_html((string) $booking_id) . "</td><td style='text-align:right; border-bottom: 1px solid #eee; padding:12px;'>" . esc_html($currency_symbol) . number_format((float) ($booking['total_price'] ?? 0), 2) . "</td></tr>";
    }

    $total_display = $order instanceof WC_Order
        ? $order->get_formatted_order_total()
        : esc_html($currency_symbol) . number_format((float) ($booking['total_price'] ?? 0), 2);

    $subtotal_display = $order instanceof WC_Order
        ? $order->get_subtotal_to_display()
        : esc_html($currency_symbol) . number_format((float) ($booking['base_price'] ?? 0) + (float) ($booking['extras_price'] ?? 0), 2);

    // Build email message.
    $message = "
    <div style='background-color: #f7f7f7; padding: 40px 0; font-family: Arial, sans-serif;'>
        <div style='background-color: #ffffff; width: 600px; margin: 0 auto; border-radius: 8px; border: 1px solid #e5e5e5; overflow: hidden;'>
            <div style='background-color: " . esc_attr($primary_color) . "; color: #ffffff; padding: 30px; text-align: center;'>
                <h1 style='margin: 0; font-size: 24px; text-transform: uppercase;'>" . esc_html__('Booking Confirmed', 'space-booking') . "</h1>
                <p style='margin: 5px 0 0;'>" . esc_html__('Reference', 'space-booking') . " #". esc_html($order_ref) . " (" . esc_html($order_date) . ")</p>
            </div>
            
            <div style='padding: 30px; color: #333;'>
                <p>" . esc_html__('Hi', 'space-booking') . " " . esc_html($first_name) . ",</p>
                <p>" . esc_html__('Your reservation is confirmed. Here is your complete booking and order summary:', 'space-booking') . "</p>
                
                <div style='margin-bottom: 24px; padding: 20px; border: 1px solid " . esc_attr($accent_color) . "; border-radius: 8px; background-color: #fafafa;'>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr>
                            <td style='vertical-align: top; width: 50%;'>
                                <h2 style='margin: 0 0 10px; font-size: 16px; color: " . esc_attr($primary_color) . "; text-transform: uppercase;'>" . esc_html__('Appointment', 'space-booking') . "</h2>
                                <p style='margin: 0 0 10px; font-size: 13px; line-height: 1.4;'><strong>" . esc_html($date_display) . "</strong><br>" . esc_html($time_display) . " (" . esc_html(number_format((float) ($booking['duration_hours'] ?? 0), 1)) . " " . esc_html__('hrs', 'space-booking') . ")</p>
                                <h3 style='font-size: 14px; margin: 12px 0 8px; color: #777;'>" . esc_html__('Selected Items', 'space-booking') . "</h3>
                                <ul style='margin:0;padding-left:18px;font-size:13px;line-height:1.6;'>" . $items_list . "</ul>
                            </td>
                            <td style='vertical-align: top; width: 50%; border-left: 1px solid #eee; padding-left: 20px;'>
                                <h2 style='margin: 0 0 10px; font-size: 16px; color: " . esc_attr($primary_color) . "; text-transform: uppercase;'>" . esc_html__('Customer / Checkout Details', 'space-booking') . "</h2>
                                <table style='width:100%; border-collapse:collapse;'>" . $booking_fields_rows . $checkout_fields_rows . "</table>
                            </td>
                        </tr>
                    </table>
                </div>

                " . $package_inclusions_html . "

                <h3 style='font-size: 15px; margin-bottom: 10px; color: #777;'>" . esc_html__('Extras', 'space-booking') . "</h3>
                " . ($extras_list !== '' ? "<ul style='margin:0 0 20px;padding-left:20px;font-size:13px;'>" . $extras_list . "</ul>" : "<p style='margin:0 0 20px;font-size:13px;color:#666;'>" . esc_html__('None', 'space-booking') . "</p>") . "

                <h3 style='font-size: 15px; margin-bottom: 10px; color: #777;'>" . esc_html__('Price Breakdown', 'space-booking') . "</h3>
                <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
                    <tbody>" . $breakdown_html . "</tbody>
                    <tfoot>
                        <tr>
                            <th style='text-align:left; padding:12px; border-top: 1px solid #eee;'>" . esc_html__('Subtotal', 'space-booking') . "</th>
                            <td style='text-align:right; padding:12px; border-top: 1px solid #eee;'>" . wp_kses_post($subtotal_display) . "</td>
                        </tr>
                        <tr>
                            <th style='text-align:left; padding:12px;'>" . esc_html__('Total Paid', 'space-booking') . "</th>
                            <td style='text-align:right; padding:12px; font-size: 20px; color: " . esc_attr($primary_color) . "; font-weight: bold;'>" . wp_kses_post($total_display) . "</td>
                        </tr>
                    </tfoot>
                </table>

                <h3 style='font-size: 15px; margin-bottom: 10px; color: #777;'>" . esc_html__('Order Items', 'space-booking') . "</h3>
                <table style='width:100%;border-collapse:collapse;margin-bottom:20px;'>
                    <thead>
                        <tr>
                            <th style='text-align:left; border-bottom: 2px solid #eee; padding:12px; background: #fafafa; font-size: 12px;'>" . esc_html__('Product', 'space-booking') . "</th>
                            <th style='text-align:right; border-bottom: 2px solid #eee; padding:12px; background: #fafafa; font-size: 12px;'>" . esc_html__('Price', 'space-booking') . "</th>
                        </tr>
                    </thead>
                    <tbody>" . $order_items_html . "</tbody>
                </table>

                " . $customer_notes_html . "
                " . $feedback_html . "
            </div>
            <div style='background-color: #f1f1f1; padding: 15px; text-align: center; font-size: 11px; color: #777; border-top: 4px solid " . esc_attr($accent_color) . ";'>
                " . esc_html__('Sent from your booking dashboard.', 'space-booking') . "
            </div>
        </div>
    </div>";
    
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    
    // Allow custom hook for email sending
    do_action('sb_before_send_confirmation_email', $booking, $admin_feedback);
    
    $sent = wp_mail($to, $subject, $message, $headers);
    
    do_action('sb_after_send_confirmation_email', $booking, $admin_feedback, $sent);
    
    return $sent;
}

add_action('wp_ajax_sb_update_booking_status', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_ajax_referer('sb_update_booking', '_wpnonce');

    $booking_id = absint($_POST['booking_id'] ?? 0);
    $status = sanitize_text_field($_POST['status'] ?? '');
    $feedback = sanitize_textarea_field($_POST['feedback'] ?? '');

    if (!$booking_id || !in_array($status, ['pending', 'in_review', 'confirmed'])) {
        wp_send_json_error('Invalid input');
    }

    $repo = new \SpaceBooking\Services\BookingRepository();
    $booking = $repo->find($booking_id);

    if (!$booking) {
        wp_send_json_error('Booking not found');
    }

    $extra_data = $feedback ? ['admin_feedback' => $feedback] : [];
    
    // If changing to confirmed, send confirmation email first
    $email_sent = true;
    if ($status === 'confirmed' && $booking['status'] !== 'confirmed') {
        $email_sent = sb_send_booking_confirmation_email($booking_id, $feedback);
        
        if (!$email_sent) {
            wp_send_json_error([
                'message' => 'Failed to send confirmation email. Booking status not changed.',
                'email_failed' => true
            ]);
            return;
        }
    }
    
    $updated = $repo->update_status($booking_id, $status, $extra_data);

    if ($updated) {
        wp_send_json_success([
            'status' => $status,
            'feedback' => $feedback,
            'email_sent' => $email_sent
        ]);
    } else {
        wp_send_json_error('Failed to update booking');
    }
});

// Enqueue edit page scripts (only on booking edit page)
add_action('admin_enqueue_scripts', function ($hook) {
    $screen = get_current_screen();
    if ($screen->id === 'space-booking_page_space-booking-bookings' && isset($_GET['edit'])) {
        wp_enqueue_script('jquery');
    }
});
?>
