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
$marketing_source = trim((string) ($repo->get_meta($booking_id, '_sb_marketing_source') ?? ''));

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
    if ($customer_notes === '') {
        $customer_notes = trim((string) $linked_order->get_customer_note());
    }
}

$order_number = $linked_order ? (string) $linked_order->get_order_number() : '';
$order_status = $linked_order ? wc_get_order_status_name($linked_order->get_status()) : '';
$order_payment_method = $linked_order ? (string) $linked_order->get_payment_method_title() : '';
$order_admin_link = $linked_order ? admin_url('post.php?post=' . $linked_order->get_id() . '&action=edit') : '';
$format_money = static function ($amount): string {
    $value = (float) $amount;
    if (function_exists('wc_price')) {
        return wp_kses_post(wc_price($value));
    }
    return '$' . number_format($value, 2);
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
$statuses = ['pending' => 'Pending', 'in_review' => 'In Review', 'confirmed' => 'Confirmed'];
$status_color = [
    'pending' => '#fff3cd',
    'in_review' => '#cce5ff',
    'confirmed' => '#d4edda'
];
?>
<style>
.sb-booking-edit {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto;
    max-width: 900px;
    margin: 20px 0;
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
            <div style="grid-column: 1 / -1;"><strong>Notes:</strong> <?php echo esc_html($customer_notes); ?></div>
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
            </div>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    const form = $('#sb-edit-form');
    const preview = $('#sb-status-preview');
    const statuses = {
        'pending': '#fff3cd',
        'in_review': '#cce5ff',
        'confirmed': '#d4edda'
    };

    // Live status preview
    $('#status').on('change', function() {
        const status = $(this).val();
        const badge = $('.sb-status-badge', preview);
        preview.css('background', statuses[status]);
        badge.text(status.charAt(0).toUpperCase() + status.slice(1))
            .css('background', statuses[status])
            .css('color', '#155724');
    });

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
                // Update preview to match saved status
                $('#status').val(res.data.status).trigger('change');
                $('#feedback').val(res.data.feedback || '');
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
});
</script>
</div>