<?php declare(strict_types=1);

namespace SpaceBooking\Migrations;

/**
 * Migration: Add 'in_review' to status ENUM in sb_bookings table.
 *
 * Why: Adds a mid-lifecycle state for booking approvals (e.g., manual review before confirmation).
 *
 * Safety Note: Modifying MySQL ENUMs requires re-listing ALL existing values to prevent data loss.
 *              Always check current definition before ALTER.
 *
 * Introduced in plugin version 1.1.0.
 *
 * For future AI agents/developers: Follow idempotency pattern (INFORMATION_SCHEMA check).
 * Run via Installer::create_tables() on activation/upgrade.
 */
class AddInReviewStatus
{
    public function run(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sb_bookings';
        $column = 'status';

        // 1. Check current ENUM definition for 'in_review'
        $current_enum = $wpdb->get_var($wpdb->prepare(
            'SELECT COLUMN_TYPE 
             FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = %s 
               AND TABLE_NAME = %s 
               AND COLUMN_NAME = %s',
            $wpdb->dbname, $table, $column
        ));

        if (false === $current_enum || strpos($current_enum, "'in_review'") !== false) {
            return true;  // Already exists or error
        }

        // 2. Safe ALTER: Re-list ALL existing + new value
        $new_enum = "ENUM('pending','confirmed','cancelled','refunded','in_review') NOT NULL DEFAULT 'pending'";
        $result = $wpdb->query("
            ALTER TABLE `{$table}`
            MODIFY COLUMN `{$column}` {$new_enum}
        ");

        if ($result) {
            error_log("SpaceBooking migration: Added 'in_review' to sb_bookings.status ENUM.");
        } else {
            error_log('SpaceBooking migration: Failed to update sb_bookings.status ENUM.');
        }

        return (bool) $result;
    }
}
