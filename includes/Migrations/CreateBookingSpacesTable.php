<?php declare(strict_types=1);

namespace SpaceBooking\Migrations;

/**
 * Create booking spaces and packages tables if they don't exist.
 * This fixes the issue where wp_sb_booking_spaces table was not created during plugin activation.
 *
 * Tables created:
 * - wp_sb_booking_spaces (links spaces to bookings)
 * - wp_sb_booking_packages (packages include spaces)
 */
final class CreateBookingSpacesTable
{
    public function run(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Create sb_booking_spaces table if it doesn't exist
        $table_spaces = $prefix . 'sb_booking_spaces';
        $spaces_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_spaces'");

        if (!$spaces_exists) {
            $sql_booking_spaces = "CREATE TABLE IF NOT EXISTS {$table_spaces} (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id      BIGINT UNSIGNED NOT NULL,
                space_id        BIGINT UNSIGNED NOT NULL,
                package_id     BIGINT UNSIGNED DEFAULT NULL,
                start_time     TIME            NOT NULL,
                end_time      TIME            NOT NULL,
                created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY idx_booking     (booking_id),
                KEY idx_space      (space_id),
                KEY idx_space_date (space_id, start_time, end_time)
            ) $charset;";

            dbDelta($sql_booking_spaces);
            error_log('SB Migration: Created sb_booking_spaces table');
        } else {
            error_log('SB Migration: sb_booking_spaces table already exists');
        }

        // 2. Create sb_booking_packages table if it doesn't exist
        $table_packages = $prefix . 'sb_booking_packages';
        $packages_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_packages'");

        if (!$packages_exists) {
            $sql_booking_packages = "CREATE TABLE IF NOT EXISTS {$table_packages} (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id  BIGINT UNSIGNED NOT NULL,
                package_id BIGINT UNSIGNED NOT NULL,
                space_id   BIGINT UNSIGNED NOT NULL,
                created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_booking  (booking_id),
                KEY idx_package (package_id),
                KEY idx_space   (space_id)
            ) $charset;";

            dbDelta($sql_booking_packages);
            error_log('SB Migration: Created sb_booking_packages table');
        } else {
            error_log('SB Migration: sb_booking_packages table already exists');
        }

        // 3. Ensure sb_booking_extras has start_time/end_time columns (for new schema)
        $extras_table = $prefix . 'sb_booking_extras';
        $extras_columns = $wpdb->get_results("DESCRIBE {$extras_table}", ARRAY_A);
        $has_start_time = false;
        $has_end_time = false;

        foreach ($extras_columns as $col) {
            if ($col['Field'] === 'start_time') {
                $has_start_time = true;
            }
            if ($col['Field'] === 'end_time') {
                $has_end_time = true;
            }
        }

        if (!$has_start_time) {
            $wpdb->query("ALTER TABLE {$extras_table} ADD COLUMN start_time TIME NOT NULL AFTER extra_id");
            error_log('SB Migration: Added start_time column to sb_booking_extras');
        }

        if (!$has_end_time) {
            $wpdb->query("ALTER TABLE {$extras_table} ADD COLUMN end_time TIME NOT NULL AFTER start_time");
            error_log('SB Migration: Added end_time column to sb_booking_extras');
        }

        // 4. Ensure sb_booking_meta table exists
        $meta_table = $prefix . 'sb_booking_meta';
        $meta_exists = $wpdb->get_var("SHOW TABLES LIKE '$meta_table'");

        if (!$meta_exists) {
            $sql_booking_meta = "CREATE TABLE IF NOT EXISTS {$meta_table} (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id  BIGINT UNSIGNED NOT NULL,
                meta_key   VARCHAR(191)   NOT NULL,
                meta_value LONGTEXT       DEFAULT NULL,
                created_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_booking (booking_id),
                KEY idx_meta_key (meta_key)
            ) $charset;";

            dbDelta($sql_booking_meta);
            error_log('SB Migration: Created sb_booking_meta table');
        }

        error_log('SB Migration: CreateBookingSpacesTable completed successfully');
    }
}
