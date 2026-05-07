<?php
/** Check schema and add order_id if missing */
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$table = $wpdb->prefix . 'sb_bookings';

// Check if order_id column exists
$columns = $wpdb->get_results("DESCRIBE $table", ARRAY_A);
$has_order_id = false;

echo "Current columns:\n";
foreach ($columns as $col) {
    echo "  - {$col['Field']}: {$col['Type']}\n";
    if ($col['Field'] === 'order_id') {
        $has_order_id = true;
    }
}

if (!$has_order_id) {
    echo "\nAdding order_id column...\n";
    $result = $wpdb->query("ALTER TABLE $table ADD COLUMN order_id INT UNSIGNED NULL DEFAULT NULL AFTER package_id");
    if ($result !== false) {
        echo "Added order_id column successfully.\n";
    } else {
        echo 'Failed to add order_id: ' . $wpdb->last_error . "\n";
    }
} else {
    echo "\norder_id column already exists.\n";
}

// Also add parent_booking_id column if missing
$has_parent = false;
foreach ($columns as $col) {
    if ($col['Field'] === 'parent_booking_id') {
        $has_parent = true;
    }
}

if (!$has_parent) {
    echo "\nAdding parent_booking_id column...\n";
    $result = $wpdb->query("ALTER TABLE $table ADD COLUMN parent_booking_id INT UNSIGNED NULL DEFAULT NULL AFTER order_id");
    if ($result !== false) {
        echo "Added parent_booking_id column successfully.\n";
    } else {
        echo 'Failed to add parent_booking_id: ' . $wpdb->last_error . "\n";
    }
} else {
    echo "\nparent_booking_id column already exists.\n";
}

echo "\nDone.\n";
