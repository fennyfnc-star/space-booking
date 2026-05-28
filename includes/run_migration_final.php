<?php
require_once '../../../wp-load.php';

global $wpdb;

$table = $wpdb->prefix . 'sb_bookings';
$column = 'expired_at';

$exists = $wpdb->get_var($wpdb->prepare('
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = %s
    AND COLUMN_NAME = %s
', $table, $column));

if ($exists > 0) {
    echo "expired_at column already exists.\n";
} else {
    $result = $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `expired_at` DATETIME NULL AFTER `status`");
    if ($result) {
        echo "expired_at column added successfully.\n";
    } else {
        echo 'Failed to add column: ' . $wpdb->last_error . "\n";
    }
}