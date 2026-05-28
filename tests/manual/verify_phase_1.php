<?php

/**
 * Phase 1 Verification Test
 *
 * Tests that booking for 3 spaces [10, 223, 224] creates 3 rows in database.
 * Expected: 3 rows with distinct space_ids sharing same order_id.
 */

// Bootstrap WordPress
require_once __DIR__ . '/bootstrap.php';

global $wpdb;

echo "=== Phase 1 Verification Test ===\n\n";

// Test with dummy order_id
$test_order_id = 9999;
$space_ids = [10, 223, 224];
$date = date('Y-m-d');
$start_time = '14:00:00';
$end_time = '15:00:00';

echo 'Test: Creating booking for spaces: ' . implode(', ', $space_ids) . "\n";
echo "Order ID (test): $test_order_id\n";
echo "Date: $date, Time: $start_time - $end_time\n\n";

// Insert rows directly using BookingRepository method
$repo = new SpaceBooking\Services\BookingRepository();

// First, let's check if these spaces exist
foreach ($space_ids as $sid) {
    $post = get_post($sid);
    if ($post) {
        echo "Space $sid exists: " . $post->post_title . "\n";
    } else {
        echo "Space $sid does NOT exist!\n";
    }
}

echo "\n--- Creating booking rows ---\n";

// Create row for each space_id
foreach ($space_ids as $space_id) {
    try {
        $row_id = $repo->create_booking_row([
            'space_id' => $space_id,
            'package_id' => null,
            'order_id' => $test_order_id,
            'booking_date' => $date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'status' => 'pending',
            'expired_at' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        echo "Created row ID $row_id for space_id $space_id\n";
    } catch (RuntimeException $e) {
        echo "ERROR creating row for space $space_id: " . $e->getMessage() . "\n";
    }
}

echo "\n--- RAW SQL Query Results ---\n";

// Direct SQL query to verify
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT id, space_id, order_id, booking_date, start_time, end_time, status 
     FROM {$wpdb->prefix}sb_bookings 
     WHERE order_id = %d 
     ORDER BY id",
    $test_order_id
), ARRAY_A);

if (empty($results)) {
    echo "ERROR: No rows found with order_id = $test_order_id\n";
} else {
    echo 'Found ' . count($results) . " row(s):\n\n";
    foreach ($results as $row) {
        echo "row: id={$row['id']}, space_id={$row['space_id']}, order_id={$row['order_id']}, date={$row['booking_date']}, status={$row['status']}\n";
    }
}

echo "\n--- Verification ---\n";
$count = count($results);
echo "COUNT: $count\n";
if ($count === 3) {
    echo "✓ PASS: Phase 1 verified - 3 rows created\n";
} else {
    echo "✗ FAIL: Expected 3 rows, got $count\n";
}

// Cleanup - delete test rows
if (!empty($results)) {
    foreach ($results as $row) {
        $wpdb->delete($wpdb->prefix . 'sb_bookings', ['id' => $row['id']], ['%d']);
    }
    echo "\n(Cleaned up test rows)\n";
}

echo "\n=== End Test ===\n";
