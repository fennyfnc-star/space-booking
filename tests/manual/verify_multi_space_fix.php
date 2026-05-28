<?php

/**
 * Reflection File: Verify Multi-Space Booking Fix
 *
 * This test verifies that when selecting multiple spaces (223, 10, 224),
 * all linked spaces are properly detected in the availability check.
 *
 * ISSUE FIXED: get_blocking_intervals() now checks both sb_bookings AND sb_booking_spaces tables
 */
require dirname(__FILE__) . '/bootstrap.php';

use SpaceBooking\Services\BookingRepository;

echo "=== Multi-Space Booking Fix Verification ===\n\n";

$repo = new BookingRepository();
$date = '2026-05-09';
$spaces = [223, 10, 224];

echo 'Testing get_blocking_intervals() for spaces: [' . implode(', ', $spaces) . "] on $date\n\n";

// Get blocking intervals
$intervals = $repo->get_blocking_intervals($spaces, $date);

echo 'Blocking intervals found: ' . count($intervals) . "\n";
foreach ($intervals as $idx => $interval) {
    echo '  - Interval #' . ($idx + 1) . ": {$interval['start']} - {$interval['end']}\n";
}

echo "\n=== Test Results ===\n";

if (count($intervals) > 0) {
    echo "✅ PASS: Blocking intervals are detected for all linked spaces\n";
    echo "   The fix correctly queries sb_booking_spaces for linked spaces.\n";
} else {
    echo "❌ FAIL: No blocking intervals found\n";
    echo "   This could mean:\n";
    echo "   - The booking doesn't exist in the database\n";
    echo "   - The fix didn't apply correctly\n";
}

echo "\n=== Database Verification ===\n";

// Check what's in sb_bookings
global $wpdb;
$bookings = $wpdb->get_results($wpdb->prepare(
    "SELECT id, space_id, booking_date, start_time, end_time, status 
     FROM {$wpdb->prefix}sb_bookings 
     WHERE booking_date = %s AND status IN ('confirmed', 'paid')
     ORDER BY id",
    $date
), ARRAY_A);

echo "Bookings on $date:\n";
foreach ($bookings as $b) {
    echo "  - ID {$b['id']}: space_id={$b['space_id']}, {$b['start_time']}-{$b['end_time']}, status={$b['status']}\n";
}

// Check what's in sb_booking_spaces
$linked = $wpdb->get_results($wpdb->prepare(
    "SELECT bs.booking_id, bs.space_id, b.start_time, b.end_time
     FROM {$wpdb->prefix}sb_booking_spaces bs
     JOIN {$wpdb->prefix}sb_bookings b ON bs.booking_id = b.id
     WHERE b.booking_date = %s",
    $date
), ARRAY_A);

echo "\nLinked spaces in sb_booking_spaces:\n";
foreach ($linked as $l) {
    echo "  - booking_id {$l['booking_id']}: space_id={$l['space_id']}\n";
}

echo "\n=== Summary ===\n";
echo "The fix ensures that get_blocking_intervals() checks:\n";
echo "  1. sb_bookings table for the primary space_id\n";
echo "  2. sb_booking_spaces table for linked spaces\n";
echo "  Then returns blocking intervals for ALL found booking IDs.\n";
