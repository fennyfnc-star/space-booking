<?php
/** Debug the exact SQL query being used for blocking intervals */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;

$space_ids = [224];
$date = '2026-05-10';

echo "=== Testing get_blocking_intervals for space 224 on $date ===\n\n";

$space_ids_placeholder = implode(',', array_fill(0, count($space_ids), '%d'));
$space_ids_params = $space_ids;

// Copy the exact query from BookingRepository
$query = $wpdb->prepare("
    SELECT start_time as start, end_time as end
    FROM {$wpdb->prefix}sb_bookings
    WHERE space_id IN ({$space_ids_placeholder}) 
    AND booking_date = %s 
    AND (
        status IN ('confirmed', 'in_review')
        OR (status = 'pending' AND (expired_at > NOW() OR expired_at = '0000-00-00 00:00:00'))
    )
    ORDER BY start_time",
    ...array_merge($space_ids_params, [$date]));

echo "QUERY:\n$query\n\n";

$results = $wpdb->get_results($query, ARRAY_A);
echo 'RESULTS count: ' . count($results) . "\n";
foreach ($results as $r) {
    echo "  {$r['start']} - {$r['end']}\n";
}

// Check raw bookings
echo "\n--- All bookings on 224 ---\n";
$all = $wpdb->get_results($wpdb->prepare(
    "SELECT id, status, expired_at, start_time, end_time FROM {$wpdb->prefix}sb_bookings WHERE space_id = %d AND booking_date = %s",
    224, $date
), ARRAY_A);

foreach ($all as $b) {
    echo "ID {$b['id']}: status={$b['status']}, expired_at={$b['expired_at']}, {$b['start_time']}-{$b['end_time']}\n";
}

echo "\n=== END ===\n";
