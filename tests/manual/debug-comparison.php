<?php
/**
 * Combined debug test to compare both scenarios
 */
require_once __DIR__ . '/bootstrap.php';

echo "=== DEBUG: Compare Both Test Scenarios ===\n\n";

global $wpdb;
$repo = new \SpaceBooking\Services\BookingRepository();
$avail = new \SpaceBooking\Services\AvailabilityService($repo);

$date = '2026-05-16';
$slot = ['start' => '18:30:00', 'end' => '20:30:00'];
$space_id = 223;
$order_id = 166;  // Same as TestMultiSpaceCollision

echo "=== SCENARIO 1: Single Direct Call (like debug-avail-service.php) ===\n";

// Clean first
$wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $order_id], ['%d']);

// Create single booking
$repo->create_booking_row([
    'space_id' => $space_id,
    'order_id' => $order_id,
    'booking_date' => $date,
    'start_time' => $slot['start'],
    'end_time' => $slot['end'],
    'status' => 'in_review'
]);
echo "Created booking id=" . $wpdb->insert_id . "\n";

// Check the booking in DB
$booking_check = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}sb_bookings WHERE order_id = %d AND space_id = %d",
    $order_id, $space_id
), ARRAY_A);
echo "DB Check: id={$booking_check['id']}, status={$booking_check['status']}, start={$booking_check['start_time']}, end={$booking_check['end_time']}\n";

// Call get_slots
$slots_result = $avail->get_slots([$space_id], $date, 60);
$slots = $slots_result['slots'] ?? [];
foreach ($slots as $s) {
    if ($s['start'] === '18:30') {
        echo "Result: available=" . ($s['available'] ? 'true' : 'false') . "\n";
    }
}

echo "\n=== SCENARIO 2: Three Spaces (like TestMultiSpaceCollision.php) ===\n";
// Clean all for this order
$wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $order_id], ['%d']);

// Create three bookings like collision test
$spaces_to_book = [223, 10, 224];
foreach ($spaces_to_book as $id) {
    $repo->create_booking_row([
        'space_id' => $id,
        'order_id' => $order_id,
        'booking_date' => $date,
        'start_time' => $slot['start'],
        'end_time' => $slot['end'],
        'status' => 'in_review'
    ]);
    echo "Created booking for space $id\n";
}

// Now check each space with get_slots
foreach ($spaces_to_book as $id) {
    $slots_result = $avail->get_slots([$id], $date, 60);
    $slots = $slots_result['slots'] ?? [];
    
    $slot_18_30 = null;
    foreach ($slots as $s) {
        if ($s['start'] === '18:30') {
            $slot_18_30 = $s;
            break;
        }
    }
    
    if ($slot_18_30) {
        echo "Space $id: available=" . ($slot_18_30['available'] ? 'true' : 'false') . "\n";
    } else {
        echo "Space $id: No 18:30 slot found\n";
    }
}

echo "\n=== SCENARIO 3: Check Repository blocking ===\n";
foreach ($spaces_to_book as $id) {
    $blocking = $repo->get_blocking_intervals([$id], $date);
    echo "Space $id blocking: " . count($blocking) . " intervals\n";
    foreach ($blocking as $b) {
        echo "  - {$b['start']} to {$b['end']}\n";
    }
}

// Cleanup
$wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $order_id], ['%d']);
echo "\nCleaned up test bookings\n";