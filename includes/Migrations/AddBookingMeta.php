<?php declare(strict_types=1);

namespace SpaceBooking\Migrations;

use SpaceBooking\Installer;

/**
 * Migration: Add wp_sb_booking_meta table for custom fields like marketing_source
 */
final class AddBookingMeta
{
    public function run(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$wpdb->prefix}sb_booking_meta (
\t\t\tid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
\t\t\tbooking_id BIGINT UNSIGNED NOT NULL,
\t\t\tmeta_key VARCHAR(191) NOT NULL,
\t\t\tmeta_value LONGTEXT,
\t\t\tPRIMARY KEY (id),
\t\t\tKEY booking_id (booking_id),
\t\t\tKEY meta_key (meta_key)
\t\t) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Verify
        $table = $wpdb->prefix . 'sb_booking_meta';
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
        if ($exists) {
            update_option('sb_migration_booking_meta', '1.0');
        }
    }
}
