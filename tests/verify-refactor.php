<?php

/**
 * Verification Test Script for Multi-Space Iteration Fix
 * Run this via: http://your-site/wp-content/plugins/space-booking/tests/verify-refactor.php
 *
 * This test uses EXACT IDs from the logs: 223, 10, 224
 * Verifies that when _sb_selected_item_ids is present, ALL spaces get their own row
 */

// Load WordPress
require_once dirname(__DIR__, 4) . '/wp-load.php';

echo "=== Multi-Space Iteration Verification (Using IDs 223, 10, 224) ===\n\n";

// Global for database access
global $wpdb;
$table = $wpdb->prefix . 'sb_bookings';

// Test IDs from the logs
$space_ids = [223, 10, 224];
$date = '2026-05-16';
$start_time = '18:30:00';
$end_time = '20:30:00';

// Test A: Cleanup first - remove any existing test data matching our test
echo "Test A Setup: Cleaning up previous test data for spaces 223, 10, 224 on $date...\n";
$wpdb->query($wpdb->prepare(
    "DELETE FROM $table WHERE booking_date = %s AND space_id IN (223, 10, 224)",
    $date
));
echo "Done.\n\n";

// ============================================================
// TEST A: Multi-Space Booking Creation - Using IDs 223, 10, 224
// ============================================================
echo "=== TEST A: Multi-Space Booking Creation (IDs: 223, 10, 224) ===\n";

$repo = new \SpaceBooking\Services\BookingRepository();

// Create LEAD booking (space_id = 223)
$lead_booking_id = $repo->create([
    'space_id' => 223,
    'package_id' => null,
    'customer_name' => 'Test Customer Fenny',
    'customer_email' => 'fenny.fnc@gmail.com',
    'customer_phone' => '',
    'booking_date' => $date,
    'start_time' => $start_time,
    'end_time' => $end_time,
    'status' => 'in_review',
    'expired_at' => '0000-00-00 00:00:00',  // Never expire
]);

echo "Created LEAD booking ID: $lead_booking_id for space_id=223\n";

// Save selected_item_ids meta (like BookingController does)
$repo->save_meta($lead_booking_id, '_sb_selected_item_ids', wp_json_encode($space_ids));

// Update lead row's order_id to itself
$wpdb->update(
    $table,
    ['order_id' => $lead_booking_id],
    ['id' => $lead_booking_id],
    ['%d'],
    ['%d']
);

// ============================================================
// ITERATION LOGIC: Create additional rows for spaces 10 and 224
// ============================================================
echo "\n--- Iteration: Creating additional rows for other spaces ---\n";

$other_spaces = array_values(array_diff($space_ids, [223]));  // [10, 224]
echo 'Other spaces to create: ' . implode(', ', $other_spaces) . "\n";

$created_rows = [];
foreach ($other_spaces as $sid) {
    try {
        $secondary_id = $repo->create_booking_row([
            'space_id' => $sid,
            'package_id' => null,
            'order_id' => $lead_booking_id,
            'booking_date' => $date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'status' => 'in_review',
            'expired_at' => '0000-00-00 00:00:00',
        ]);
        $created_rows[] = $secondary_id;
        echo "Created additional row ID: $secondary_id for space_id=$sid\n";
    } catch (\RuntimeException $e) {
        echo "ERROR creating row for space $sid: " . $e->getMessage() . "\n";
    }
}

// ============================================================
// ASSERTION 1: Count should be 3 (223 + 10 + 224)
// ============================================================
echo "\n--- ASSERTION 1: Row Count Check ---\n";

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, space_id, order_id, status FROM $table WHERE order_id = %d OR id = %d",
    $lead_booking_id, $lead_booking_id
), ARRAY_A);

echo "Total rows for order_id $lead_booking_id: " . count($rows) . "\n";
echo "Rows:\n";
foreach ($rows as $row) {
    echo sprintf(
        "  ID=%d, space_id=%d, order_id=%s, status=%s\n",
        $row['id'], $row['space_id'], $row['order_id'] ?? 'NULL', $row['status']
    );
}

$test_a_pass = (count($rows) === 3);
echo "\nTEST A RESULT: " . ($test_a_pass ? 'PASS - 3 rows created!' : 'FAIL - Expected 3 rows, got ' . count($rows)) . "\n\n";

// ============================================================
// TEST B: Single-Space Detection (Blocking Intervals)
// ============================================================
echo "=== TEST B: Blocking Intervals for Space 10 ===\n";

$blocking = $repo->get_blocking_intervals([10], $date);

echo "Blocking intervals for space_id 10:\n";
foreach ($blocking as $b) {
    echo sprintf("  %s - %s\n", $b['start'], $b['end']);
}

$test_b_pass = !empty($blocking);
echo 'TEST B RESULT: ' . ($test_b_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// TEST C: Verify ALL spaces blocked for same time slot
// ============================================================
echo "=== TEST C: All Spaces Blocking Verification ===\n";

$all_space_ids = [223, 10, 224];
$blocking_all = $repo->get_blocking_intervals($all_space_ids, $date);

echo "Blocking intervals for all spaces [223, 10, 224]:\n";
foreach ($blocking_all as $b) {
    echo sprintf("  %s - %s\n", $b['start'], $b['end']);
}

$test_c_pass = count($blocking_all) > 0;
echo 'TEST C RESULT: ' . ($test_c_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// TEST D: Verify space_id = 10 query returns blocked
// ============================================================
echo "=== TEST D: Verify Space 10 is BLOCKED ===\n";

$blocking_10 = $repo->get_blocking_intervals([10], $date);
$test_d_pass = count($blocking_10) > 0;

echo 'Space 10 blocked: ' . ($test_d_pass ? 'YES' : 'NO') . "\n";
echo 'TEST D RESULT: ' . ($test_d_pass ? 'PASS - Space 10 is BLOCKED' : 'FAIL - Space 10 is NOT blocked') . "\n\n";

// Summary
echo "=== SUMMARY ===\n";
echo 'Test A (3 Rows Created): ' . ($test_a_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test B (Blocking Intervals): ' . ($test_b_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test C (All Spaces Blocked): ' . ($test_c_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test D (Space 10 Blocked): ' . ($test_d_pass ? 'PASS' : 'FAIL') . "\n";

$all_pass = $test_a_pass && $test_b_pass && $test_c_pass && $test_d_pass;
echo "\nOVERALL: " . ($all_pass ? 'ALL TESTS PASSED - Iteration is working!' : 'SOME TESTS FAILED - Check issues above') . "\n";

// Cleanup
$wpdb->query($wpdb->prepare(
    "DELETE FROM $table WHERE booking_date = %s AND space_id IN (223, 10, 224)",
    $date
));
echo "\nCleanup complete.\n";
