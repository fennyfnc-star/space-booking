<?php

/**
 * Extras Validation Tests
 *
 * Tests for extras handling:
 * 1. Booking without extras should not show extras
 * 2. Booking with extras shows proper extras
 * 3. Extra validation (only valid extra_id)
 *
 * Run via: http://your-site/wp-content/plugins/space-booking/tests/manual/TestExtrasValidation.php
 */

// Load WordPress
require_once dirname(__DIR__, 5) . '/wp-load.php';

echo "=== Extras Validation Tests ===\n\n";

global $wpdb;
$table = $wpdb->prefix . 'sb_bookings';
$extras_table = $wpdb->prefix . 'sb_booking_extras';
$repo = new \SpaceBooking\Services\BookingRepository();

$test_date = date('Y-m-d', strtotime('+1 day'));

function create_test_extra(): int
{
    $extra_id = wp_insert_post([
        'post_type' => 'sb_extra',
        'post_status' => 'publish',
        'post_title' => 'Test Extra Validation Item',
    ]);

    if (!is_wp_error($extra_id) && $extra_id > 0) {
        update_post_meta($extra_id, '_sb_extra_price', 25.0);
        return (int) $extra_id;
    }

    return 0;
}

// ============================================================
// TEST 1: Booking WITHOUT extras returns empty extras
// ============================================================
echo "=== TEST 1: No Extras Returns Empty ===\n";

$no_extras_id = $repo->create([
    'space_id' => 101,
    'customer_name' => 'No Extras Customer',
    'customer_email' => 'noextras@test.com',
    'booking_date' => $test_date,
    'start_time' => '10:00',
    'end_time' => '11:00',
    'status' => 'confirmed',
]);

echo "Created booking ID $no_extras_id without extras\n";

// Get enriched
$booking = $repo->findEnriched($no_extras_id);
$extras = $booking['extras'] ?? [];
$extras_count = is_array($extras) ? count($extras) : 0;

echo "Extras count: $extras_count\n";

$test_1_pass = $extras_count === 0;

echo 'TEST 1 RESULT: ' . ($test_1_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// TEST 2: Booking WITH extras returns correct extras
// ============================================================
echo "=== TEST 2: With Extras Returns Correct Data ===\n";

$valid_extra_id = create_test_extra();
if ($valid_extra_id <= 0) {
    echo "Could not create test extra post.\n";
}

$with_extras_id = $repo->create([
    'space_id' => 101,
    'customer_name' => 'With Extras Customer',
    'customer_email' => 'withextras@test.com',
    'booking_date' => $test_date,
    'start_time' => '14:00',
    'end_time' => '15:00',
    'status' => 'confirmed',
    'extras' => [
        ['extra_id' => $valid_extra_id, 'quantity' => 2],
    ],
]);

echo "Created booking ID $with_extras_id with extras\n";

// Get extras directly from table
$direct_extras = $repo->get_extras($with_extras_id);
echo 'Direct extras from table: ' . count($direct_extras) . "\n";

foreach ($direct_extras as $e) {
    echo "  - {$e['title']} x{$e['quantity']}\n";
}

// Get enriched
$booking_2 = $repo->findEnriched($with_extras_id);
$enriched_extras = $booking_2['extras'] ?? [];
$enriched_count = is_array($enriched_extras) ? count($enriched_extras) : 0;

echo "Enriched extras count: $enriched_count\n";

$test_2_pass = $valid_extra_id > 0 && count($direct_extras) === 1 && $enriched_count === 1;

echo 'TEST 2 RESULT: ' . ($test_2_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// TEST 3: Extra validation - invalid extra_id excluded
// ============================================================
echo "=== TEST 3: Invalid Extra ID Excluded ===\n";

// Insert invalid extra directly
$wpdb->insert($extras_table, [
    'booking_id' => $no_extras_id,
    'extra_id' => 999999,  // Invalid non-existent extra
    'quantity' => 1,
], ['%d', '%d', '%d']);

echo "Inserted invalid extra_id 999999\n";

// Get enriched - should not include invalid
$booking_3 = $repo->findEnriched($no_extras_id);
$valid_extras = $booking_3['extras'] ?? [];
$valid_extras_count = is_array($valid_extras) ? count($valid_extras) : 0;

echo "Valid extras count after validation: $valid_extras_count\n";

$test_3_pass = $valid_extras_count === 0;  // Invalid should be filtered out

echo 'TEST 3 RESULT: ' . ($test_3_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// TEST 4: get_extras returns title from post
// ============================================================
echo "=== TEST 4: Get Extras Includes Title ===\n";

$test_4_pass = !empty($direct_extras) && isset($direct_extras[0]['title']);
echo 'First extra title: ' . ($direct_extras[0]['title'] ?? '(none)') . "\n";

echo 'TEST 4 RESULT: ' . ($test_4_pass ? 'PASS' : 'FAIL') . "\n\n";

// ============================================================
// CLEANUP
// ============================================================
echo "=== Cleanup ===\n";
$wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id IN (%d, %d)", $no_extras_id, $with_extras_id));
$wpdb->query($wpdb->prepare("DELETE FROM $extras_table WHERE booking_id IN (%d, %d)", $no_extras_id, $with_extras_id));
if (!empty($valid_extra_id)) {
    wp_delete_post($valid_extra_id, true);
}
echo "Done.\n\n";

// ============================================================
// SUMMARY
// ============================================================
echo "=== SUMMARY ===\n";
echo 'Test 1 (No Extras Empty): ' . ($test_1_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 2 (With Extras Data): ' . ($test_2_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 3 (Invalid Excluded): ' . ($test_3_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 4 (Title Included): ' . ($test_4_pass ? 'PASS' : 'FAIL') . "\n";

$all_pass = $test_1_pass && $test_2_pass && $test_3_pass && $test_4_pass;
echo "\nOVERALL: " . ($all_pass ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED') . "\n";
exit($all_pass ? 0 : 1);
