<?php declare(strict_types=1);

namespace SpaceBooking\Migrations;

use SpaceBooking\Services\BookingRepository;

/**
 * Migration: Add expired_at column to sb_bookings table.
 */
class AddExpiredAt
{
    public function run(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sb_bookings';
        $column = 'expired_at';

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

        // Add column
        $result = $wpdb->query("
            ALTER TABLE `{$table}`
            ADD COLUMN `{$column}` DATETIME NULL DEFAULT NULL
                AFTER `status`
        ");

        if ($result) {
            // Backfill existing pending bookings
            $backfilled = $wpdb->query("
                UPDATE `{$table}` 
                SET `{$column}` = DATE_ADD(COALESCE(created_at, NOW()), INTERVAL 30 MINUTE)
                WHERE status = 'pending' 
                  AND (`{$column}` IS NULL OR `{$column}` = '0000-00-00 00:00:00')
            ");
            if (false !== $backfilled) {
                error_log("AddExpiredAt migration: Backfilled {$backfilled} pending bookings with expired_at.");
            }
        }

        return (bool) $result;
    }
}