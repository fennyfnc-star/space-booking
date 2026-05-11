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
$booking = $wpdb->get_row($wpdb->prepare(
    "SELECT b.*, p.post_title as space_title 
     FROM {$wpdb->prefix}sb_bookings b 
     LEFT JOIN {$wpdb->posts} p ON p.ID = b.space_id 
     WHERE b.id = %d",
    $booking_id
), ARRAY_A);

if (!$booking) {
    wp_die('Booking not found.');
}

// Fetch extras
$extras = $wpdb->get_results($wpdb->prepare(
    "SELECT be.*, p.post_title as extra_name, pm.meta_value as extra_price 
     FROM {$wpdb->prefix}sb_booking_extras be 
     JOIN {$wpdb->posts} p ON p.ID = be.extra_id 
     LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = be.extra_id AND pm.meta_key = '_sb_extra_price' 
     WHERE be.booking_id = %d",
    $booking_id
), ARRAY_A) ?: [];

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
    border-bottom: 2px solid #2271b1;
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
    background: #2271b1;
    color: #fff;
}

.btn-primary:hover {
    background: #135e96;
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
            <div><strong>Space:</strong> <?php echo esc_html($booking['space_title']); ?></div>
            <div><strong>Date:</strong> <?php echo esc_html($booking['booking_date']); ?></div>
            <div><strong>Time:</strong>
                <?php echo esc_html(sb_format_time_12hour($booking['start_time']) . ' - ' . sb_format_time_12hour($booking['end_time'])); ?>
            </div>
            <div><strong>Duration:</strong> <?php echo esc_html($booking['duration_hours']); ?>h</div>
            <div><strong>Customer:</strong> <?php echo esc_html($booking['customer_name']); ?></div>
            <div><strong>Email:</strong> <?php echo esc_html($booking['customer_email']); ?></div>
            <?php if ($booking['customer_phone']): ?><div><strong>Phone:</strong>
                <?php echo esc_html($booking['customer_phone']); ?></div><?php endif; ?>
            <?php if ($booking['notes']): ?><div><strong>Notes:</strong> <?php echo esc_html($booking['notes']); ?>
            </div>
            <?php endif; ?>
            <?php
            $repo = new \SpaceBooking\Services\BookingRepository();
            $marketing = $repo->get_meta($booking_id, '_sb_marketing_source');
            if ($marketing):
                ?>
            <div><strong>📈 How did you hear about us?</strong> <?php echo esc_html($marketing); ?></div>
            <?php endif; ?>
        </div>
    </div>


    <div class="sb-section">
        <h3>💰 Pricing</h3>
        <div class="sb-price-breakdown">
            <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                <span>Base: $<?php echo number_format($booking['base_price'], 2); ?></span>
            </div>
            <?php if ($booking['extras_price'] > 0): ?>
            <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                <span>Extras: $<?php echo number_format($booking['extras_price'], 2); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($booking['modifier_price'] != 0): ?>
            <div style="display:flex; justify-content:space-between;">
                <span>Modifiers: $<?php echo number_format($booking['modifier_price'], 2); ?></span>
            </div>
            <?php endif; ?>
            <div style="border-top:2px solid #2271b1; padding-top:12px; font-weight:bold; font-size:18px;">
                <span>Total: $<?php echo number_format($booking['total_price'], 2); ?></span>
            </div>
        </div>
    </div>

    <?php if (!empty($extras)): ?>
    <div class="sb-section">
        <h3>➕ Extras (<?php echo count($extras); ?>)</h3>
        <ul class="sb-extras-list">
            <?php foreach ($extras as $extra): ?>
            <li class="sb-extra-item">
                <span><?php echo esc_html($extra['extra_name']); ?> ×<?php echo $extra['quantity']; ?></span>
                <span>$<?php echo number_format($extra['quantity'] * $extra['unit_price'], 2); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

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
        form.addClass('loading');

        $.post(ajaxurl, {
            action: 'sb_update_booking_status',
            booking_id: <?php echo $booking_id; ?>,
            status: $('#status').val(),
            feedback: $('#feedback').val(),
            _wpnonce: '<?php echo wp_create_nonce('sb_update_booking'); ?>'
        }, function(res) {
            if (res.success) {
                showToast('✅ Booking updated successfully!', 'success');
                // Update preview to match saved status
                $('#status').val(res.data.status).trigger('change');
                $('#feedback').val(res.data.feedback || '');
            } else {
                showToast('❌ ' + (res.data || 'Update failed'), 'error');
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