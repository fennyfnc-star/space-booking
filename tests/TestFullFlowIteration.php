<?php

/**
 * TestFullFlowIteration.php - Master Verification Test
 *
 * This test validates the full Unified Array Iteration flow:
 * 1. Pending registration with array of spaces
 * 2. Multi-row insertion in database
 * 3. Individual space availability blocking
 * 4. End-to-end verification
 *
 * Run: http://your-site/wp-content/plugins/space-booking/tests/TestFullFlowIteration.php
 */

// Bootstrap WordPress
define('ABSPATH', 'C:/xampp/htdocs/kukoolala/');
define('WP_DEBUG', true);
require_once ABSPATH . 'wp-load.php';

require_once dirname(__FILE__) . '/../space-booking.php';
require_once dirname(__FILE__) . '/../includes/Plugin.php';

\spaceBooking\Plugin::instance()->boot();

global $wpdb;
$repo = new \SpaceBooking\Services\BookingRepository();
$availability = new \SpaceBooking\Services\AvailabilityService($repo);

echo "=== MASTER VERIFICATION TEST: Unified Array Iteration ===\n\n";

$test_date = date('Y-m-d', strtotime('+1 day'));

// Helpers
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

function cleanup_test_bookings(array $space_ids, string $date): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'sb_bookings';

    foreach ($space_ids as $id) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE space_id = %d AND booking_date = %s",
            $id, $date
        ));
    }
}

function cleanup_spaces(array $space_ids): void
{
    foreach ($space_ids as $id) {
        wp_delete_post($id, true);
    }
}

// ============================================================
// STEP 1: Pending Registration with Space Array [101, 102]
// ============================================================
echo "=== STEP 1: Pending Registration ===\n";

$space_101 = create_test_space('Space A - Test', 50);
$space_102 = create_test_space('Space B - Test', 30);

echo "Created test spaces: ID $space_101, ID $space_102\n";

cleanup_test_bookings([$space_101, $space_102], $test_date);

// Get future expiry using MySQL
$future_expiry = $wpdb->get_var('SELECT DATE_ADD(NOW(), INTERVAL 2 HOUR)');

// Create lead booking (Space 101)
$booking_id = $repo->create([
    'space_id' => $space_101,
    'customer_name' => 'Test Customer',
    'customer_email' => 'test@example.com',
    'booking_date' => $test_date,
    'start_time' => '10:00',
    'end_time' => '12:00',
    'status' => 'pending',
    'expired_at' => $future_expiry,
]);

echo "Created lead booking ID: $booking_id for space $space_101\n";

// Update lead row's order_id
$wpdb->update(
    $wpdb->prefix . 'sb_bookings',
    ['order_id' => $booking_id],
    ['id' => $booking_id],
    ['%d'],
    ['%d']
);

// Create secondary row for Space 102
$booking_id_2 = $repo->create_booking_row([
    'space_id' => $space_102,
    'booking_date' => $test_date,
    'start_time' => '10:00',
    'end_time' => '12:00',
    'status' => 'pending',
    'order_id' => $booking_id,
    'expired_at' => $future_expiry,
]);

echo "Created secondary booking ID: $booking_id_2 for space $space_102\n";

$test_step1 = ($booking_id > 0 && $booking_id_2 > 0);
echo 'STEP 1 RESULT: ' . ($test_step1 ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// STEP 2: DB Check - Verify EXACTLY 2 pending rows
// ============================================================
echo "=== STEP 2: Database Row Count Check ===\n";

$row_count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings 
    WHERE space_id IN (%d, %d) AND status = 'pending'
    AND booking_date = %s",
    $space_101, $space_102, $test_date
));

echo "Query: SELECT COUNT(*) WHERE space_id IN ($space_101, $space_102) AND status = 'pending'\n";
echo "Result: $row_count rows\n";

$test_step2 = ($row_count == 2);
echo 'STEP 2 RESULT: ' . ($test_step2 ? 'PASS' : 'FAIL') . " (expected 2, got $row_count)\n\n";

// ============================================================
// STEP 3: Availability Check for Space 102
// ============================================================
echo "=== STEP 3: Individual Space Availability Check ===\n";

// Check availability for Space 102 specifically
$slots = $availability->get_slots($space_102, $test_date, 60);
$slots_array = $slots['slots'];

// Find 10:00 slot
$slot_10_blocked = false;
foreach ($slots_array as $slot) {
    if ($slot['start'] === '10:00' && empty($slot['available'])) {
        $slot_10_blocked = true;
        echo "Slot 10:00-11:00: BLOCKED\n";
    }
}

echo "Checking if Space $space_102 returns as BLOCKED due to pending booking...\n";
echo 'Space 102 slot 10:00 blocked: ' . ($slot_10_blocked ? 'YES' : 'NO') . "\n";

$test_step3 = $slot_10_blocked;
echo 'STEP 3 RESULT: ' . ($test_step3 ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// STEP 4: Verify blocking intervals for individual space
// ============================================================
echo "=== STEP 4: Blocking Intervals Verification ===\n";

$blocking = $repo->get_blocking_intervals([$space_102], $test_date);
echo "Blocking intervals for Space $space_102: " . count($blocking) . "\n";

if (!empty($blocking)) {
    foreach ($blocking as $block) {
        echo "  - Blocked: {$block['start']} - {$block['end']}\n";
    }
}

$test_step4 = !empty($blocking);
echo 'STEP 4 RESULT: ' . ($test_step4 ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// STEP 5: Intersection Check - Both spaces must show blocking
// ============================================================
echo "=== STEP 5: Intersection Availability ===\n";

$intersection = $availability->get_intersection_slots([$space_101, $space_102], $test_date, 60);

echo 'Common slots: ' . count($intersection['slots']) . "\n";
echo 'Blockers: ' . count($intersection['blockers']) . "\n";

if (!empty($intersection['blockers'])) {
    foreach ($intersection['blockers'] as $blocker) {
        echo "  - Blocker: {$blocker['title']} ({$blocker['reason']})\n";
    }
}

// Since both spaces have pending bookings at 10:00-12:00, intersection should be empty
$test_step5 = empty($intersection['slots']);
echo 'STEP 5 RESULT: ' . ($test_step5 ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// STEP 6: Verify the "Array First" truth
// ============================================================
echo "=== STEP 6: Array-First Truth Verification ===\n";

// Query directly by space_id - should find the row
$direct_query = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings 
    WHERE space_id = %d AND booking_date = %s 
    AND status = 'pending'",
    $space_102, $test_date
));

echo "Direct query for space_id = $space_102: $direct_query row(s)\n";
echo "(This proves NO 'Primary' hierarchy - space_id is the truth)\n";

$test_step6 = ($direct_query == 1);
echo 'STEP 6 RESULT: ' . ($test_step6 ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// CLEANUP
// ============================================================
echo "=== Cleanup ===\n";
cleanup_test_bookings([$space_101, $space_102], $test_date);
cleanup_spaces([$space_101, $space_102]);
echo "Done.\n\n";

// ============================================================
// SUMMARY
// ============================================================
echo "=== SUMMARY ===\n";
echo 'Step 1 (Pending Registration): ' . ($test_step1 ? 'PASS' : 'FAIL') . "\n";
echo 'Step 2 (DB Row Count): ' . ($test_step2 ? 'PASS' : 'FAIL') . "\n";
echo 'Step 3 (Space 102 Blocked): ' . ($test_step3 ? 'PASS' : 'FAIL') . "\n";
echo 'Step 4 (Blocking Intervals): ' . ($test_step4 ? 'PASS' : 'FAIL') . "\n";
echo 'Step 5 (Intersection Empty): ' . ($test_step5 ? 'PASS' : 'FAIL') . "\n";
echo 'Step 6 (Array-First Truth): ' . ($test_step6 ? 'PASS' : 'FAIL') . "\n";

$all_pass = $test_step1 && $test_step2 && $test_step3 && $test_step4 && $test_step5 && $test_step6;
echo "\nOVERALL: " . ($all_pass ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED') . "\n";

exit($all_pass ? 0 : 1);
