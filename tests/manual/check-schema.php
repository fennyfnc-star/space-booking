<?php
/** Check sb_booking_extras schema */
require_once '../../../wp-load.php';

global $wpdb;

$table = $wpdb->prefix . 'sb_booking_extras';
$columns = $wpdb->get_results("DESCRIBE $table", ARRAY_A);

echo "Schema for $table:\n";
echo str_repeat('-', 50) . "\n";

foreach ($columns as $col) {
    echo $col['Field'] . ' - ' . $col['Type'] . "\n";
}

echo "\nDone.\n";
