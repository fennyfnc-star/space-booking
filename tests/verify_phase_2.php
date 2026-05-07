<?php

/**
 * Phase 2 Verification Test - API Intersection Logic
 *
 * Tests that when Space A is booked at 10:00 AM, the intersection
 * for [Space A, Space B] correctly removes that slot.
 */

// Bootstrap WordPress
require_once dirname(__DIR__) . '/tests/bootstrap.php';

global $wpdb;

echo "=== Phase 2 Verification Test ===\n\n";

// Test scenario:
// - Space 10 booked at 10:00 AM
// - Query intersection for [10, 223]
// - Expected: 10:00 slot REMOVED or available=false

$space_a = 10;  // Main Cafe
$space_b = 223;  // Covered Secret Garden
$date = date('Y-m-d');
$test_order_id = 9998;

echo "Setup: Booking Space $space_a at 10:00-11:00\n";

// Create a booking for space A at 10:00 AM (this should block it)
$repo = new SpaceBooking\Services\BookingRepository();

try {
    $row_id = $repo->create_booking_row([
        'space_id' => $space_a,
        'package_id' => null,
        'order_id' => $test_order_id,
        'booking_date' => $date,
        'start_time' => '10:00',
        'end_time' => '11:00',
        'status' => 'confirmed',  // Mark as confirmed to block
        'expired_at' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
    ]);
    echo "Created booking row $row_id for space $space_a\n";
} catch (RuntimeException $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}

echo "\n--- Testing get_intersection_slots ---\n";

// Now query intersection for both spaces
$availability = new SpaceBooking\Services\AvailabilityService($repo);
$result = $availability->get_intersection_slots([$space_a, $space_b], $date, 60);

$slots = $result['slots'] ?? [];
$blockers = $result['blockers'] ?? [];

echo 'Result slots count: ' . count($slots) . "\n";
echo 'Blockers count: ' . count($blockers) . "\n\n";

// Check if 10:00 slot was removed/blocked
$found_10am = false;
foreach ($slots as $slot) {
    if ($slot['start'] === '10:00' || $slot['start'] === '10:00:00') {
        $found_10am = true;
        echo 'FOUND 10:00 slot: available=' . ($slot['available'] ?? 'N/A') . "\n";
    }
}

echo "\n--- RAW Slot Output ---\n";
echo json_encode($slots, JSON_PRETTY_PRINT) . "\n";

echo "\n--- BLOCKERS ---\n";
echo json_encode($blockers, JSON_PRETTY_PRINT) . "\n";

echo "\n--- Verification ---\n";
if (!$found_10am) {
    echo "✓ PASS: 10:00 slot was REMOVED from intersection\n";
} else {
    // Check if available is false
    foreach ($slots as $slot) {
        if (($slot['start'] === '10:00' || $slot['start'] === '10:00:00') &&
                empty($slot['available'])) {
            echo "✓ PASS: 10:00 slot marked available=false\n";
            exit(0);
        }
    }
    echo "✗ FAIL: 10:00 slot still appears as available\n";
}

// Cleanup
$wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $test_order_id], ['%d']);
echo "\n(Cleaned up test booking)\n";

echo "\n=== End Test ===\n";
