<?php
/** Debug: Trace what AvailabilityService does with arrays */
require_once __DIR__ . '/bootstrap.php';

$date = '2026-05-16';

global $wpdb;
$repo = new \SpaceBooking\Services\BookingRepository();
$avail = new \SpaceBooking\Services\AvailabilityService($repo);

$order_id = 998;

// Clean up first
$wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $order_id], ['%d']);

// Create test booking for space 223
$repo->create_booking_row([
    'space_id' => 223,
    'order_id' => $order_id,
    'booking_date' => $date,
    'start_time' => '18:30:00',
    'end_time' => '20:30:00',
    'status' => 'in_review'
]);

echo "=== Debug: Array Processing in AvailabilityService ===\n\n";

// Test 1: Single integer (old way)
echo "Test 1: get_slots(223, \$date, 60) - single int\n";
$slots1 = $avail->get_slots(223, $date, 60);
echo '  18:30 available: ' . ($slots1['slots'][1]['available'] ?? 'not found') . "\n\n";

// Test 2: Array with one element (new way)
echo "Test 2: get_slots([223], \$date, 60) - array\n";
$slots2 = $avail->get_slots([223], $date, 60);
echo '  18:30 available: ' . ($slots2['slots'][1]['available'] ?? 'not found') . "\n\n";

// Test what primary_id is calculated inside get_slots
echo "=== Tracing internal processing ===\n";
$test_ids = [223];
$primary = $test_ids[array_key_first($test_ids)] ?? $test_ids[0] ?? 0;
echo "Input: [223]\n";
echo 'array_key_first(): ' . array_key_first($test_ids) . "\n";
echo "primary_id calculated: $primary\n";

// Test with array wrapped
$test_ids2 = [223];
$primary2 = $test_ids2[array_key_first($test_ids2)] ?? $test_ids2[0] ?? 0;
echo "\nInput: [223] (reassigned)\n";
echo "primary_id calculated: $primary2\n";

// Cleanup
$wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $order_id], ['%d']);
echo "\nCleaned up.\n";
