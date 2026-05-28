<?php

/**
 * Public booking confirmation page template.
 * Used after WooCommerce payment success.
 */
if (!defined('ABSPATH'))
    exit;

// Debug log
error_log('SpaceBooking Confirmation Page Loaded: id=' . ($_GET['id'] ?? 'none') . ' status=' . ($_GET['status'] ?? 'none'));

global $wp_query;
$booking_id = intval(get_query_var('booking_id') ?? $_GET['id'] ?? 0);
$status = sanitize_text_field(get_query_var('status') ?? $_GET['status'] ?? '');
error_log('Parsed: booking_id=' . $booking_id . ' status=' . $status);

// Fetch booking if provided
$booking = null;
if ($booking_id) {
    $repo = new \SpaceBooking\Services\BookingRepository();
    $booking = $repo->find($booking_id);
    if ($booking) {
        wp_localize_script('space-booking-confirmation', 'sbConfirmationData', [
            'bookingId' => $booking_id,
            'status' => $booking['status'],
            'spaceId' => $booking['space_id'],
        ]);
    }
}

get_header();
?>
<div id="sb-confirmation-app" data-booking-id="<?= esc_attr($booking_id) ?>" data-status="<?= esc_attr($status) ?>">
    <div class="sb-confirmation-loading">
        <p>Checking booking status...</p>
    </div>
</div>

<script type="module">
window.sbConfig = window.sbConfig || {};
// Load confirmation-main.tsx
import(window.sbConfig.viteBase + '/src/confirmation-main.tsx?' + Date.now())
    .catch(() => import('/wp-content/plugins/space-booking/assets/js/booking-app.js'))
    .then(() => {
        // confirmation-main.tsx auto-mounts to #sb-confirmation-app
    });
</script>

<?php if ($booking): ?>
<style>
.sb-confirmation-success {
    background: #d4edda;
    padding: 20px;
    border-radius: 8px;
}
</style>
<div class="sb-confirmation-success">
    <h2>Booking #<?= esc_html($booking_id) ?> Confirmed!</h2>
    <p>Space: <?= esc_html(get_the_title($booking['space_id'])) ?></p>
    <p>Date: <?= esc_html($booking['booking_date']) ?> | <?= date('g:i A', strtotime($booking['start_time'])) ?> –
        <?= date('g:i A', strtotime($booking['end_time'])) ?> | <?= $booking['duration_hours'] ?> hours</p>
    <?php if (!empty($booking['extras'])): ?>
    <p>Extras: <?= implode(', ', array_column($booking['extras'], 'extra_name')) ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php get_footer(); ?>