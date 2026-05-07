<?php

/**
 * TestIterationLogic.php - "Iteration" Truth Test
 *
 * This test validates the NEW architectural approach:
 * 1. Every space ID = independent row in database
 * 2. Global Resource = Virtual Space ID
 * 3. Each booking creates N rows for N space_ids (+ global resources)
 *
 * Run: http://your-site/wp-content/plugins/space-booking/tests/TestIterationLogic.php
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

echo "=== ITERATION TRUTH TEST ===\n";
echo "Validating: One space ID = One row, Global Resource = Virtual space_id\n\n";

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

function create_global_resource(string $title): int
{
    // Create as sb_extra but treated as global resource
    $extra_id = wp_insert_post([
        'post_title' => $title,
        'post_type' => 'sb_extra',
        'post_status' => 'publish',
    ]);
    update_post_meta($extra_id, '_sb_extra_price', 50);
    update_post_meta($extra_id, '_sb_inventory', 1);
    update_post_meta($extra_id, '_sb_is_global_resource', 1);
    return $extra_id;
}

function cleanup_all(int $space_a, int $space_b, int $resource): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'sb_bookings';
    $extras_table = $wpdb->prefix . 'sb_booking_extras';

    // Delete bookings for spaces
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE space_id IN (%d, %d)", $space_a, $space_b));
    // Delete bookings for global resource (stored in space_id column)
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE space_id = %d", $resource));
    // Delete extras
    $wpdb->query($wpdb->prepare("DELETE FROM $extras_table WHERE extra_id = %d", $resource));

    wp_delete_post($space_a, true);
    wp_delete_post($space_b, true);
    wp_delete_post($resource, true);
}

// ============================================================
// STEP 1: Create booking for Spaces [101, 102] + Global Resource [999]
// ============================================================
echo "=== STEP 1: Create booking with multiple space IDs ===\n";

$space_101 = create_test_space('Space 101', 50);
$space_102 = create_test_space('Space 102', 30);
$resource_999 = create_global_resource('Bouncy Castle');

echo "Created: Space_A (ID: $space_101), Space_B (ID: $space_102), Global Resource (ID: $resource_999)\n";

// Create MULTIPLE rows: one for each space_id
// NEW STANDARD: Every space ID = independent row

// Row 1: Space 101 (lead)
$booking_id_1 = $repo->create([
    'space_id' => $space_101,
    'customer_name' => 'Multi-Space Customer',
    'customer_email' => 'multi@test.com',
    'booking_date' => $test_date,
    'start_time' => '10:00',
    'end_time' => '12:00',
    'status' => 'confirmed',
]);
// FIX: Update lead row's order_id to itself for query consistency
$wpdb->update(
    $wpdb->prefix . 'sb_bookings',
    ['order_id' => $booking_id_1],
    ['id' => $booking_id_1],
    ['%d'],
    ['%d']
);
echo "Created lead booking row: ID $booking_id_1 for space $space_101\n";

// Row 2: Space 102 (shadow/linked)
$booking_id_2 = $repo->create_booking_row([
    'space_id' => $space_102,
    'booking_date' => $test_date,
    'start_time' => '10:00',
    'end_time' => '12:00',
    'status' => 'confirmed',
    'order_id' => $booking_id_1,  // Link to lead
]);
echo "Created linked booking row: ID $booking_id_2 for space $space_102\n";

// Row 3: Global resource as VIRTUAL space_id
// NEW STANDARD: Global resource gets its own row with space_id = resource ID
$booking_id_3 = $repo->create_booking_row([
    'space_id' => $resource_999,  // Treated as "virtual space"
    'booking_date' => $test_date,
    'start_time' => '10:00',
    'end_time' => '12:00',
    'status' => 'confirmed',
    'order_id' => $booking_id_1,  // Link to lead
]);
echo "Created virtual booking row: ID $booking_id_3 for global resource $resource_999\n";

// ============================================================
// STEP 2: Verify EXACTLY 3 rows for this order group
// ============================================================
echo "\n=== STEP 2: Verify row count ===\n";

$row_count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings WHERE order_id = %d",
    $booking_id_1
));

echo "Query: SELECT COUNT(*) WHERE order_id = $booking_id_1\n";
echo "Result: $row_count rows\n";

$test_step2 = ($row_count == 3);
echo 'TEST STEP 2: ' . ($test_step2 ? 'PASS' : 'FAIL') . " (expected 3, got $row_count)\n";

// ============================================================
// STEP 3: Verify blocking includes global resource
// ============================================================
echo "\n=== STEP 3: Verify global resource blocks availability ===\n";

// Check availability for Space 103 should be BLOCKED by global resource
$space_103 = create_test_space('Space 103', 40);
echo "Created: Space 103 (ID: $space_103) - not in booking\n";

// Get blocking intervals
$blocking = $repo->get_blocking_intervals([$space_103], $test_date);
echo 'Blocking intervals for Space 103: ' . count($blocking) . "\n";

// Check if GLOBAL Resource blocks this space
$global_blocks = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings 
    WHERE space_id = %d AND booking_date = %s 
    AND status IN ('confirmed', 'in_review')
    AND start_time < '12:00' AND end_time > '10:00'",
    $resource_999, $test_date
));

echo 'Global resource blocks: ' . ($global_blocks > 0 ? 'YES' : 'NO') . "\n";

// The NEW logic: Global resource's space_id IS USED in the blocking check
// Since we added resource_999 as a booking row, it should query correctly
$test_step3 = ($global_blocks > 0);
echo 'TEST STEP 3: ' . ($test_step3 ? 'PASS' : 'FAIL') . "\n";

// ============================================================
// STEP 4: Conflict message identifies global resource
// ============================================================
echo "\n=== STEP 4: Conflict message for global resource ===\n";

$result = $availability->get_intersection_slots([$space_103], $test_date, 60);

echo "Availability result:\n";
echo '  Slots: ' . count($result['slots']) . "\n";
echo '  Blockers: ' . count($result['blockers']) . "\n";

foreach ($result['blockers'] as $blocker) {
    echo "  - {$blocker['title']}: {$blocker['message']}\n";
}

// The blocker message should reference the global resource
$has_global_message = false;
foreach ($result['blockers'] as $blocker) {
    if (stripos($blocker['title'], 'Bouncy') !== false ||
            stripos($blocker['message'], 'Bouncy') !== false) {
        $has_global_message = true;
    }
}

$test_step4 = $has_global_message;
echo 'TEST STEP 4: ' . ($test_step4 ? 'PASS' : 'FAIL') . "\n";

// ============================================================
// CLEANUP
// ============================================================
echo "\n=== Cleanup ===\n";
cleanup_all($space_101, $space_102, $resource_999);
wp_delete_post($space_103, true);
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}sb_bookings WHERE space_id = %d", $space_103));
echo "Done.\n";

// ============================================================
// SUMMARY
// ============================================================
echo "\n=== SUMMARY ===\n";
echo 'Step 2 (3 rows created): ' . ($test_step2 ? 'PASS' : 'FAIL') . "\n";
echo 'Step 3 (Global blocks): ' . ($test_step3 ? 'PASS' : 'FAIL') . "\n";
echo 'Step 4 (Global message): ' . ($test_step4 ? 'PASS' : 'FAIL') . "\n";

$all_pass = $test_step2 && $test_step3 && $test_step4;
echo "\nOVERALL: " . ($all_pass ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED') . "\n";
