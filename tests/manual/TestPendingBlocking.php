<?php

/**
 * Pending Slot Blocking Tests
 *
 * Tests for pending booking handling:
 * 1. Pending (non-expired) bookings block slots
 * 2. Confirmed bookings always block slots
 * 3. Cleanup removes expired pending bookings
 * 4. Get pending intervals method works
 *
 * Run via: http://your-site/wp-content/plugins/space-booking/tests/manual/TestPendingBlocking.php
 */

// Load WordPress
require_once dirname(__DIR__, 5) . '/wp-load.php';

echo "=== Pending Slot Blocking Tests ===\n\n";

global $wpdb;
$table = $wpdb->prefix . 'sb_bookings';
$repo = new \SpaceBooking\Services\BookingRepository();
$availability = new \SpaceBooking\Services\AvailabilityService($repo);

// Use tomorrow's date (but check the actual MySQL time)
$mysql_now = $wpdb->get_var('SELECT NOW()');
$test_date = date('Y-m-d', strtotime('+1 day'));

// Cleanup function
function cleanup_test_bookings(int $space_id, string $date): void
{
    global $wpdb, $table;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table WHERE space_id = %d AND booking_date = %s",
        $space_id, $date
    ));
}

// ============================================================
// TEST 1: Pending non-expired booking blocks slot
// ============================================================
echo "=== TEST 1: Pending Non-Expired Blocks Slot ===\n";

// Use actual space ID 10 (Main Cafe)
$test_space_id = 10;
cleanup_test_bookings($test_space_id, $test_date);

// Get future expiry using MySQL time (to ensure timezone consistency)
$future_expiry = $wpdb->get_var('SELECT DATE_ADD(NOW(), INTERVAL 2 HOUR)');
echo "Using MySQL future expiry: $future_expiry\n";

// Create pending booking with future expiry (non-expired)
$pending_id = $repo->create([
    'space_id' => $test_space_id,
    'customer_name' => 'Pending Customer',
    'customer_email' => 'pending@test.com',
    'booking_date' => $test_date,
    'start_time' => '10:00',
    'end_time' => '11:00',
    'status' => 'pending',
    'expired_at' => $future_expiry,
]);

echo "Created pending booking ID: $pending_id\n";

// Get blocking intervals
$blocking = $repo->get_blocking_intervals([$test_space_id], $test_date);
echo 'Blocking intervals count: ' . count($blocking) . "\n";

$test_1_pass = !empty($blocking) &&
    substr($blocking[0]['start'], 0, 5) === '10:00' &&
    substr($blocking[0]['end'], 0, 5) === '11:00';

echo 'TEST 1 RESULT: ' . ($test_1_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// TEST 2: Confirmed booking always blocks
// ============================================================
echo "=== TEST 2: Confirmed Always Blocks ===\n";

// Use space 223 (Covered Secret Garden)
$test_space_id_2 = 223;
$wpdb->query($wpdb->prepare(
    "DELETE FROM $table WHERE space_id = %d AND booking_date = %s",
    $test_space_id_2, $test_date
));

// Create confirmed booking
$confirmed_id = $repo->create([
    'space_id' => $test_space_id_2,
    'customer_name' => 'Confirmed Customer',
    'customer_email' => 'confirmed@test.com',
    'booking_date' => $test_date,
    'start_time' => '12:00',
    'end_time' => '13:00',
    'status' => 'confirmed',
]);

echo "Created confirmed booking ID: $confirmed_id\n";

// Get blocking intervals
$blocking_2 = $repo->get_blocking_intervals([$test_space_id_2], $test_date);
echo 'Blocking intervals count: ' . count($blocking_2) . "\n";

$test_2_pass = !empty($blocking_2);

echo 'TEST 2 RESULT: ' . ($test_2_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// TEST 3: Available slots reflect blocking
// ============================================================
echo "=== TEST 3: Slots Reflect Blocking ===\n";

// Use space 10 from Test 1
$slots = $availability->get_slots(10, $test_date, 60);
$slots_array = $slots['slots'];

// Find any blocked slot
$blocked_count = 0;
foreach ($slots_array as $slot) {
    if (!$slot['available']) {
        $blocked_count++;
        echo 'Blocked slot: ' . $slot['start'] . '-' . $slot['end'] . "\n";
    }
}

echo "Total blocked slots: $blocked_count\n";

$test_3_pass = $blocked_count > 0;

echo 'TEST 3 RESULT: ' . ($test_3_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// TEST 4: Cleanup removes expired pending
// ============================================================
echo "=== TEST 4: Cleanup Removes Expired ===\n";

// Get past expiry using MySQL
$past_expiry = $wpdb->get_var('SELECT DATE_SUB(NOW(), INTERVAL 2 HOUR)');

// Create expired booking
$cleanup_id = $repo->create([
    'space_id' => 103,
    'customer_name' => 'To Cleanup',
    'customer_email' => 'cleanup@test.com',
    'booking_date' => $test_date,
    'start_time' => '14:00',
    'end_time' => '15:00',
    'status' => 'pending',
    'expired_at' => $past_expiry,
]);

echo "Created expired booking ID: $cleanup_id (expired)\n";

// Run cleanup
$deleted = $repo->cleanup_expired();
echo "Cleaned up $deleted expired booking(s)\n";

// Verify deleted
$found_after = $repo->find($cleanup_id);
$test_4_pass = $found_after === null;

echo 'TEST 4 RESULT: ' . ($test_4_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// TEST 5: Get pending intervals method works
// ============================================================
echo "=== TEST 5: Get Pending Intervals ===\n";

// Define test space ID 3 for the pending intervals test
$test_space_id_3 = 224;

// Create another pending booking on space 224
$pending2_id = $repo->create([
    'space_id' => $test_space_id_3,
    'customer_name' => 'Pending 2',
    'customer_email' => 'pending2@test.com',
    'booking_date' => $test_date,
    'start_time' => '15:00',
    'end_time' => '16:00',
    'status' => 'pending',
    'expired_at' => $future_expiry,
]);

$pending_intervals = $repo->get_pending_intervals_for_spaces([$test_space_id_3], $test_date);
echo 'Pending intervals count: ' . count($pending_intervals) . "\n";

$test_5_pass = !empty($pending_intervals);

echo 'TEST 5 RESULT: ' . ($test_5_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// FINAL CLEANUP
// ============================================================
echo "=== Final Cleanup ===\n";
cleanup_test_bookings($test_space_id, $test_date);
cleanup_test_bookings($test_space_id_2, $test_date);
cleanup_test_bookings($test_space_id_3, $test_date);
echo "Done.\n\n";

// ============================================================
// SUMMARY
// ============================================================
echo "=== SUMMARY ===\n";
echo 'Test 1 (Pending Blocks): ' . ($test_1_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 2 (Confirmed Blocks): ' . ($test_2_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 3 (Slots Reflect): ' . ($test_3_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 4 (Cleanup Expired): ' . ($test_4_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 5 (Get Pending): ' . ($test_5_pass ? 'PASS' : 'FAIL') . "\n";

$all_pass = $test_1_pass && $test_2_pass && $test_3_pass && $test_4_pass && $test_5_pass;
echo "\nOVERALL: " . ($all_pass ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED') . "\n";
