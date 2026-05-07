<?php

/**
 * Verification Test Script for Unified Resource-Based Booking Architecture
 * Run this via: http://your-site/wp-content/plugins/space-booking/tests/verify-refactor.php
 */

// Load WordPress
require_once dirname(__DIR__, 4) . '/wp-load.php';

echo "=== Unified Resource-Based Architecture Verification ===\n\n";

// Global for database access
global $wpdb;
$table = $wpdb->prefix . 'sb_bookings';

// Test A: Cleanup first - remove any test data from previous runs
echo "Test A Setup: Cleaning up previous test data...\n";
$wpdb->query("DELETE FROM $table WHERE booking_date = '2026-05-20' AND space_id IN (101, 102, 103)");
echo "Done.\n\n";

// Test A: Create a multi-space booking for Space IDs 101 and 102
echo "=== TEST A: Multi-Space Booking Creation ===\n";
$date = '2026-05-20';
$start_time = '14:00';
$end_time = '15:00';

$repo = new \SpaceBooking\Services\BookingRepository();

// Create lead booking (space_id = 101) with in_review status directly
$lead_booking_id = $repo->create([
    'space_id' => 101,
    'package_id' => null,
    'customer_name' => 'Test Customer',
    'customer_email' => 'test@example.com',
    'customer_phone' => '1234567890',
    'booking_date' => $date,
    'start_time' => $start_time,
    'end_time' => $end_time,
    'notes' => 'in_review',  // Hack: set status via notes, then update
    'expired_at' => '0000-00-00 00:00:00',  // Never expire for testing
]);

// Update status to in_review manually
$repo->update_status($lead_booking_id, 'in_review');

echo "Created lead booking ID: $lead_booking_id for space_id=101\n";

// Create secondary row for space_id = 102 using new unified method
$secondary_id = $repo->create_booking_row([
    'space_id' => 102,
    'package_id' => null,
    'order_id' => $lead_booking_id,
    'booking_date' => $date,
    'start_time' => $start_time,
    'end_time' => $end_time,
    'status' => 'in_review',
    'expired_at' => '0000-00-00 00:00:00',
]);

echo "Created secondary booking ID: $secondary_id for space_id=102\n";

// Verify in database
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, space_id, status, booking_date, start_time, end_time FROM $table WHERE booking_date = %s AND space_id IN (101, 102)",
    $date
), ARRAY_A);

echo "\nDatabase rows after Test A:\n";
foreach ($rows as $row) {
    echo sprintf(
        "  ID=%d, space_id=%d, status=%s, date=%s, time=%s-%s\n",
        $row['id'], $row['space_id'], $row['status'],
        $row['booking_date'], $row['start_time'], $row['end_time']
    );
}

$test_a_pass = (count($rows) === 2 &&
    in_array(101, array_column($rows, 'space_id')) &&
    in_array(102, array_column($rows, 'space_id')));
echo "\nTEST A RESULT: " . ($test_a_pass ? 'PASS' : 'FAIL') . "\n\n";

// Test B: Single-Space Detection
echo "=== TEST B: Single-Space Detection ===\n";
$blocking = $repo->get_blocking_intervals([101], $date);

echo "Blocking intervals for space_id 101:\n";
foreach ($blocking as $b) {
    echo sprintf("  %s - %s\n", $b['start'], $b['end']);
}

// Normalize time format (database returns HH:MM:SS, test expects HH:MM)
$test_b_pass = !empty($blocking) &&
    substr($blocking[0]['start'], 0, 5) === '14:00' &&
    substr($blocking[0]['end'], 0, 5) === '15:00';
echo "\nTEST B RESULT: " . ($test_b_pass ? 'PASS' : 'FAIL') . "\n\n";

// Test C: Common Slot Intersection
echo "=== TEST C: Common Slot Intersection ===\n";
$availability = new \SpaceBooking\Services\AvailabilityService($repo);
$slots = $availability->generate_dynamic_slots([101, 103], $date, 60);

echo "Available slots for [101, 103]:\n";
$found_14 = false;
foreach ($slots as $slot) {
    echo sprintf("  %s - %s: %s\n", $slot['start'], $slot['end'], $slot['available'] ? 'AVAILABLE' : 'BLOCKED');
    if ($slot['start'] === '14:00' && $slot['available']) {
        $found_14 = true;
    }
}

$test_c_pass = !$found_14;  // 14:00 should NOT be available because 101 is booked
echo 'TEST C RESULT: ' . ($test_c_pass ? 'PASS' : 'FAIL') . "\n\n";

// Test D: Conflict Messaging
echo "=== TEST D: Conflict Messaging ===\n";
// First check slots for 101 alone
$slots_101 = $availability->get_slots(101, $date, 60);
$available_101 = array_filter($slots_101['slots'], fn($s) => !empty($s['available']));

// Check slots for 102 alone
$slots_102 = $availability->get_slots(102, $date, 60);
$available_102 = array_filter($slots_102['slots'], fn($s) => !empty($s['available']));

// Check intersection (both in_review so should show blockers)
$intersection = $availability->get_intersection_slots([101, 102], $date, 60);

echo 'Slots available for 101 alone: ' . count($available_101) . "\n";
echo 'Slots available for 102 alone: ' . count($available_102) . "\n";
echo 'Intersection slots: ' . count($intersection['slots']) . "\n";
echo 'Blockers count: ' . count($intersection['blockers']) . "\n";

if (!empty($intersection['blockers'])) {
    foreach ($intersection['blockers'] as $blocker) {
        echo sprintf(
            "  Blocker: ID=%d, Title='%s', Reason='%s', Message='%s'\n",
            $blocker['id'], $blocker['title'], $blocker['reason'],
            $blocker['message'] ?? '(no message)'
        );
    }
}

// Test passes if 14:00 slot is properly excluded from intersection (both spaces blocked at that time)
$found_14_in_intersection = false;
foreach ($intersection['slots'] as $slot) {
    if ($slot['start'] === '14:00' && $slot['available']) {
        $found_14_in_intersection = true;
    }
}
$test_d_pass = !$found_14_in_intersection;
echo 'TEST D RESULT: ' . ($test_d_pass ? 'PASS' : 'FAIL') . "\n\n";

// Summary
echo "=== SUMMARY ===\n";
echo 'Test A (Multi-Space Creation): ' . ($test_a_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test B (Single-Space Detection): ' . ($test_b_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test C (Common Slot Intersection): ' . ($test_c_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test D (Conflict Messaging): ' . ($test_d_pass ? 'PASS' : 'FAIL') . "\n";

$all_pass = $test_a_pass && $test_b_pass && $test_c_pass && $test_d_pass;
echo "\nOVERALL: " . ($all_pass ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED') . "\n";

// Cleanup
$wpdb->query("DELETE FROM $table WHERE booking_date = '2026-05-20' AND space_id IN (101, 102, 103)");
echo "\nCleanup complete.\n";
