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
    
    // Get Elementor Global Colors (fallback defaults)
    $primary_color = '#7c3aed'; // Purple
    $accent_color = '#a78bfa';   // Light purple
    
    $subject = sprintf(__('Your Booking is Confirmed! - Booking #%d', 'space-booking'), $booking_id);
    
    // Build booking details HTML
    $selected_items = $booking['_selected_items'] ?? [];
    $extras_details = $booking['_extras_details'] ?? [];
    $price_breakdown = $booking['_price_breakdown'] ?? [];
    
    // Format time
    $start_time = date('g:i A', strtotime($booking['start_time']));
    $end_time = date('g:i A', strtotime($booking['end_time']));
    $time_display = $start_time . ' - ' . $end_time;
    $date_display = date_i18n(get_option('date_format'), strtotime($booking['booking_date']));
    
    // Build spaces/packages list
    $items_list = '';
    foreach ($selected_items as $item) {
        $items_list .= '<li>' . esc_html($item['title']) . '</li>';
    }
    
    // Build extras list
    $extras_list = '';
    foreach ($extras_details as $extra) {
        $extras_list .= '<li>' . esc_html($extra['extra_name']) . ' x' . $extra['quantity'] . '</li>';
    }
    
    // Build price breakdown
    $breakdown_html = '';
    foreach ($price_breakdown as $item) {
        $breakdown_html .= '<tr><td style="text-align:left;padding:8px;border-bottom:1px solid #eee;">' . esc_html($item['name']) . '</td><td style="text-align:right;padding:8px;border-bottom:1px solid #eee;">$' . number_format($item['price'], 2) . '</td></tr>';
    }
    
    // If no detailed breakdown, use simple breakdown
    if (empty($breakdown_html)) {
        $breakdown_html = '<tr><td style="text-align:left;padding:8px;border-bottom:1px solid #eee;">Base</td><td style="text-align:right;padding:8px;border-bottom:1px solid #eee;">$' . number_format($booking['base_price'], 2) . '</td></tr>';
        if (!empty($extras_list)) {
            $breakdown_html .= '<tr><td style="text-align:left;padding:8px;border-bottom:1px solid #eee;">Extras</td><td style="text-align:right;padding:8px;border-bottom:1px solid #eee;">$' . number_format($booking['extras_price'], 2) . '</td></tr>';
        }
    }
    
    // Admin feedback section
    $feedback_html = '';
    if ($admin_feedback) {
        $feedback_html = '
        <div style="margin-top:20px;padding:15px;background:#fafafa;border-left:4px solid ' . $accent_color . ';">
            <h4 style="margin:0 0 5px;font-size:14px;">' . __('Message from the team', 'space-booking') . '</h4>
            <p style="margin:0;font-size:13px;color:#666;">' . nl2br(esc_html($admin_feedback)) . '</p>
        </div>';
    }
    
    // Build email message
    $message = '
    <div style="background-color:#f7f7f7;padding:40px 0;font-family:Arial,sans-serif;">
        <div style="background-color:#ffffff;width:600px;margin:0 auto;border-radius:8px;border:1px solid #e5e5e5;overflow:hidden;">
            <div style="background-color:' . $primary_color . ';color:#ffffff;padding:30px;text-align:center;">
                <h1 style="margin:0;font-size:24px;text-transform:uppercase;">' . __('Booking Confirmed', 'space-booking') . '</h1>
                <p style="margin:5px 0 0;">' . sprintf(__('Booking #%d'), $booking_id) . '</p>
            </div>
            
            <div style="padding:30px;color:#333;">
                <p>Hi ' . esc_html($first_name) . ',</p>
                <p>Your reservation is confirmed! Here is a summary of your booking:</p>
                
                <div style="margin-bottom:30px;padding:20px;border:1px solid ' . $accent_color . ';border-radius:8px;background-color:#fafafa;">
                    <table style="width:100%;border-collapse:collapse;">
                        <tr>
                            <td style="vertical-align:top;width:50%;">
                                <h2 style="margin:0 0 10px;font-size:16px;color:' . $primary_color . ';text-transform:uppercase;">' . __('Appointment', 'space-booking') . '</h2>
                                <p style="margin:0 0 10px;font-size:13px;line-height:1.4;"><strong>' . $date_display . '</strong><br>' . $time_display . ' (' . $booking['duration_hours'] . ' hrs)</p>
                            </td>
                            <td style="vertical-align:top;width:50%;border-left:1px solid #eee;padding-left:20px;">
                                <h2 style="margin:0 0 10px;font-size:16px;color:' . $primary_color . ';text-transform:uppercase;">' . __('Your Booking', 'space-booking') . '</h2>
                                <ul style="margin:0;padding-left:20px;font-size:13px;line-height:1.8;">' . $items_list . '</ul>
                            </td>
                        </tr>
                    </table>
                </div>';
    
    if (!empty($extras_list)) {
        $message .= '
                <h3 style="font-size:15px;margin-bottom:10px;color:#777;">' . __('Extras', 'space-booking') . '</h3>
                <ul style="margin:0 0 20px;padding-left:20px;font-size:13px;">' . $extras_list . '</ul>';
    }
    
    $message .= '
                <h3 style="font-size:15px;margin-bottom:10px;color:#777;">' . __('ORDER BREAKDOWN', 'space-booking') . '</h3>
                <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
                    <tbody>' . $breakdown_html . '
                    </tbody>
                    <tfoot>
                        <tr>
                            <th style="text-align:left;padding:12px;border-top:2px solid ' . $primary_color . ';">' . __('Total', 'space-booking') . '</th>
                            <th style="text-align:right;padding:12px;border-top:2px solid ' . $primary_color . ';font-size:18px;color:' . $primary_color . ';">$' . number_format($booking['total_price'], 2) . '</th>
                        </tr>
                    </tfoot>
                </table>';
    
    $message .= $feedback_html;
    
    $message .= '
            </div>
            <div style="background-color:#f1f1f1;padding:15px;text-align:center;font-size:11px;color:#777;border-top:4px solid ' . $accent_color . ';">
                ' . __('Sent from your booking dashboard.', 'space-booking') . '
            </div>
        </div>
    </div>';
    
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