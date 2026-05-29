<?php declare(strict_types=1);

namespace SpaceBooking\Migrations;

/**
 * Migration: Add 'trashed' and 'deleted' to booking status enum.
 */
class AddTrashStatuses
{
    public function run(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sb_bookings';
        $column = 'status';

        $current_enum = $wpdb->get_var($wpdb->prepare(
            'SELECT COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s
               AND TABLE_NAME = %s
               AND COLUMN_NAME = %s',
            $wpdb->dbname,
            $table,
            $column
        ));

        if (false === $current_enum) {
            return false;
        }

        $has_trashed = strpos((string) $current_enum, "'trashed'") !== false;
        $has_deleted = strpos((string) $current_enum, "'deleted'") !== false;
        if ($has_trashed && $has_deleted) {
            return true;
        }

        $new_enum = "ENUM('pending','confirmed','cancelled','refunded','in_review','trashed','deleted') NOT NULL DEFAULT 'pending'";
        $result = $wpdb->query("
            ALTER TABLE `{$table}`
            MODIFY COLUMN `{$column}` {$new_enum}
        ");

        if ($result) {
            error_log("SpaceBooking migration: Added 'trashed' and 'deleted' to sb_bookings.status ENUM.");
        } else {
            error_log('SpaceBooking migration: Failed to add trash statuses to sb_bookings.status ENUM.');
        }

        return (bool) $result;
    }
}
