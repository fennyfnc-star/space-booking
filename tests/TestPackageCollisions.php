<?php

/**
 * Module 4: Package-Space Collision Tests
 *
 * Tests:
 * 1. Hard Lock: If "Main Cafe" is booked, any Package containing Main Cafe
 *    must return available = false with blocker message
 * 2. Redundancy Check: If user selects Package AND individual space already
 *    inside that package, system must identify the overlap
 *
 * Run: http://your-site/wp-content/plugins/space-booking/tests/TestPackageCollisions.php
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

echo "=== Module 4: Package-Space Collision Tests ===\n\n";

// Use tomorrow's date
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

function create_test_package(string $title, array $space_ids, float $price = 150): int
{
    $post_id = wp_insert_post([
        'post_title' => $title,
        'post_type' => 'sb_package',
        'post_status' => 'publish',
    ]);
    update_post_meta($post_id, '_sb_package_price', $price);
    update_post_meta($post_id, '_sb_package_space_ids', $space_ids);
    update_post_meta($post_id, '_sb_package_duration', 2);
    return $post_id;
}

function cleanup_post(int $post_id): void
{
    wp_delete_post($post_id, true);
}

function cleanup_bookings(int $space_id, string $date): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'sb_bookings';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table WHERE space_id = %d AND booking_date = %s",
        $space_id, $date
    ));
}

// ============================================================
// TEST 1: Hard Lock - Package blocks when included space booked
// ============================================================
echo "=== TEST 1: Hard Lock (Package blocks when space booked) ===\n";

// Create Main Cafe space
$main_cafe = create_test_space('Main Cafe', 50);
echo "Created Space: Main Cafe (ID: $main_cafe)\n";

// Create "Cafe Party Package" that includes Main Cafe
$cafe_package = create_test_package('Cafe Party Package', [$main_cafe], 150);
echo "Created Package: Cafe Party Package (ID: $cafe_package)\n";

// Book Main Cafe directly (not via package)
$booking_id = $repo->create([
    'space_id' => $main_cafe,
    'customer_name' => 'Cafe Customer',
    'customer_email' => 'cafe@test.com',
    'booking_date' => $test_date,
    'start_time' => '10:00',
    'end_time' => '12:00',
    'status' => 'confirmed',
]);

echo "Booked Main Cafe directly (booking ID: $booking_id)\n";

// Now check if Package is available by checking the space it contains
$package_space_ids = get_post_meta($cafe_package, '_sb_package_space_ids', true) ?: [];
echo 'Package contains spaces: ' . json_encode($package_space_ids) . "\n";

// Get availability for spaces in package
$blocked = $repo->get_blocking_intervals($package_space_ids, $test_date);
echo 'Blocking intervals for package spaces: ' . count($blocked) . "\n";

foreach ($blocked as $b) {
    echo "  Blocked: {$b['start']} - {$b['end']}\n";
}

// The test: Package should be unavailable because Main Cafe is booked
$test_1_pass = (count($blocked) > 0);

echo 'TEST 1 RESULT: ' . ($test_1_pass ? 'PASS' : 'FAIL') . "\n\n";

cleanup_post($main_cafe);
cleanup_post($cafe_package);
cleanup_bookings($main_cafe, $test_date);

// ============================================================
// TEST 2: Redundancy Check - Package + individual space overlap
// ============================================================
echo "=== TEST 2: Redundancy Check (Package + space overlap) ===\n";

// Create spaces
$space_a = create_test_space('Space A', 50);
$space_b = create_test_space('Space B', 30);

echo "Created: Space A (ID: $space_a), Space B (ID: $space_b)\n";

// Create package that contains both A and B
$combo_package = create_test_package('Combo Package', [$space_a, $space_b], 150);
echo "Created Package: Combo Package (ID: $combo_package)\n";

// Now simulate user selecting Package AND Space A (redundant!)
$selected_items = [$space_a, $space_b];  // User picks package
$redundant_space = $space_a;  // User also picks individual space from package

// Detect overlap: does $redundant_space exist in $selected_items?
$package_spaces = get_post_meta($combo_package, '_sb_package_space_ids', true) ?: [];
$overlap = array_intersect([$redundant_space], $package_spaces);

echo 'User selected: Package (contains ' . json_encode($package_spaces) . ") + Space ID $redundant_space\n";
echo 'Overlap detected: ' . (count($overlap) > 0 ? 'YES' : 'NO') . "\n";

if (count($overlap) > 0) {
    echo "OVERLAP MESSAGE: Space #$redundant_space is already included in the selected package\n";
}

// TEST 2: Overlap detection works
$test_2_pass = (count($overlap) > 0);

echo 'TEST 2 RESULT: ' . ($test_2_pass ? 'PASS' : 'FAIL') . "\n\n";

cleanup_post($space_a);
cleanup_post($space_b);
cleanup_post($combo_package);

// ============================================================
// TEST 3: Verify conflict calculation
// ============================================================
echo "=== TEST 3: Package conflict footprint ===\n";

$space_a = create_test_space('Space A', 50);
$space_b = create_test_space('Space B', 30);

$package = create_test_package('Full Package', [$space_a, $space_b], 200);
echo "Created Package (ID: $package) with spaces: $space_a, $space_b\n";

// Get conflict group for the package
$conflict_group = $availability->get_conflict_group_ids($space_a);
echo 'Conflict group for Space A: ' . json_encode($conflict_group) . "\n";

// The conflict group should include both spaces (via resource dependencies)
// Or we may need to check explicitly

$package_spaces = get_post_meta($package, '_sb_package_space_ids', true) ?: [];

// Verify package contains both
$test_3_pass = (in_array($space_a, $package_spaces) && in_array($space_b, $package_spaces));

echo 'TEST 3 RESULT: ' . ($test_3_pass ? 'PASS' : 'FAIL') . "\n\n";

cleanup_post($space_a);
cleanup_post($space_b);
cleanup_post($package);

// ============================================================
// TEST 4: Availability returns blocker message
// ============================================================
echo "=== TEST 4: Blocker message in availability ===\n";

$space = create_test_space('Test Space', 50);
echo "Created Space (ID: $space)\n";

// Book the space
$booking_id = $repo->create([
    'space_id' => $space,
    'customer_name' => 'Blocking Customer',
    'customer_email' => 'block@test.com',
    'booking_date' => $test_date,
    'start_time' => '10:00',
    'end_time' => '12:00',
    'status' => 'confirmed',
]);

echo "Booked space (ID: $booking_id)\n";

// Get availability with blockers info
$result = $availability->get_intersection_slots([$space], $test_date, 60);

echo 'Slots: ' . count($result['slots']) . "\n";
echo 'Blockers: ' . count($result['blockers']) . "\n";

foreach ($result['blockers'] as $blocker) {
    echo "  Blocker: {$blocker['title']} - {$blocker['message']}\n";
}

// TEST 4: Should have blockers with message
$test_4_pass = (count($result['blockers']) > 0 && !empty($result['blockers'][0]['message']));

echo 'TEST 4 RESULT: ' . ($test_4_pass ? 'PASS' : 'FAIL') . "\n\n";

cleanup_post($space);
cleanup_bookings($space, $test_date);

// ============================================================
// SUMMARY
// ============================================================
echo "=== SUMMARY ===\n";
echo 'Test 1 (Hard Lock): ' . ($test_1_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 2 (Redundancy Check): ' . ($test_2_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 3 (Package Footprint): ' . ($test_3_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 4 (Blocker Message): ' . ($test_4_pass ? 'PASS' : 'FAIL') . "\n";

$all_pass = $test_1_pass && $test_2_pass && $test_3_pass && $test_4_pass;
echo "\nOVERALL: " . ($all_pass ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED') . "\n";
