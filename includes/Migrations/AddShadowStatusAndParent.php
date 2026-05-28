<?php declare(strict_types=1);

namespace SpaceBooking\Migrations;

use SpaceBooking\Services\AvailabilityService;

/**
 * Migration: Add 'shadow' status to ENUM and ensure parent_booking_id for shadow bookings.
 */
class AddShadowStatusAndParent
{
    public function run(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sb_bookings';

        // 1. Add parent_booking_id if missing ( idempotent check )
        $parent_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'parent_booking_id'",
            $wpdb->dbname, $table
        ));

        if (!$parent_exists) {
            $wpdb->query("
                ALTER TABLE `{$table}` 
                ADD COLUMN `parent_booking_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `status`,
                ADD INDEX `idx_parent` (`parent_booking_id`)
            ") || error_log('SB Migration: parent_booking_id add failed');
        }

        // 2. Modify ENUM to add 'shadow' - MySQL requires DROP + ADD (safe with data)
        $current_enum = $wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'status'");
        if (preg_match("/ENUM\('([^']*)'\)/", $current_enum, $matches)) {
            $old_enum = str_replace("'", "''", $matches[1]);  // escape single quotes
            $new_enum = str_replace("'", "''", "pending','confirmed','cancelled','refunded','shadow");
            $wpdb->query("
                ALTER TABLE `{$table}` 
                MODIFY COLUMN `status` ENUM('pending','confirmed','cancelled','refunded','shadow') NOT NULL DEFAULT 'pending'
            ") || error_log('SB Migration: status enum update failed. Current: ' . $current_enum);
        }

        return true;
    }
}
