<?php
/** Debug test to verify multi-space booking blocking detection */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$repo = new \SpaceBooking\Services\BookingRepository();
$avail = new \SpaceBooking\Services\AvailabilityService($repo);

$date = '2026-05-16';
$slot = ['start' => '18:30:00', 'end' => '20:30:00'];
$space_ids = [223, 10, 224];
$order_id = 166;

// Clean up existing test bookings first
foreach ($space_ids as $sid) {
    $wpdb->delete($wpdb->prefix . 'sb_bookings',
        ['booking_date' => $date, 'space_id' => $sid, 'start_time' => $slot['start']],
        ['%s', '%d', '%s']);
}

echo "=== STEP 1: Create test bookings ===\n";
$booking_ids = [];
foreach ($space_ids as $sid) {
    $bid = $repo->create_booking_row([
        'space_id' => $sid,
        'order_id' => $order_id,
        'booking_date' => $date,
        'start_time' => $slot['start'],
        'end_time' => $slot['end'],
        'status' => 'in_review'
    ]);
    $booking_ids[] = $bid;
    echo "Created booking ID: $bid for space $sid\n";
}

// Verify DB rows
echo "\n=== STEP 2: Verify DB rows ===\n";
$rows = $wpdb->get_results($wpdb->prepare(
    'SELECT id, space_id, order_id, status, start_time, end_time FROM ' . $wpdb->prefix . 'sb_bookings WHERE order_id = %d',
    $order_id
), ARRAY_A);
echo 'Found ' . count($rows) . " rows:\n";
foreach ($rows as $r) {
    echo "  ID: {$r['id']}, Space: {$r['space_id']}, Time: {$r['start_time']}-{$r['end_time']}, Status: {$r['status']}\n";
}

// Check blocking intervals directly from repository
echo "\n=== STEP 3: Repository get_blocking_intervals ===\n";
foreach ($space_ids as $sid) {
    $blocking = $repo->get_blocking_intervals([$sid], $date);
    echo "Space $sid blocking intervals: " . count($blocking) . "\n";
    foreach ($blocking as $b) {
        echo "  - {$b['start']} to {$b['end']}\n";
    }
}

// Check slots from AvailabilityService (get_slots)
echo "\n=== STEP 4: AvailabilityService get_slots ===\n";
foreach ($space_ids as $sid) {
    $result = $avail->get_slots([$sid], $date, 60);
    $slots = $result['slots'] ?? [];
    echo "Space $sid get_slots: " . count($slots) . " slots\n";
    foreach ($slots as $s) {
        $status = $s['available'] ? 'AVAILABLE' : 'BLOCKED';
        echo "  - {$s['start']}: $status\n";
    }
}

// Check get_intersection_slots for all spaces
echo "\n=== STEP 5: AvailabilityService get_intersection_slots (ALL spaces) ===\n";
$result = $avail->get_intersection_slots($space_ids, $date, 60);
echo 'is_intersection: ' . ($result['is_intersection'] ? 'YES' : 'NO') . "\n";
echo 'Slots returned: ' . count($result['slots']) . "\n";
echo 'Blockers count: ' . count($result['blockers']) . "\n";
foreach ($result['blockers'] as $b) {
    echo "  - ID: {$b['id']}, Title: {$b['title']}, Reason: {$b['reason']}\n";
}

// Cleanup
echo "\n=== CLEANUP ===\n";
foreach ($booking_ids as $bid) {
    $wpdb->delete($wpdb->prefix . 'sb_bookings', ['id' => $bid], ['%d']);
}
echo 'Deleted ' . count($booking_ids) . " test bookings\n";

echo "\n=== ANALYSIS ===\n";
echo "If individual spaces show BLOCKED at 18:30 but multi-space shows slots available,\n";
echo "then there's a bug in the get_intersection_slots method.\n";
