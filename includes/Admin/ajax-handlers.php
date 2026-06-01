<?php
/** Admin AJAX Handlers for Booking Updates */

/**
 * Send booking confirmation email with admin feedback
 * 
 * @param int $booking_id Booking ID
 * @param string $admin_feedback Optional admin feedback message
 * @return bool True if email sent successfully
 */
function sb_send_booking_confirmation_email(int $booking_id, string $admin_feedback = '', ?string $status_override = null): bool {
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
    
    // Email theme colors.
    $primary_color = \SpaceBooking\Services\EmailTemplateHelper::PRIMARY_COLOR;
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
    if (empty($price_breakdown)) {
        $snapshot = is_array($booking['_price_snapshot_v1'] ?? null) ? $booking['_price_snapshot_v1'] : [];
        $snapshot_lines = is_array($snapshot['line_items'] ?? null) ? $snapshot['line_items'] : [];
        foreach ($snapshot_lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $label = trim((string) ($line['label'] ?? ''));
            $amount = isset($line['line_total']) ? (float) $line['line_total'] : 0.0;
            if ($label === '') {
                continue;
            }
            $price_breakdown[] = [
                'label' => $label,
                'amount' => $amount,
                'type' => (string) ($line['type'] ?? ''),
            ];
        }
    }
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

    // Build grouped price breakdown by category.
    $space_titles = [];
    $package_titles = [];
    foreach ($selected_items as $item) {
        $item_title = trim((string) ($item['title'] ?? ''));
        if ($item_title === '') {
            continue;
        }
        if ((string) ($item['type'] ?? '') === 'sb_space') {
            $space_titles[] = $item_title;
        } elseif ((string) ($item['type'] ?? '') === 'sb_package') {
            $package_titles[] = $item_title;
        }
    }
    $extra_titles = [];
    foreach ($extras_details as $extra) {
        $extra_title = trim((string) ($extra['extra_name'] ?? $extra['title'] ?? ''));
        if ($extra_title !== '') {
            $extra_titles[] = $extra_title;
        }
    }
    $package_inclusion_titles = [];
    if (is_string($package_inclusions_raw) && $package_inclusions_raw !== '') {
        $inclusions = json_decode($package_inclusions_raw, true);
        if (is_array($inclusions)) {
            foreach ($inclusions as $inc) {
                if (!is_array($inc)) {
                    continue;
                }
                $inc_title = trim((string) ($inc['title'] ?? $inc['label'] ?? ''));
                if ($inc_title !== '') {
                    $package_inclusion_titles[] = $inc_title;
                }
            }
        }
    }
    $grouped_rows = [
        'space' => [],
        'package' => [],
        'extra' => [],
        'other' => [],
    ];
    $grouped_totals = [
        'space' => 0.0,
        'package' => 0.0,
        'extra' => 0.0,
        'other' => 0.0,
    ];

    foreach ($price_breakdown as $item) {
        $label = (string) ($item['label'] ?? $item['name'] ?? __('Line Item', 'space-booking'));
        $amount = isset($item['amount']) ? (float) $item['amount'] : (isset($item['price']) ? (float) $item['price'] : 0.0);
        $context_type = strtolower((string) ($item['context']['type'] ?? ''));
        $item_type = strtolower((string) ($item['type'] ?? $item['item_type'] ?? ''));
        $target_group = 'other';

        // Prefer exact title-based matching first for more accurate grouping.
        $matched = false;
        foreach ($package_titles as $title) {
            if (stripos($label, $title) !== false) {
                $target_group = 'package';
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            foreach ($space_titles as $title) {
                if (stripos($label, $title) !== false) {
                    $target_group = 'space';
                    $matched = true;
                    break;
                }
            }
        }
        if (!$matched && $context_type === 'space') {
            $target_group = 'space';
            $matched = true;
        } elseif (!$matched && $context_type === 'package') {
            $target_group = 'package';
            $matched = true;
        } elseif (!$matched && $context_type === 'extra') {
            $target_group = 'extra';
            $matched = true;
        } elseif (!$matched && ($item_type === 'sb_space' || $item_type === 'space')) {
            $target_group = 'space';
            $matched = true;
        } elseif (!$matched && ($item_type === 'sb_package' || $item_type === 'package')) {
            $target_group = 'package';
            $matched = true;
        } elseif (!$matched && ($item_type === 'sb_extra' || $item_type === 'extra')) {
            $target_group = 'extra';
            $matched = true;
        }

        if (!$matched) {
            // Fallback classification from inclusions/extras and legacy label heuristics.
            if (!$matched) {
                foreach ($package_inclusion_titles as $title) {
                    if (stripos($label, $title) !== false) {
                        $target_group = 'package';
                        $matched = true;
                        break;
                    }
                }
            }
            if (!$matched) {
                foreach ($extra_titles as $title) {
                    if (stripos($label, $title) !== false) {
                        $target_group = 'extra';
                        $matched = true;
                        break;
                    }
                }
            }
            if ($matched) {
                // no-op
            } else {
                // Legacy fallback inference from label when context is missing.
            $label_lc = strtolower($label);
            if (strpos($label_lc, 'extra') !== false) {
                $target_group = 'extra';
            } elseif (strpos($label_lc, 'inclusion') !== false) {
                $target_group = 'package';
            } elseif (strpos($label_lc, 'package') !== false) {
                $target_group = 'package';
            } elseif (strpos($label_lc, 'space') !== false) {
                $target_group = 'space';
            }
            }
        }

        $grouped_rows[$target_group][] = [
            'label' => $label,
            'amount' => $amount,
        ];
        $grouped_totals[$target_group] += $amount;
    }

    $breakdown_html = '';
    $append_group_rows = static function (string $title, array $rows, float $subtotal): string {
        if (empty($rows)) {
            return '';
        }

        $html = '<tr><td colspan="2" style="text-align:left;padding:10px 8px;background:#fafafa;border-top:1px solid #eee;border-bottom:1px solid #eee;font-weight:700;">' . esc_html($title) . '</td></tr>';
        foreach ($rows as $row) {
            $html .= '<tr>' .
                '<td style="text-align:left;padding:8px;border-bottom:1px solid #eee;">' . esc_html((string) $row['label']) . '</td>' .
                '<td style="text-align:right;padding:8px;border-bottom:1px solid #eee;">' . wp_kses_post(wc_price((float) $row['amount'])) . '</td>' .
                '</tr>';
        }
        $html .= '<tr>' .
            '<td style="text-align:left;padding:8px;border-bottom:1px solid #eee;color:#555;"><em>' . esc_html__('Subtotal', 'space-booking') . ' - ' . esc_html($title) . '</em></td>' .
            '<td style="text-align:right;padding:8px;border-bottom:1px solid #eee;color:#555;"><em>' . wp_kses_post(wc_price($subtotal)) . '</em></td>' .
            '</tr>';

        return $html;
    };

    $breakdown_html .= $append_group_rows(__('Spaces', 'space-booking'), $grouped_rows['space'], $grouped_totals['space']);
    $breakdown_html .= $append_group_rows(__('Packages', 'space-booking'), $grouped_rows['package'], $grouped_totals['package']);
    $breakdown_html .= $append_group_rows(__('Extras', 'space-booking'), $grouped_rows['extra'], $grouped_totals['extra']);
    $breakdown_html .= $append_group_rows(__('Other Charges', 'space-booking'), $grouped_rows['other'], $grouped_totals['other']);

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
    if (!$order instanceof WC_Order && function_exists('wc_get_orders')) {
        $orders = wc_get_orders([
            'limit' => 1,
            'return' => 'objects',
            'meta_query' => [
                [
                    'key' => '_sb_booking_id',
                    'value' => $booking_id,
                    'compare' => '=',
                ],
            ],
        ]);
        if (!empty($orders) && $orders[0] instanceof WC_Order) {
            $order = $orders[0];
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
        __('Status', 'space-booking') => (string) ($status_override ?: ($booking['status'] ?? '')),
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

    $package_answers_json = (string) ($meta_data['_sb_package_question_answers'] ?? '');
    if ($package_answers_json === '') {
        $package_answers_json = (string) $repo->get_meta($booking_id, '_sb_package_question_answers');
    }
    $package_answer_rows = \SpaceBooking\Services\EmailTemplateHelper::package_question_rows_from_meta_string(
        $package_answers_json
    );
    $package_answers_html = \SpaceBooking\Services\EmailTemplateHelper::render_package_qa_html($package_answer_rows);

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
                " . $package_answers_html . "

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
    $actor_user_id = get_current_user_id();

    if (!$booking) {
        wp_send_json_error('Booking not found');
    }

    $extra_data = $feedback ? ['admin_feedback' => $feedback] : [];

    $updated = $repo->update_status($booking_id, $status, $extra_data);

    if ($updated) {
        $repo->log_audit_event($booking_id, 'status_changed', $actor_user_id, [
            'from_status' => (string) $booking['status'],
            'to_status' => (string) $status,
        ]);

        // If changed to confirmed, send email after status persist; rollback if send fails.
        $email_sent = true;
        if ($status === 'confirmed' && $booking['status'] !== 'confirmed') {
            if ($feedback !== '' && (string) $repo->get_meta($booking_id, '_sb_first_confirmation_feedback') === '') {
                $repo->save_meta($booking_id, '_sb_first_confirmation_feedback', $feedback);
            }
            $email_sent = sb_send_booking_confirmation_email($booking_id, $feedback, 'confirmed');
            if (!$email_sent) {
                $repo->update_status($booking_id, (string) $booking['status']);
                $repo->log_audit_event($booking_id, 'status_changed', $actor_user_id, [
                    'from_status' => (string) $status,
                    'to_status' => (string) $booking['status'],
                    'reason' => 'email_send_failed_rollback',
                ]);
                $repo->log_audit_event($booking_id, 'confirmation_email_failed', $actor_user_id, [
                    'trigger' => 'status_change',
                    'attempted_status' => 'confirmed',
                ]);
                wp_send_json_error([
                    'message' => 'Failed to send confirmation email. Booking status was reverted.',
                    'email_failed' => true
                ]);
                return;
            }

            $repo->log_audit_event($booking_id, 'confirmation_email_sent', $actor_user_id, [
                'trigger' => 'status_change',
                'result' => 'success',
            ]);
        }

        wp_send_json_success([
            'status' => $status,
            'feedback' => $feedback,
            'email_sent' => $email_sent
        ]);
    } else {
        wp_send_json_error('Failed to update booking');
    }
});

add_action('wp_ajax_sb_resend_booking_confirmation_email', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_ajax_referer('sb_update_booking', '_wpnonce');

    $booking_id = absint($_POST['booking_id'] ?? 0);
    if ($booking_id <= 0) {
        wp_send_json_error('Invalid booking ID');
    }

    $repo = new \SpaceBooking\Services\BookingRepository();
    $booking = $repo->find($booking_id);
    if (!$booking) {
        wp_send_json_error('Booking not found');
    }

    if ((string) ($booking['status'] ?? '') !== 'confirmed') {
        wp_send_json_error('Only confirmed bookings can resend confirmation email.');
    }

    $actor_user_id = get_current_user_id();
    $posted_feedback = sanitize_textarea_field($_POST['feedback'] ?? '');
    $first_confirmation_feedback = (string) $repo->get_meta($booking_id, '_sb_first_confirmation_feedback');
    $stored_feedback = (string) $repo->get_meta($booking_id, 'admin_feedback');
    $feedback_for_email = $posted_feedback !== ''
        ? $posted_feedback
        : ($first_confirmation_feedback !== '' ? $first_confirmation_feedback : $stored_feedback);

    if ($posted_feedback !== '') {
        $repo->save_meta($booking_id, 'admin_feedback', $posted_feedback);
    }

    $email_sent = sb_send_booking_confirmation_email($booking_id, $feedback_for_email);
    if (!$email_sent) {
        $repo->log_audit_event($booking_id, 'confirmation_email_failed', $actor_user_id, [
            'trigger' => 'manual_resend',
        ]);
        wp_send_json_error([
            'message' => 'Failed to resend confirmation email.',
            'email_failed' => true,
        ]);
        return;
    }

    $repo->log_audit_event($booking_id, 'confirmation_email_sent', $actor_user_id, [
        'trigger' => 'manual_resend',
        'result' => 'success',
    ]);

    wp_send_json_success([
        'email_sent' => true,
    ]);
});

add_action('wp_ajax_sb_booking_lifecycle_action', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_ajax_referer('sb_update_booking', '_wpnonce');

    $booking_id = absint($_POST['booking_id'] ?? 0);
    $lifecycle_action = sanitize_key($_POST['lifecycle_action'] ?? '');
    if ($booking_id <= 0 || !in_array($lifecycle_action, ['trash', 'restore', 'delete_permanently'], true)) {
        wp_send_json_error('Invalid input');
    }

    $repo = new \SpaceBooking\Services\BookingRepository();
    $booking = $repo->find($booking_id);
    if (!$booking) {
        wp_send_json_error('Booking not found');
    }

    $actor_user_id = get_current_user_id();
    $ok = false;
    if ($lifecycle_action === 'trash') {
        $ok = $repo->move_to_trash($booking_id, $actor_user_id);
    } elseif ($lifecycle_action === 'restore') {
        $ok = $repo->restore_from_trash($booking_id, $actor_user_id);
    } elseif ($lifecycle_action === 'delete_permanently') {
        $ok = $repo->delete_permanently($booking_id, $actor_user_id);
    }

    if (!$ok) {
        wp_send_json_error('Failed to apply lifecycle action');
    }

    wp_send_json_success([
        'booking_id' => $booking_id,
        'lifecycle_action' => $lifecycle_action,
    ]);
});

// Enqueue edit page scripts (only on booking edit page)
add_action('admin_enqueue_scripts', function ($hook) {
    $screen = get_current_screen();
    if ($screen->id === 'space-booking_page_space-booking-bookings' && isset($_GET['edit'])) {
        wp_enqueue_script('jquery');
    }
});
?>
