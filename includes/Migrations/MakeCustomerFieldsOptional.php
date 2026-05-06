<?php declare(strict_types=1);

namespace SpaceBooking\Migrations;

/**
 * Make customer_name and customer_email nullable in sb_bookings table.
 * This allows bookings to be created without customer details, and they can be
 * synced from WooCommerce billing info after checkout.
 */
final class MakeCustomerFieldsOptional
{
    public static function run(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sb_bookings';

        // Check current column definitions
        $columns = $wpdb->get_results("DESCRIBE {$table}", ARRAY_A);
        $column_defs = [];
        foreach ($columns as $col) {
            $column_defs[$col['Field']] = $col['Type'] . ' ' . $col['Null'];
        }

        // Only alter if still NOT NULL
        if (isset($column_defs['customer_name']) && strpos($column_defs['customer_name'], 'NO') !== false) {
            $wpdb->query("ALTER TABLE {$table} MODIFY customer_name VARCHAR(191) NULL");
            error_log('SpaceBooking Migration: Made customer_name nullable');
        }

        if (isset($column_defs['customer_email']) && strpos($column_defs['customer_email'], 'NO') !== false) {
            $wpdb->query("ALTER TABLE {$table} MODIFY customer_email VARCHAR(191) NULL");
            error_log('SpaceBooking Migration: Made customer_email nullable');
        }

        return true;
    }
}