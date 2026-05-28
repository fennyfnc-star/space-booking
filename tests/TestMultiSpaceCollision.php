<?php

/**
 * TDD Test: Multi-Space Collision Verification
 *
 * Scenario: Booking [223, 10, 224] should block all three individually.
 * This test replicates the exact log scenario from the Kukoolala Conflict.
 *
 * Run via: php tests/TestMultiSpaceCollision.php
 */
require_once __DIR__ . '/bootstrap.php';

echo "=== TDD TEST: Multi-Space Collision Verification ===\n\n";

global $wpdb;
$repo = new \SpaceBooking\Services\BookingRepository();
$avail = new \SpaceBooking\Services\AvailabilityService($repo);

$date = '2026-05-16';
$slot = ['start' => '18:30:00', 'end' => '20:30:00'];
$spaces_to_book = [223, 10, 224];

echo "=== PHASE 1: Create Multi-Space Booking ===\n";

// Simulate the logic that SHOULD happen in the Controller
$order_id = 166;  // Matches your log

// First, ensure we're starting clean - delete any existing test bookings
$wpdb->delete(
    $wpdb->prefix . 'sb_bookings',
    ['order_id' => $order_id],
    ['%d']
);
echo "Cleaned up any existing test bookings for order_id $order_id\n";

// Create bookings for each space - this simulates what BookingController does
foreach ($spaces_to_book as $id) {
    $repo->create_booking_row([
        'space_id' => $id,
        'order_id' => $order_id,
        'booking_date' => $date,
        'start_time' => $slot['start'],
        'end_time' => $slot['end'],
        'status' => 'in_review'
    ]);
    echo "Created booking row for Space ID: $id\n";
}

echo "\n=== PHASE 2: Database Verification ===\n";
$count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings WHERE order_id = %d",
    $order_id
));

echo "Rows created in DB: $count (Expected: 3)\n";

// List what was created
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, space_id, status FROM {$wpdb->prefix}sb_bookings WHERE order_id = %d",
    $order_id
), ARRAY_A);

echo "Created rows:\n";
foreach ($rows as $row) {
    echo "  - ID: {$row['id']}, Space: {$row['space_id']}, Status: {$row['status']}\n";
}

echo "\n=== PHASE 3: Individual Availability Checks (Repository) ===\n";
$results = [];
$blocking_data = [];

foreach ($spaces_to_book as $id) {
    $is_blocked = false;
    // Pass as array per new architecture
    $blocking = $repo->get_blocking_intervals([$id], $date);

    $blocking_data[$id] = $blocking;

    foreach ($blocking as $b) {
        if ($b['start'] === $slot['start']) {
            $is_blocked = true;
        }
    }

    $results[$id] = $is_blocked;
    echo "Space $id at 18:30: " . ($is_blocked ? '❌ BLOCKED (Correct)' : '✅ AVAILABLE (BUG!)') . "\n";

    if (!empty($blocking)) {
        echo "  Blocking intervals found:\n";
        foreach ($blocking as $b) {
            echo "    - {$b['start']} to {$b['end']}\n";
        }
    }
}

// Also test the AvailabilityService - pass array per new architecture
echo "\n=== PHASE 4: AvailabilityService Check ===\n";
foreach ($spaces_to_book as $id) {
    // NEW: Pass array instead of single space_id
    $slots_result = $avail->get_slots([$id], $date, 60);
    $slots = $slots_result['slots'] ?? [];

    // Find the 18:30 slot
    $slot_18_30 = null;
    foreach ($slots as $s) {
        if ($s['start'] === '18:30') {
            $slot_18_30 = $s;
            break;
        }
    }

    if ($slot_18_30) {
        echo "Space $id via AvailabilityService: " . ($slot_18_30['available'] ? '❌ BLOCKED (Correct)' : '✅ AVAILABLE (BUG!)') . "\n";
    } else {
        echo "Space $id via AvailabilityService: No 18:30 slot found\n";
    }
}

// Cleanup - delete test rows
echo "\n=== CLEANUP ===\n";
$wpdb->delete(
    $wpdb->prefix . 'sb_bookings',
    ['order_id' => $order_id],
    ['%d']
);
echo "Deleted test rows with order_id $order_id\n";

// Final Summary
$all_passed = ($count == 3 && !in_array(false, $results));
echo "\n========================================\n";
echo 'OVERALL RESULT: ' . ($all_passed ? '✅ PASS' : '❌ FAIL') . "\n";
echo "========================================\n";

exit($all_passed ? 0 : 1);
