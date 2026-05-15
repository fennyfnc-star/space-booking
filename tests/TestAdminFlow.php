<?php

/**
 * Module 3: The "In Review" Human-in-the-Loop Tests
 *
 * Tests:
 * 1. Status Lock: Verify 'in_review' status blocks calendar like confirmed
 * 2. Status Transition: Cancelled order frees up space_ids immediately
 *
 * Run: http://your-site/wp-content/plugins/space-booking/tests/TestAdminFlow.php
 */

// Bootstrap WordPress
$abspath = dirname(__DIR__, 4);
if (file_exists($abspath . '/wp-load.php')) {
    define('ABSPATH', $abspath . '/');
} else {
    define('ABSPATH', dirname(__DIR__, 3) . '/');
}
define('WP_DEBUG', true);
require_once ABSPATH . 'wp-load.php';

require_once dirname(__FILE__) . '/../space-booking.php';
require_once dirname(__FILE__) . '/../includes/Plugin.php';

\spaceBooking\Plugin::instance()->boot();

global $wpdb;
$repo = new \SpaceBooking\Services\BookingRepository();
$availability = new \SpaceBooking\Services\AvailabilityService($repo);

echo "=== Module 3: In Review Status Tests ===\n\n";

// Use tomorrow's date
$test_date = date('Y-m-d', strtotime('+1 day'));

// Helper to create test space
function create_test_space(string $title, float $rate = 50): int
{
    $post_id = wp_insert_post([
        'post_title' => $title,
        'post_type' => 'sb_space',
        'post_status' => 'publish',
    ]);
    update_post_meta($post_id, '_sb_hourly_rate', $rate);
    return $post_id;
}

function cleanup_space(int $space_id): void
{
    wp_delete_post($space_id, true);
}

function cleanup_bookings(int $space_id, string $date): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'sb_bookings';
    $spaces_table = $wpdb->prefix . 'sb_booking_spaces';
    $extras_table = $wpdb->prefix . 'sb_booking_extras';

    // Get all booking IDs for this space and date
    $booking_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT b.id FROM $table b
         LEFT JOIN $spaces_table bs ON b.id = bs.booking_id
         WHERE (b.space_id = %d OR bs.space_id = %d)
         AND b.booking_date = %s",
        $space_id, $space_id, $date
    ));

    // Delete extras first
    if (!empty($booking_ids)) {
        $wpdb->query("DELETE FROM $extras_table WHERE booking_id IN (" . implode(',', array_map('intval', $booking_ids)) . ")");
    }

    // Delete from spaces table
    $wpdb->query("DELETE FROM $spaces_table WHERE booking_id IN (" . implode(',', array_map('intval', $booking_ids)) . ")");

    // Delete bookings
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table WHERE space_id = %d AND booking_date = %s",
        $space_id, $date
    ));
}

// ============================================================
// TEST 1: Status Lock - 'in_review' blocks calendar like confirmed
// ============================================================
echo "=== TEST 1: Status Lock (in_review blocks calendar) ===\n";

$space_id = create_test_space('Test Space');
echo "Created Space (ID: $space_id)\n";

// Create booking with 'in_review' status
$booking_id = $repo->create([
    'space_id' => $space_id,
    'customer_name' => 'In Review Customer',
    'customer_email' => 'inreview@test.com',
    'booking_date' => $test_date,
    'start_time' => '10:00',
    'end_time' => '12:00',
    'status' => 'in_review',  // KEY: in_review status
]);

echo "Created in_review booking ID: $booking_id\n";

// Get blocking intervals
$blocking = $repo->get_blocking_intervals([$space_id], $test_date);
echo 'Blocking intervals: ' . count($blocking) . "\n";

foreach ($blocking as $b) {
    echo "  Blocked: {$b['start']} - {$b['end']}\n";
}

// Check availability
$slots = $availability->get_slots($space_id, $test_date, 60);
$slots_array = $slots['slots'];

$blocked_count = 0;
foreach ($slots_array as $slot) {
    if (!$slot['available']) {
        $blocked_count++;
        echo "  Slot blocked: {$slot['start']} - {$slot['end']}\n";
    }
}

echo "Total blocked slots: $blocked_count\n";

// TEST 1: in_review should block AND show in blocking intervals
$test_1_pass = (count($blocking) > 0 && $blocked_count > 0);

echo 'TEST 1 RESULT: ' . ($test_1_pass ? 'PASS' : 'FAIL') . "\n\n";

cleanup_space($space_id);
cleanup_bookings($space_id, $test_date);

// ============================================================
// TEST 2: Status Transition - Cancelled frees space
// ============================================================
echo "=== TEST 2: Status Transition (Cancelled frees space) ===\n";

$space_id = create_test_space('Test Space');
echo "Created Space (ID: $space_id)\n";

// Create confirmed booking (simulating WooCommerce order)
$booking_id = $repo->create([
    'space_id' => $space_id,
    'customer_name' => 'Confirmed Customer',
    'customer_email' => 'confirmed@test.com',
    'booking_date' => $test_date,
    'start_time' => '14:00',
    'end_time' => '16:00',
    'status' => 'confirmed',
]);

echo "Created confirmed booking ID: $booking_id\n";

// Verify it blocks
$blocking_before = $repo->get_blocking_intervals([$space_id], $test_date);
echo 'Blocking before cancel: ' . count($blocking_before) . "\n";

// Simulate WooCommerce order status change to cancelled
$updated = $repo->update_status($booking_id, 'cancelled');
echo "Updated status to 'cancelled': " . ($updated ? 'YES' : 'NO') . "\n";

// Check blocking after cancel
$blocking_after = $repo->get_blocking_intervals([$space_id], $test_date);
echo 'Blocking after cancel: ' . count($blocking_after) . "\n";

// Verify booking is cancelled
$booking = $repo->find($booking_id);
$status_after = $booking ? $booking['status'] : 'NOT FOUND';
echo "Booking status now: $status_after\n";

// TEST 2: Cancelled booking should NOT block
$test_2_pass = (count($blocking_after) === 0 && $status_after === 'cancelled');

echo 'TEST 2 RESULT: ' . ($test_2_pass ? 'PASS' : 'FAIL') . "\n\n";

cleanup_space($space_id);
cleanup_bookings($space_id, $test_date);

// ============================================================
// TEST 3: Compare confirmed vs in_review blocking
// ============================================================
echo "=== TEST 3: Compare confirmed vs in_review blocking ===\n";

$space_id = create_test_space('Test Space');
echo "Created Space (ID: $space_id)\n";

// Create both confirmed and in_review bookings for different times
$confirmed_id = $repo->create([
    'space_id' => $space_id,
    'customer_name' => 'Confirmed',
    'customer_email' => 'confirmed@test.com',
    'booking_date' => $test_date,
    'start_time' => '09:00',
    'end_time' => '10:00',
    'status' => 'confirmed',
]);

$in_review_id = $repo->create([
    'space_id' => $space_id,
    'customer_name' => 'In Review',
    'customer_email' => 'inreview@test.com',
    'booking_date' => $test_date,
    'start_time' => '11:00',
    'end_time' => '12:00',
    'status' => 'in_review',
]);

echo "Created confirmed: $confirmed_id (09:00-10:00)\n";
echo "Created in_review: $in_review_id (11:00-12:00)\n";

// Get all blocking intervals
$blocking = $repo->get_blocking_intervals([$space_id], $test_date);
echo 'Total blocking intervals: ' . count($blocking) . "\n";

foreach ($blocking as $b) {
    echo "  Blocked: {$b['start']} - {$b['end']}\n";
}

// Both confirmed AND in_review should block
$test_3_pass = (count($blocking) === 2);

echo 'TEST 3 RESULT: ' . ($test_3_pass ? 'PASS' : 'FAIL') . "\n\n";

cleanup_space($space_id);
cleanup_bookings($space_id, $test_date);

// ============================================================
// SUMMARY
// ============================================================
echo "=== SUMMARY ===\n";
echo 'Test 1 (in_review blocks): ' . ($test_1_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 2 (Cancelled frees): ' . ($test_2_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 3 (confirmed=in_review): ' . ($test_3_pass ? 'PASS' : 'FAIL') . "\n";

$all_pass = $test_1_pass && $test_2_pass && $test_3_pass;
echo "\nOVERALL: " . ($all_pass ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED') . "\n";
