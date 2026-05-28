<?php
/** Debug: Match TestMultiSpaceCollision exactly but show more details */
require_once __DIR__ . '/bootstrap.php';

echo "=== Debug: Matching TestMultiSpaceCollision exactly ===\n\n";

global $wpdb;
$repo = new \SpaceBooking\Services\BookingRepository();
$avail = new \SpaceBooking\Services\AvailabilityService($repo);

$date = '2026-05-16';
$slot = ['start' => '18:30:00', 'end' => '20:30:00'];
$spaces_to_book = [223, 10, 224];

$order_id = 166;

// Clean up first
$wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $order_id], ['%d']);
echo "Cleaned up test data\n";

// Create bookings - SAME as test
foreach ($spaces_to_book as $id) {
    $repo->create_booking_row([
        'space_id' => $id,
        'order_id' => $order_id,
        'booking_date' => $date,
        'start_time' => $slot['start'],
        'end_time' => $slot['end'],
        'status' => 'in_review'
    ]);
    echo "Created booking for Space ID: $id\n";
}

echo "\n--- Checking AvailabilityService ---";

// EXACT CODE from TestMultiSpaceCollision Phase 4
foreach ($spaces_to_book as $id) {
    // NEW: Pass array instead of single space_id
    $slots_result = $avail->get_slots([$id], $date, 60);
    $slots = $slots_result['slots'] ?? [];

    echo "\nSpace $id:\n";
    echo '  Total slots: ' . count($slots) . "\n";

    // Find the 18:30 slot
    $slot_18_30 = null;
    foreach ($slots as $s) {
        echo "    Checking slot: {$s['start']} - {$s['end']} => available=" . ($s['available'] ? 'true' : 'false') . "\n";
        if ($s['start'] === '18:30') {
            $slot_18_30 = $s;
        }
    }

    if ($slot_18_30) {
        echo '  RESULT: ' . ($slot_18_30['available'] ? '✅ AVAILABLE (BUG!)' : '❌ BLOCKED (Correct)') . "\n";
    } else {
        echo "  No 18:30 slot found\n";
    }
}

// Cleanup
$wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $order_id], ['%d']);
echo "\nCleaned up.\n";
