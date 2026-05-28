<?php
/** Debug: More detailed look at slots */
require_once __DIR__ . '/bootstrap.php';

$date = '2026-05-16';

global $wpdb;
$repo = new \SpaceBooking\Services\BookingRepository();
$avail = new \SpaceBooking\Services\AvailabilityService($repo);

$order_id = 997;

// Clean up first
$wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $order_id], ['%d']);

// Create test booking for space 223 - same status as before
$repo->create_booking_row([
    'space_id' => 223,
    'order_id' => $order_id,
    'booking_date' => $date,
    'start_time' => '18:30:00',
    'end_time' => '20:30:00',
    'status' => 'in_review'
]);

echo "=== Debug: Detailed Slot Info ===\n\n";

// Check direct blocking intervals
echo "Direct repo check:\n";
$blocking = $repo->get_blocking_intervals([223], $date);
echo '  Blocking for [223]: ' . count($blocking) . "\n";
foreach ($blocking as $b) {
    echo "    {$b['start']} to {$b['end']}\n";
}

// Now get slots via AvailabilityService with array
$slots = $avail->get_slots([223], $date, 60);
echo "\nVia AvailabilityService get_slots([223], ..., 60):\n";
echo '  has_fixed_slots: ' . ($slots['has_fixed_slots'] ?? 'missing') . "\n";
echo '  Total slots: ' . count($slots['slots']) . "\n";

foreach ($slots['slots'] as $idx => $slot) {
    echo "  Slot $idx: {$slot['start']} - {$slot['end']} => available=" . ($slot['available'] ? 'true' : 'false') . "\n";
}

// Cleanup
$wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $order_id], ['%d']);
echo "\nCleaned up.\n";
