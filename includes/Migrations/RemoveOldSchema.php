<?php declare(strict_types=1);

namespace SpaceBooking\Migrations;

/**
 * Remove old schema columns that are no longer needed after migration to new structure.
 * - parent_booking_id (replaced by sb_booking_spaces)
 * - order_id (replaced by booking_id in linking tables)
 * - Remove 'shadow' status from ENUM
 */
final class RemoveOldSchema
{
    public function run(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sb_bookings';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Remove parent_booking_id column if exists
        $columns = $wpdb->get_results("DESCRIBE {$table}", ARRAY_A);
        $has_parent = false;
        $has_order_id = false;

        foreach ($columns as $col) {
            if ($col['Field'] === 'parent_booking_id') {
                $has_parent = true;
            }
            if ($col['Field'] === 'order_id') {
                $has_order_id = true;
            }
        }

        if ($has_parent) {
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN parent_booking_id");
            error_log('SB Migration: Dropped parent_booking_id column');
        }

        if ($has_order_id) {
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN order_id");
            error_log('SB Migration: Dropped order_id column');
        }

        // 2. Remove 'shadow' from status ENUM
        $current_enum = $wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'status'");
        if ($current_enum && preg_match("/enum\('([^)]+)'\)", $current_enum, $matches)) {
            $old_values = explode("','", $matches[1]);
            $new_values = array();
            foreach ($old_values as $v) {
                if ($v !== 'shadow') {
                    $new_values[] = $v;
                }
            }

            if (count($new_values) < count($old_values)) {
                $new_enum = "ENUM('" . implode("','", $new_values) . "')";
                $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN status {$new_enum} NOT NULL DEFAULT 'pending'");
                error_log('SB Migration: Removed shadow from status ENUM');
            }
        }

        // 3. Clean up the sb_bookings_new table if it exists (from CreateNewSchema)
        $new_table = $wpdb->prefix . 'sb_bookings_new';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$new_table}'");

        if ($table_exists) {
            error_log('SB Migration: sb_bookings_new table exists - can be dropped after migration verification');
        }

        error_log('SB Migration: Old schema cleanup completed');
    }
}
