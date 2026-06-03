<?php
/** Single Booking Edit Page */
defined('ABSPATH') || exit;

/**
 * Convert 24-hour time to 12-hour format with AM/PM
 */
function sb_format_time_12hour(string $time): string
{
    if (empty($time) || strlen($time) < 5)
        return $time;
    [$hourStr, $minuteStr] = explode(':', substr($time, 0, 5));
    $hour = (int) $hourStr;
    $minutes = $minuteStr;
    $period = $hour >= 12 ? 'PM' : 'AM';
    $hour = $hour % 12;
    if ($hour === 0)
        $hour = 12;
    return sprintf('%d:%s %s', $hour, $minutes, $period);
}

// Get booking ID from URL
$booking_id = absint($_GET['edit'] ?? 0);

if (!$booking_id) {
    wp_die('Invalid booking ID.');
}

global $wpdb;
$repo = new \SpaceBooking\Services\BookingRepository();

// Get enriched booking data (includes all linked spaces, extras, packages)
$booking = $repo->findEnriched($booking_id);

if (!$booking) {
    wp_die('Booking not found.');
}

// Use data from findEnriched - it already provides:
// - _selected_items: array of selected spaces/packages
// - _extras_details: array of extras with details
// - _price_breakdown: detailed price breakdown from meta
// - _space_titles: array of space titles

// Get linked spaces from _selected_items (filter for spaces only)
$linked_spaces = array_filter($booking['_selected_items'] ?? [], function($item) {
    return ($item['type'] ?? '') === 'sb_space';
});

// Get linked packages from _selected_items (filter for packages only)
$linked_packages = array_filter($booking['_selected_items'] ?? [], function($item) {
    return ($item['type'] ?? '') === 'sb_package';
});

// Get extras from _extras_details
$linked_extras = $booking['_extras_details'] ?? [];

// Get price breakdown
$price_breakdown = $booking['_price_breakdown'] ?? [];

// Get package inclusions from meta
$package_inclusions = $repo->get_meta($booking_id, '_sb_package_inclusions');
$inclusions = $package_inclusions ? json_decode($package_inclusions, true) : [];

// If no inclusions from meta, derive from linked packages
if (empty($inclusions) && !empty($linked_packages)) {
    $seen_titles = [];
    foreach ($linked_packages as $pkg) {
        $pkg_id = $pkg['id'] ?? 0;
        if ($pkg_id) {
            // Get package's included space
            $pkg_space_id = get_post_meta($pkg_id, '_sb_package_space_id', true);
            if ($pkg_space_id) {
                $space_title = get_the_title($pkg_space_id);
                if (!in_array($space_title, $seen_titles)) {
                    $seen_titles[] = $space_title;
                    $inclusions[] = [
                        'type' => 'sb_space',
                        'title' => $space_title,
                        'label' => $space_title . ' (Package Inclusion)'
                    ];
                }
            }
            // Get package's included extras
            $pkg_extra_ids = get_post_meta($pkg_id, '_sb_package_extra_ids', true);
            if (is_array($pkg_extra_ids)) {
                foreach ($pkg_extra_ids as $item) {
                    $extra_id = is_array($item) ? (int) ($item['extra_id'] ?? $item['id'] ?? 0) : (int) $item;
                    if ($extra_id > 0) {
                        $extra_title = get_the_title($extra_id);
                        if (!in_array($extra_title, $seen_titles)) {
                            $seen_titles[] = $extra_title;
                            $inclusions[] = [
                                'type' => 'sb_extra',
                                'title' => $extra_title,
                                'label' => $extra_title . ' (Package Inclusion)'
                            ];
                        }
                    }
                }
            }
        }
    }
}

// Deduplicate inclusions by title
if (!empty($inclusions)) {
    $seen_titles = [];
    $inclusions = array_values(array_filter($inclusions, function($inc) use (&$seen_titles) {
        $title = $inc['title'] ?? $inc['name'] ?? '';
        if (in_array($title, $seen_titles)) {
            return false;
        }
        $seen_titles[] = $title;
        return true;
    }));
}

// Resolve linked WooCommerce order if available.
$linked_order = null;
if (!empty($booking['order_id']) && function_exists('wc_get_order')) {
    $linked_order = wc_get_order((int) $booking['order_id']);
}
if (!$linked_order && function_exists('wc_get_orders')) {
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
    if (!empty($orders)) {
        $linked_order = $orders[0];
    }
}

// Canonical contact + order fields shown once in the booking editor.
$customer_name = trim((string) ($booking['customer_name'] ?? ''));
$customer_email = trim((string) ($booking['customer_email'] ?? ''));
$customer_phone = trim((string) ($booking['customer_phone'] ?? ''));
$customer_notes = trim((string) ($booking['notes'] ?? ''));
$checkout_additional_notes = '';
$marketing_source = trim((string) ($repo->get_meta($booking_id, '_sb_marketing_source') ?? ''));
$package_question_answers_json = (string) $repo->get_meta($booking_id, '_sb_package_question_answers');
if ($package_question_answers_json === '' && isset($booking['_meta_data']['_sb_package_question_answers'])) {
    $package_question_answers_json = (string) $booking['_meta_data']['_sb_package_question_answers'];
}
$package_answer_rows = \SpaceBooking\Services\EmailTemplateHelper::package_question_rows_from_meta_string(
    $package_question_answers_json
);
$package_answers_for_pricing = [];
if ($package_question_answers_json !== '') {
    $decoded_package_answers = json_decode($package_question_answers_json, true);
    if (is_array($decoded_package_answers)) {
        $package_answers_for_pricing = $decoded_package_answers;
    }
}

if ($linked_order) {
    $order_name = trim((string) $linked_order->get_formatted_billing_full_name());
    $order_email = trim((string) $linked_order->get_billing_email());
    $order_phone = trim((string) $linked_order->get_billing_phone());
    if ($customer_name === '') {
        $customer_name = $order_name;
    }
    if ($customer_email === '') {
        $customer_email = $order_email;
    }
    if ($customer_phone === '') {
        $customer_phone = $order_phone;
    }
    $checkout_additional_notes = trim((string) $linked_order->get_customer_note());
}
$notes_are_duplicate = ($customer_notes !== '' && $checkout_additional_notes !== '')
    && mb_strtolower(trim($customer_notes)) === mb_strtolower(trim($checkout_additional_notes));

$order_number = $linked_order ? (string) $linked_order->get_order_number() : '';
$order_status = $linked_order ? wc_get_order_status_name($linked_order->get_status()) : '';
$order_payment_method = $linked_order ? (string) $linked_order->get_payment_method_title() : '';
$order_admin_link = $linked_order ? admin_url('post.php?post=' . $linked_order->get_id() . '&action=edit') : '';
$format_money = static function ($amount): string {
    $value = (float) $amount;
    return \SpaceBooking\Services\CurrencyService::format($value);
};

$spaces_subtotal = 0.0;
$packages_subtotal = 0.0;
$extras_subtotal = 0.0;
$pricing_source = 'booking';
$itemized_breakdown = [];
$extras_breakdown = [];

// Recompute from canonical pricing service so subtotals and line breakdown match booking logic.
$selected_item_ids = array_map(static function ($item) {
    return (int) ($item['id'] ?? 0);
}, $booking['_selected_items'] ?? []);
$selected_item_ids = array_values(array_filter($selected_item_ids));
$package_ids = array_map(static function ($item) {
    return (int) ($item['id'] ?? 0);
}, $linked_packages);
$package_ids = array_values(array_filter($package_ids));
$extras_for_pricing = array_map(static function ($extra) {
    return [
        'extra_id' => (int) ($extra['extra_id'] ?? 0),
        'quantity' => max(1, (int) ($extra['quantity'] ?? 1)),
    ];
}, $linked_extras);
$extras_for_pricing = array_values(array_filter($extras_for_pricing, static function ($extra) {
    return $extra['extra_id'] > 0;
}));

if (!empty($selected_item_ids)) {
    $pricing_service = new \SpaceBooking\Services\PricingService();
    $computed = $pricing_service->calculate(
        null,
        (string) ($booking['booking_date'] ?? ''),
        (string) ($booking['start_time'] ?? ''),
        (string) ($booking['end_time'] ?? ''),
        $extras_for_pricing,
        $selected_item_ids,
        $package_ids,
        $package_answers_for_pricing,
        null
    );

    foreach (($computed['items'] ?? []) as $item) {
        $item_type = (string) ($item['type'] ?? '');
        $item_subtotal = (float) ($item['subtotal'] ?? 0);
        if ($item_type === 'sb_package') {
            $packages_subtotal += $item_subtotal;
        } elseif ($item_type === 'sb_space') {
            $spaces_subtotal += $item_subtotal;
        }
        $itemized_breakdown[] = $item;
    }
    $extras_subtotal = (float) ($computed['extras_price'] ?? 0);
    $extras_breakdown = $computed['extras_breakdown'] ?? [];
    $price_breakdown = $computed['breakdown'] ?? $price_breakdown;
} else {
    // Legacy fallback if selected items are not available.
    $spaces_subtotal = (float) ($booking['base_price'] ?? 0);
    $extras_subtotal = (float) ($booking['extras_price'] ?? 0);
    $packages_subtotal = !empty($linked_packages) ? max(0, (float) ($booking['modifier_price'] ?? 0)) : 0.0;
}
$calculated_total = $spaces_subtotal + $packages_subtotal + $extras_subtotal;
$display_total = $linked_order ? (float) $linked_order->get_total() : (float) ($booking['total_price'] ?? $calculated_total);

// Status options
$statuses = ['pending' => 'Pending', 'in_review' => 'In Review', 'confirmed' => 'Confirmed', 'trashed' => 'Trashed'];
$status_color = [
    'pending' => '#fff3cd',
    'in_review' => '#cce5ff',
    'confirmed' => '#d4edda',
    'trashed' => '#f8d7da'
];
$audit_log_entries = $repo->get_audit_log($booking_id);
if (!empty($audit_log_entries)) {
    usort($audit_log_entries, static function ($a, $b) {
        return strcmp((string) ($b['timestamp_gmt'] ?? ''), (string) ($a['timestamp_gmt'] ?? ''));
    });
}
?>
<style>
.sb-booking-edit {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto;
    max-width: 1300px;
    margin: 20px 0;
}
.sb-edit-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    gap: 20px;
    align-items: start;
}
.sb-side-column {
    position: sticky;
    top: 20px;
}

.sb-section {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 6px;
    margin-bottom: 24px;
    padding: 24px;
}

.sb-section h3 {
    margin: 0 0 16px;
    color: #1d2327;
    border-bottom: 2px solid #7A48B0;
    padding-bottom: 8px;
}

.sb-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin: 16px 0;
}

.sb-info-row strong {
    color: #1d2327;
}

.sb-price-breakdown {
    background: #f6f7f7;
    padding: 16px;
    border-radius: 4px;
}

.sb-extras-list {
    list-style: none;
    padding: 0;
}

.sb-extra-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}

.sb-status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 500;
    font-size: 13px;
    text-transform: uppercase;
}

.sb-edit-form {
    background: #f9fbfe;
    padding: 20px;
    border-radius: 6px;
}

.sb-form-row {
    display: flex;
    gap: 16px;
    align-items: end;
    margin-bottom: 16px;
}

.sb-form-group {
    flex: 1;
}

.sb-form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
}

.sb-form-group select,
.sb-form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #8c8f94;
    border-radius: 4px;
    font-size: 14px;
}

.sb-actions {
    display: flex;
    gap: 12px;
}

.btn {
    padding: 10px 20px;
    border: 0;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 500;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: #7A48B0;
    color: #fff;
}

.btn-primary:hover {
    background: #5e3585;
}

.btn-secondary {
    background: #646970;
    color: #fff;
}

.btn-secondary:hover {
    background: #50565b;
}

.btn-danger {
    background: #b32d2e;
    color: #fff;
}

.btn-danger:hover {
    background: #8f2424;
}

#sb-status-preview {
    min-height: 40px;
    padding: 10px;
    background: #f0f0f1;
    border-radius: 4px;
    margin-top: 8px;
}

.loading {
    opacity: 0.6;
    pointer-events: none;
}

.success {
    color: #00a32a;
    background: #d4edda;
}

.error {
    color: #d63638;
    background: #f4acb7;
}

.toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 16px 20px;
    border-radius: 4px;
    color: #fff;
    z-index: 9999;
    transform: translateX(400px);
    transition: transform .3s;
}

@media (max-width:768px) {
    .sb-edit-layout {
        grid-template-columns: 1fr;
    }

    .sb-side-column {
        position: static;
    }

    .sb-info-grid {
        grid-template-columns: 1fr;
    }

    .sb-form-row {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<div class="sb-booking-edit">
    <div style="display:flex; align-items:center; gap:16px; margin-bottom:24px;">
        <a href="<?php echo esc_url(remove_query_arg('edit')); ?>" class="btn btn-secondary">← Back to Bookings</a>
        <h1>Edit Booking #<?php echo esc_html($booking['id']); ?></h1>
    </div>

    <div class="sb-edit-layout">
    <div class="sb-main-column">
    <div class="sb-section">
        <h3>📅 Booking Details</h3>
        <div class="sb-info-grid">
            <?php if (!empty($linked_spaces)): ?>
            <div style="grid-column: 1 / -1;">
                <strong>Spaces (<?php echo count($linked_spaces); ?>):</strong>
                <ul class="sb-extras-list" style="margin-top:8px;">
                    <?php foreach ($linked_spaces as $ls): ?>
                    <li class="sb-extra-item">
                        <span>📍 <?php echo esc_html($ls['title']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php else: ?>
            <div><strong>Space:</strong> <?php echo esc_html($booking['space_title'] ?? 'N/A'); ?></div>
            <?php endif; ?>

            <?php if (!empty($linked_packages)): ?>
            <div style="grid-column: 1 / -1;">
                <strong>📦 Packages (<?php echo count($linked_packages); ?>):</strong>
                <ul class="sb-extras-list" style="margin-top:8px;">
                    <?php foreach ($linked_packages as $pkg): ?>
                    <li class="sb-extra-item">
                        <span>🎁 <?php echo esc_html($pkg['title']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <?php if (!empty($package_answer_rows)): ?>
            <div style="grid-column: 1 / -1;">
                <strong>Package Answers:</strong>
                <ul class="sb-extras-list" style="margin-top:8px;">
                    <?php foreach ($package_answer_rows as $row): ?>
                    <li class="sb-extra-item" style="display:block;">
                        <div><strong><?php echo esc_html($row['label']); ?></strong></div>
                        <div><?php echo esc_html($row['value']); ?></div>
                        <?php if ($row['others_text'] !== ''): ?>
                        <div style="margin-top:4px;color:#50575e;">
                            <em>Others explanation:</em> <?php echo esc_html($row['others_text']); ?>
                        </div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div><strong>Date:</strong> <?php echo esc_html($booking['booking_date']); ?></div>
            <div><strong>Time:</strong>
                <?php echo esc_html(sb_format_time_12hour($booking['start_time']) . ' - ' . sb_format_time_12hour($booking['end_time'])); ?>
            </div>
            <div><strong>Duration:</strong> <?php echo esc_html($booking['duration_hours']); ?>h</div>
        </div>
    </div>

    <div class="sb-section">
        <h3>👤 Customer & Order</h3>
        <div class="sb-info-grid">
            <div><strong>Name:</strong> <?php echo esc_html($customer_name ?: 'N/A'); ?></div>
            <div><strong>Email:</strong> <?php echo esc_html($customer_email ?: 'N/A'); ?></div>
            <div><strong>Phone:</strong> <?php echo esc_html($customer_phone ?: 'N/A'); ?></div>
            <div><strong>Booking ID:</strong> #<?php echo esc_html($booking['id']); ?></div>
            <div>
                <strong>WooCommerce Order:</strong>
                <?php if ($linked_order): ?>
                <a
                    href="<?php echo esc_url($order_admin_link); ?>">#<?php echo esc_html($order_number ?: $linked_order->get_id()); ?></a>
                <?php else: ?>
                N/A
                <?php endif; ?>
            </div>
            <div><strong>Order Status:</strong> <?php echo esc_html($order_status ?: 'N/A'); ?></div>
            <?php if ($order_payment_method): ?>
            <div><strong>Payment Method:</strong> <?php echo esc_html($order_payment_method); ?></div>
            <?php endif; ?>
            <?php if ($linked_order): ?>
            <div><strong>Order Date:</strong>
                <?php echo esc_html($linked_order->get_date_created() ? $linked_order->get_date_created()->date_i18n('Y-m-d H:i') : 'N/A'); ?>
            </div>
            <div><strong>Transaction ID:</strong> <?php echo esc_html($linked_order->get_transaction_id() ?: 'N/A'); ?>
            </div>
            <div><strong>Customer ID:</strong>
                <?php echo esc_html($linked_order->get_customer_id() ? '#' . $linked_order->get_customer_id() : 'Guest'); ?>
            </div>
            <div><strong>Payment Status:</strong> <?php echo esc_html($linked_order->is_paid() ? 'Paid' : 'Unpaid'); ?>
            </div>
            <?php endif; ?>
            <?php if ($customer_notes !== ''): ?>
            <div style="grid-column: 1 / -1;"><strong>Booking Notes:</strong> <?php echo esc_html($customer_notes); ?></div>
            <?php endif; ?>
            <?php if ($checkout_additional_notes !== '' && !$notes_are_duplicate): ?>
            <div style="grid-column: 1 / -1;"><strong>WooCommerce Additional Notes:</strong> <?php echo esc_html($checkout_additional_notes); ?></div>
            <?php endif; ?>
            <?php if ($marketing_source !== ''): ?>
            <div><strong>📈 How did you hear about us?</strong> <?php echo esc_html($marketing_source); ?></div>
            <?php endif; ?>
            <?php if ($linked_order): ?>
            <div style="grid-column: 1 / -1;">
                <strong>Billing Details:</strong><br>
                <?php echo wp_kses_post($linked_order->get_formatted_billing_address() ?: 'N/A'); ?><br>
                <span><strong>Company:</strong>
                    <?php echo esc_html($linked_order->get_billing_company() ?: 'N/A'); ?></span><br>
                <span><strong>Email:</strong>
                    <?php echo esc_html($linked_order->get_billing_email() ?: 'N/A'); ?></span><br>
                <span><strong>Phone:</strong>
                    <?php echo esc_html($linked_order->get_billing_phone() ?: 'N/A'); ?></span>
            </div>
            <div style="grid-column: 1 / -1;">
                <strong>Shipping Details:</strong><br>
                <?php echo wp_kses_post($linked_order->get_formatted_shipping_address() ?: 'N/A'); ?><br>
                <span><strong>Recipient:</strong>
                    <?php echo esc_html(trim($linked_order->get_shipping_first_name() . ' ' . $linked_order->get_shipping_last_name()) ?: 'N/A'); ?></span><br>
                <span><strong>Company:</strong>
                    <?php echo esc_html($linked_order->get_shipping_company() ?: 'N/A'); ?></span><br>
                <span><strong>Phone:</strong>
                    <?php echo esc_html(method_exists($linked_order, 'get_shipping_phone') ? ($linked_order->get_shipping_phone() ?: 'N/A') : 'N/A'); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>


    <div class="sb-section">
        <h3>💰 Pricing</h3>
        <div class="sb-price-breakdown">
            <?php if (!empty($inclusions)): ?>
            <div style="margin-bottom:16px; background:#f3e8ff; padding:12px; border-radius:4px;">
                <strong>✅ Package Inclusions:</strong>
                <ul class="sb-extras-list" style="margin-top:8px;">
                    <?php foreach ($inclusions as $inc): ?>
                    <?php $inc_name = $inc['name'] ?? $inc['title'] ?? ''; ?>
                    <?php if (!empty($inc_name)): ?>
                    <li class="sb-extra-item">
                        <span>✓ <?php echo esc_html($inc_name); ?></span>
                        <span>Included</span>
                    </li>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>


            <?php if (!empty($price_breakdown)): ?>
            <div style="margin-bottom:16px;">
                <strong>Detailed Breakdown:</strong>
                <ul class="sb-extras-list" style="margin-top:8px;">
                    <?php foreach ($price_breakdown as $item): ?>
                    <li class="sb-extra-item">
                        <span><?php echo esc_html($item['label'] ?? $item['name'] ?? 'Line Item'); ?></span>
                        <span><?php echo $format_money($item['amount'] ?? $item['price'] ?? 0); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div style="margin-bottom:16px;">
                <strong>Subtotal by Type (<?php echo esc_html(ucfirst($pricing_source)); ?>):</strong>
                <ul class="sb-extras-list" style="margin-top:8px;">
                    <li class="sb-extra-item"><span>Spaces
                            Subtotal</span><span><?php echo $format_money($spaces_subtotal); ?></span></li>
                    <li class="sb-extra-item"><span>Packages
                            Subtotal</span><span><?php echo $format_money($packages_subtotal); ?></span></li>
                    <li class="sb-extra-item"><span>Extras
                            Subtotal</span><span><?php echo $format_money($extras_subtotal); ?></span></li>
                    <li class="sb-extra-item"><span>Calculated
                            Subtotal</span><span><?php echo $format_money($calculated_total); ?></span></li>
                    <?php if ($linked_order): ?>
                    <li class="sb-extra-item">
                        <span>Discount</span><span>-<?php echo $format_money($linked_order->get_discount_total()); ?></span>
                    </li>
                    <li class="sb-extra-item">
                        <span>Shipping</span><span><?php echo $format_money($linked_order->get_shipping_total()); ?></span>
                    </li>
                    <li class="sb-extra-item">
                        <span>Tax</span><span><?php echo $format_money($linked_order->get_total_tax()); ?></span>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <div
                style="border-top:2px solid #7A48B0; padding-top:12px; font-weight:bold; font-size:18px; color:#7A48B0;">
                <span>Total: <?php echo $format_money($display_total); ?></span>
            </div>
        </div>
    </div>

    <div class="sb-section">
        <h3>⚙️ Update Status</h3>
        <form id="sb-edit-form" class="sb-edit-form">
            <div class="sb-form-row">
                <div class="sb-form-group">
                    <label for="status">Status <span style="color:#d63638;">*</span></label>
                    <select id="status" name="status" required>
                        <?php foreach ($statuses as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($booking['status'], $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="sb-status-preview"
                        style="background:<?php echo $status_color[$booking['status']] ?? '#eee'; ?>;">
                        Current: <span class="sb-status-badge"
                            style="background:<?php echo $status_color[$booking['status']] ?? '#ccc'; ?>; color:#155724;"><?php echo esc_html(ucfirst($booking['status'])); ?></span>
                    </div>
                </div>
                <div class="sb-form-group">
                    <label for="feedback">Admin Feedback (optional)</label>
                    <textarea id="feedback" name="feedback" rows="3"
                        placeholder="Add notes about this status change..."><?php echo esc_textarea($booking['admin_feedback'] ?? ''); ?></textarea>
                </div>
            </div>
            <div class="sb-actions">
                <button type="submit" class="btn btn-primary">💾 Update Booking</button>
                <button type="button" class="btn btn-secondary" onclick="history.back()">Cancel</button>
                <?php if (($booking['status'] ?? '') === 'confirmed'): ?>
                <button type="button" class="btn btn-secondary" id="sb-resend-email-btn">Resend Confirmation Email</button>
                <?php endif; ?>
            </div>
        </form>
        <hr style="margin:18px 0;">
        <div class="sb-actions">
            <?php if (($booking['status'] ?? '') !== 'trashed'): ?>
            <button type="button" class="btn btn-secondary sb-lifecycle-btn" data-action="trash">Move to Trash</button>
            <?php else: ?>
            <button type="button" class="btn btn-secondary sb-lifecycle-btn" data-action="restore">Restore</button>
            <?php endif; ?>
            <button type="button" class="btn btn-danger sb-lifecycle-btn" data-action="delete_permanently">Delete Permanently</button>
        </div>
    </div>
    </div>
    <aside class="sb-side-column">
        <div class="sb-section">
            <h3>Transaction Log</h3>
            <?php if (empty($audit_log_entries)): ?>
            <p style="margin:0;color:#646970;">No transactions recorded yet.</p>
            <?php else: ?>
            <ul class="sb-extras-list" style="margin:0;">
                <?php foreach ($audit_log_entries as $entry): ?>
                <?php
                $event = sanitize_key((string) ($entry['event'] ?? ''));
                $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
                $actor_user_id = (int) ($entry['actor_user_id'] ?? 0);
                $actor_user = $actor_user_id > 0 ? get_userdata($actor_user_id) : null;
                $actor_name = $actor_user ? (string) $actor_user->display_name : ($actor_user_id > 0 ? ('User #' . $actor_user_id) : 'System');
                $timestamp_gmt = (string) ($entry['timestamp_gmt'] ?? '');
                $timestamp_local = $timestamp_gmt !== '' ? get_date_from_gmt($timestamp_gmt, 'Y-m-d H:i:s') : '';
                $event_label = 'Activity';
                if ($event === 'status_changed') {
                    $event_label = 'Status changed';
                } elseif ($event === 'confirmation_email_sent') {
                    $event_label = 'Confirmation email sent';
                } elseif ($event === 'confirmation_email_failed') {
                    $event_label = 'Confirmation email failed';
                } elseif ($event === 'booking_trashed') {
                    $event_label = 'Moved to trash';
                } elseif ($event === 'booking_restored') {
                    $event_label = 'Restored from trash';
                } elseif ($event === 'booking_delete_permanently') {
                    $event_label = 'Deleted permanently';
                }
                ?>
                <li class="sb-extra-item" style="display:block;">
                    <div><strong><?php echo esc_html($event_label); ?></strong></div>
                    <?php if ($event === 'status_changed'): ?>
                    <div style="margin-top:4px;color:#50575e;">
                        <?php echo esc_html((string) ($context['from_status'] ?? '')); ?> -> <?php echo esc_html((string) ($context['to_status'] ?? '')); ?>
                    </div>
                    <?php endif; ?>
                    <div style="margin-top:4px;color:#50575e;">
                        <?php echo esc_html($actor_name); ?><?php echo $timestamp_local !== '' ? ' · ' . esc_html($timestamp_local) : ''; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </aside>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    const form = $('#sb-edit-form');

    // Form submit
    form.on('submit', function(e) {
        e.preventDefault();
        const btn = form.find('.btn-primary').prop('disabled', true).text('Saving...');
        const newStatus = $('#status').val();
        form.addClass('loading');

        $.post(ajaxurl, {
            action: 'sb_update_booking_status',
            booking_id: <?php echo $booking_id; ?>,
            status: newStatus,
            feedback: $('#feedback').val(),
            _wpnonce: '<?php echo wp_create_nonce('sb_update_booking'); ?>'
        }, function(res) {
            if (res.success) {
                // Check if email was sent (for confirmed status)
                if (newStatus === 'confirmed' && res.data.email_sent) {
                    showToast('✅ Booking confirmed and email sent to customer!', 'success');
                } else {
                    showToast('✅ Booking updated successfully!', 'success');
                }
                $('#feedback').val(res.data.feedback || '');
                window.setTimeout(() => window.location.reload(), 700);
            } else {
                // Check for email failure
                const errorData = res.data;
                if (errorData && errorData.email_failed) {
                    showToast('❌ ' + (errorData.message || 'Failed to send confirmation email'),
                        'error');
                } else {
                    showToast('❌ ' + (errorData || 'Update failed'), 'error');
                }
            }
            btn.prop('disabled', false).text('💾 Update Booking');
            form.removeClass('loading');
        }).fail(function() {
            showToast('❌ Network error. Please try again.', 'error');
            btn.prop('disabled', false).text('💾 Update Booking');
            form.removeClass('loading');
        });
    });

    function showToast(msg, type = '') {
        const toast = $(`<div class="toast ${type}">${msg}</div>`).appendTo('body');
        toast.css('transform', 'translateX(0)');
        setTimeout(() => toast.remove(), 4000);
    }

    $('#sb-resend-email-btn').on('click', function() {
        const button = $(this).prop('disabled', true).text('Sending...');
        $.post(ajaxurl, {
            action: 'sb_resend_booking_confirmation_email',
            booking_id: <?php echo $booking_id; ?>,
            feedback: $('#feedback').val(),
            _wpnonce: '<?php echo wp_create_nonce('sb_update_booking'); ?>'
        }, function(res) {
            if (res.success) {
                showToast('âœ… Confirmation email resent successfully.', 'success');
                window.setTimeout(() => window.location.reload(), 700);
                return;
            }
            showToast('âŒ ' + ((res.data && res.data.message) ? res.data.message : (res.data || 'Resend failed')), 'error');
            button.prop('disabled', false).text('Resend Confirmation Email');
        }).fail(function() {
            showToast('âŒ Network error. Please try again.', 'error');
            button.prop('disabled', false).text('Resend Confirmation Email');
        });
    });

    $(document).on('click', '.sb-lifecycle-btn', function() {
        const lifecycleAction = $(this).data('action');
        const labels = {
            trash: 'move this booking to trash',
            restore: 'restore this booking from trash',
            delete_permanently: 'permanently delete this booking'
        };
        if (!window.confirm('Confirm ' + labels[lifecycleAction] + '?')) {
            return;
        }

        const button = $(this).prop('disabled', true);
        $.post(ajaxurl, {
            action: 'sb_booking_lifecycle_action',
            booking_id: <?php echo $booking_id; ?>,
            lifecycle_action: lifecycleAction,
            _wpnonce: '<?php echo wp_create_nonce('sb_update_booking'); ?>'
        }, function(res) {
            if (res.success) {
                if (lifecycleAction === 'delete_permanently') {
                    window.location.href = '<?php echo esc_url(admin_url('admin.php?page=space-booking-bookings')); ?>';
                    return;
                }
                showToast('Lifecycle action applied successfully.', 'success');
                window.location.reload();
                return;
            }
            showToast('Lifecycle action failed: ' + (res.data || 'Unknown error'), 'error');
            button.prop('disabled', false);
        }).fail(function() {
            showToast('Network error while applying lifecycle action.', 'error');
            button.prop('disabled', false);
        });
    });
});
</script>
</div>
