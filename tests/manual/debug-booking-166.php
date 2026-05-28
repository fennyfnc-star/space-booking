<?php

/**
 * Debug script: Check booking 166 data - Search broader
 * Run via browser or command line
 */

// Load WordPress bootstrap
require_once dirname(__DIR__, 5) . '/wp-load.php';

echo "=== Debug: Search for booking 166 ===\n\n";

global $wpdb;
$table = $wpdb->prefix . 'sb_bookings';

// Query for booking 166 - try direct ID search
$rows = $wpdb->get_results("SELECT * FROM $table WHERE id = 166", ARRAY_A);

echo 'Direct ID search: Found ' . count($rows) . " row(s)\n";
foreach ($rows as $row) {
    echo "ID: {$row['id']}, space_id: {$row['space_id']}, status: {$row['status']}\n";
}

// Try finding by any booking from 2026-05-16 with in_review status
$rows2 = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table WHERE booking_date = %s AND status = 'in_review' ORDER BY id DESC LIMIT 10",
    '2026-05-16'
), ARRAY_A);

echo "\n\nin_review bookings on 2026-05-16: " . count($rows2) . " row(s)\n";
foreach ($rows2 as $row) {
    echo "ID: {$row['id']}, space_id: {$row['space_id']}, status: {$row['status']}, time: {$row['start_time']}-{$row['end_time']}\n";
}

// Search for most recent bookings
$recent = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 5", ARRAY_A);
echo "\n\nMost recent bookings: " . count($recent) . "\n";
foreach ($recent as $row) {
    echo "ID: {$row['id']}, space_id: {$row['space_id']}, status: {$row['status']}, date: {$row['booking_date']}\n";
}

// Check meta for booking 166
$meta_table = $wpdb->prefix . 'sb_booking_meta';
$meta_rows = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $meta_table WHERE booking_id = %d",
    166
), ARRAY_A);

echo "\n\nMeta for booking 166: " . count($meta_rows) . " row(s)\n";
foreach ($meta_rows as $meta) {
    echo "  {$meta['meta_key']}: {$meta['meta_value']}\n";
}
