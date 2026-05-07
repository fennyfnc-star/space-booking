<?php declare(strict_types=1);

namespace SpaceBooking\Migrations;

/**
 * Migration: Add order_id column to sb_bookings table.
 * This is needed for multi-space bookings to link related rows.
 */
class AddOrderId
{
    public function run(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sb_bookings';
        $column = 'order_id';

        // Check if column exists
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s
               AND TABLE_NAME = %s
               AND COLUMN_NAME = %s',
            $wpdb->dbname, $table, $column
        ));

        if ((int) $exists > 0) {
            return true;  // Already exists
        }

        // Add column after package_id
        $result = $wpdb->query("
            ALTER TABLE `{$table}`
            ADD COLUMN `{$column}` BIGINT UNSIGNED DEFAULT NULL
                AFTER `package_id`
        ");

        if ($result) {
            // Add index for faster lookups of related booking rows
            $wpdb->query("
                ALTER TABLE `{$table}`
                ADD INDEX `idx_order_id` (`order_id`)
            ");
            error_log('AddOrderId migration: Added order_id column with index.');
        }

        return (bool) $result;
    }
}
