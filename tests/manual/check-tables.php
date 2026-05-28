<?php
/** Quick test to check if tables exist */
require_once '../../../wp-load.php';

global $wpdb;

$tables = [
    'sb_bookings',
    'sb_booking_extras',
    'sb_booking_spaces',
    'sb_booking_packages',
    'sb_booking_meta'
];

echo "Checking tables:\n";
echo str_repeat('-', 50) . "\n";

foreach ($tables as $table) {
    $full_table = $wpdb->prefix . $table;
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $full_table));
    $status = $exists ? '✅ EXISTS' : '❌ MISSING';
    echo "$table: $status\n";
}

echo "\nDone.\n";
