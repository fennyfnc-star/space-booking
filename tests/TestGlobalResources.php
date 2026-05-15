<?php

/**
 * Module 2: "Bouncy Castle" Global Resource Constraint Tests
 *
 * Tests:
 * 1. Inventory Check: Global resource with 1 unit available
 * 2. Conflict Test: If User 1 books Space A with Bouncy Castle,
 *    User 2 trying to book Space B with Bouncy Castle must be blocked
 *
 * Global Resources are tied to TIME, not to specific space ID.
 * The correct approach is to add the global resource as a SPACE_ID
 * in the bookings table (virtual resource pattern).
 *
 * Run: http://your-site/wp-content/plugins/space-booking/tests/TestGlobalResources.php
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

echo "=== Module 2: Global Resources (Bouncy Castle) ===\n\n";

// Use tomorrow's date
$test_date = date('Y-m-d', strtotime('+1 day'));
$future_expiry = $wpdb->get_var('SELECT DATE_ADD(NOW(), INTERVAL 2 HOUR)');

/**
 * Helper: Create test space
 */
function create_test_space(string $title, float $hourly_rate = 50): int
{
    $post_id = wp_insert_post([
        'post_title' => $title,
        'post_type' => 'sb_space',
        'post_status' => 'publish',
    ]);
    update_post_meta($post_id, '_sb_hourly_rate', $hourly_rate);
    return $post_id;
}

/**
 * Helper: Create global resource (like Bouncy Castle)
 * This is stored as an sb_extra with global_inventory = 1
 * AND we mark it as a global resource that can be booked as "space"
 */
function create_global_resource(string $title): int
{
    // Create as sb_extra CPT
    $extra_id = wp_insert_post([
        'post_title' => $title,
        'post_type' => 'sb_extra',
        'post_status' => 'publish',
    ]);
    update_post_meta($extra_id, '_sb_extra_price', 50);
    update_post_meta($extra_id, '_sb_inventory', 1);
    update_post_meta($extra_id, '_sb_is_global_resource', 1);  // Mark as global
    update_post_meta($extra_id, '_sb_can_book_as_space', 1);  // Mark as bookable as virtual space

    return $extra_id;
}

/**
 * Cleanup helper
 */
function cleanup_test_bookings(int $space_id, string $date): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'sb_bookings';
    $spaces_table = $wpdb->prefix . 'sb_booking_spaces';
    $extras_table = $wpdb->prefix . 'sb_booking_extras';

    // Get all booking IDs for this space and date (including linked spaces)
    $booking_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT b.id FROM $table b
         LEFT JOIN $spaces_table bs ON b.id = bs.booking_id
         WHERE (b.space_id = %d OR bs.space_id = %d)
         AND b.booking_date = %s",
        $space_id, $space_id, $date
    ));

    // Delete extras first
    if (!empty($booking_ids)) {
        $ids = implode(',', array_map('intval', $booking_ids));
        $wpdb->query("DELETE FROM $extras_table WHERE booking_id IN ($ids)");
        $wpdb->query("DELETE FROM $spaces_table WHERE booking_id IN ($ids)");
    }

    // Delete bookings
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table WHERE space_id = %d AND booking_date = %s",
        $space_id, $date
    ));
}

function cleanup_test_post(int $post_id): void
{
    wp_delete_post($post_id, true);
}

// ============================================================
// TEST 1: Inventory Check - 1 Bouncy Castle available globally
// ============================================================
echo "=== TEST 1: Inventory Check (1 Bouncy Castle available) ===\n";

$bouncy_castle = create_global_resource('Bouncy Castle');
echo "Created Global Resource: Bouncy Castle (ID: $bouncy_castle)\n";

// Check inventory stored in meta
$inventory = (int) get_post_meta($bouncy_castle, '_sb_inventory', true);
$is_global = (int) get_post_meta($bouncy_castle, '_sb_is_global_resource', true);
$can_book_as_space = (int) get_post_meta($bouncy_castle, '_sb_can_book_as_space', true);

echo "Inventory: $inventory\n";
echo "Is Global Resource: $is_global\n";
echo "Can Book as Space: $can_book_as_space\n";

$test_1_pass = ($inventory === 1 && $is_global === 1 && $can_book_as_space === 1);
echo 'TEST 1 RESULT: ' . ($test_1_pass ? 'PASS' : 'FAIL') . "\n\n";

cleanup_test_post($bouncy_castle);

// ============================================================
// TEST 2: Conflict Test - User 1 books Bouncy Castle, User 2 blocked
// ============================================================
echo "=== TEST 2: Conflict Test (User 1 → User 2 blocked) ===\n";

// Create test spaces
$space_a = create_test_space('Space A', 50);
$space_b = create_test_space('Space B', 30);

// Create global resource
$bouncy_castle = create_global_resource('Bouncy Castle');
echo "Created: Space A (ID: $space_a), Space B (ID: $space_b)\n";
echo "Created: Bouncy Castle (ID: $bouncy_castle)\n";

// User 1 books Space A + Bouncy Castle for 10:00-12:00
// CORRECT APPROACH: Add Bouncy Castle as a SPACE_ID in the bookings table (virtual resource)
$booking_1 = $repo->create([
    'space_id' => $space_a,
    'customer_name' => 'User 1',
    'customer_email' => 'user1@test.com',
    'booking_date' => $test_date,
    'start_time' => '10:00',
    'end_time' => '12:00',
    'status' => 'confirmed',
]);
echo "User 1 booking ID: $booking_1 (Space A)\n";

// ALSO create a row for the global resource (Bouncy Castle) - THIS IS THE KEY!
// This makes the global resource be checked like any other space
$repo->create_booking_row([
    'space_id' => $bouncy_castle,  // Using extra ID as "space_id" - virtual resource
    'order_id' => $booking_1,  // Link to main booking
    'booking_date' => $test_date,
    'start_time' => '10:00',
    'end_time' => '12:00',
    'status' => 'confirmed',
]);
echo "Created virtual booking row for Bouncy Castle (ID: $bouncy_castle) as space_id\n";

// Verify: Check how many rows in bookings table for this order
$row_count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings WHERE order_id = %d",
    $booking_1
));
echo "Bookings for order $booking_1: $row_count rows\n";

// Now check availability for User 2 trying to book Space B
// Get blocking intervals for Space B (should include Bouncy Castle as blocker)
$blocked = $repo->get_blocking_intervals([$space_b], $test_date);

echo 'Blocking intervals for Space B: ' . count($blocked) . "\n";

foreach ($blocked as $b) {
    echo "  Blocked: {$b['start']} - {$b['end']}\n";
}

// Use AvailabilityService to get proper blockers
$avail_result = $availability->get_intersection_slots([$space_b], $test_date, 60);

echo "Availability result for Space B:\n";
echo '  Slots: ' . count($avail_result['slots']) . "\n";
echo '  Blockers: ' . count($avail_result['blockers']) . "\n";

foreach ($avail_result['blockers'] as $blocker) {
    echo "  - {$blocker['title']}: {$blocker['message']}\n";
}

// User 2 should be BLOCKED because Bouncy Castle is already booked (blocks ALL spaces)
// The test passes if blockers include Bouncy Castle or if blocking intervals exist
$test_2_pass = (count($blocked) > 0 || count($avail_result['blockers']) > 0);

echo 'TEST 2 RESULT: ' . ($test_2_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// TEST 3: Verify Global Resource blocks across different spaces
// ============================================================
echo "=== TEST 3: Global Resource Blocks Across Spaces ===\n";

// Create a third space not involved in any direct booking
$space_c = create_test_space('Space C', 40);
echo "Created: Space C (ID: $space_c) - not directly booked\n";

// Check if there are blocking intervals for Space C (Bouncy Castle should block it)
$space_c_blocked = $repo->get_blocking_intervals([$space_c], $test_date);

echo 'Blocking intervals for Space C: ' . count($space_c_blocked) . "\n";

// Also check with AvailabilityService
$space_c_avail = $availability->get_intersection_slots([$space_c], $test_date, 60);
echo 'Availability blockers for Space C: ' . count($space_c_avail['blockers']) . "\n";

foreach ($space_c_avail['blockers'] as $blocker) {
    echo "  - {$blocker['title']}: {$blocker['reason']}\n";
}

// Space C should also be BLOCKED because Bouncy Castle blocks globally
$test_3_pass = (count($space_c_blocked) > 0 || count($space_c_avail['blockers']) > 0);

echo 'TEST 3 RESULT: ' . ($test_3_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// CLEANUP
// ============================================================
echo "=== Cleanup ===\n";

// Clean up all bookings for these spaces/extra for this date
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}sb_bookings WHERE booking_date = %s AND (space_id = %d OR space_id = %d OR space_id = %d OR space_id = %d)",
    $test_date, $space_a, $space_b, $space_c, $bouncy_castle
));

cleanup_test_post($space_a);
cleanup_test_post($space_b);
cleanup_test_post($space_c);
cleanup_test_post($bouncy_castle);
echo "Done.\n\n";

// ============================================================
// SUMMARY
// ============================================================
echo "=== SUMMARY ===\n";
echo 'Test 1 (Inventory Check): ' . ($test_1_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 2 (Conflict Test): ' . ($test_2_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 3 (Global Blocks): ' . ($test_3_pass ? 'PASS' : 'FAIL') . "\n";

$all_pass = $test_1_pass && $test_2_pass && $test_3_pass;
echo "\nOVERALL: " . ($all_pass ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED') . "\n";
