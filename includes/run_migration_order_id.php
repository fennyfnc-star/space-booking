<?php

/**
 * Standalone migration to add order_id column.
 * Upload this file to your WordPress plugins directory and access it directly
 * to run the migration: /wp-content/plugins/space-booking/includes/run_migration_order_id.php
 *
 * After running, delete this file for security.
 */
if (!defined('ABSPATH')) {
    // Load WordPress if accessed directly
    $parse_uri = parse_url($_SERVER['REQUEST_URI']);
    if (empty($parse_uri['path']) || strpos($parse_uri['path'], 'plugin') === false) {
        require_once dirname(__DIR__, 3) . '/wp-blog-header.php';
    }
}

global $wpdb;

$table = $wpdb->prefix . 'sb_bookings';
$column = 'order_id';

// Check if column already exists
$exists = $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) 
     FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = %s 
       AND TABLE_NAME = %s 
       AND COLUMN_NAME = %s',
    $wpdb->dbname, $table, $column
));

if ((int) $exists > 0) {
    echo '<p style="color: green;">✓ order_id column already exists. No migration needed.</p>';
    return;
}

// Add column after package_id
$result = $wpdb->query("
    ALTER TABLE `{$table}` 
    ADD COLUMN `order_id` BIGINT UNSIGNED DEFAULT NULL 
        AFTER `package_id`
");

if ($result) {
    // Add index for faster lookups
    $wpdb->query("
        ALTER TABLE `{$table}` 
        ADD INDEX `idx_order_id` (`order_id`)
    ");
    echo '<p style="color: green;">✓ Successfully added order_id column with index.</p>';
} else {
    echo '<p style="color: red;">✗ Failed to add order_id column: ' . $wpdb->last_error . '</p>';
}
