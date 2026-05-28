<?php declare(strict_types=1);

namespace SpaceBooking\Migrations;

use SpaceBooking\Services\BookingRepository;

/**
 * Migration: Add parent_booking_id column to sb_bookings table for shadow blocks.
 */
class AddParentBookingId
{
    public function run(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sb_bookings';
        $column = 'parent_booking_id';

        // Check if column exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s
               AND TABLE_NAME = %s
               AND COLUMN_NAME = %s",
            $wpdb->dbname, $table, $column
        ));

        if ((int) $exists > 0) {
            return true;
        }

        // Add column
        $result = $wpdb->query("
            ALTER TABLE `{$table}`
            ADD COLUMN `{$column}` BIGINT UNSIGNED NULL DEFAULT NULL
                AFTER `status`,
            ADD INDEX idx_parent (parent_booking_id)
        ");

        return (bool) $result;
    }
}
