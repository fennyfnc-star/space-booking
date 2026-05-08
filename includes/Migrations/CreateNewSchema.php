<?php declare(strict_types=1);

namespace SpaceBooking\Migrations;

/**
 * Create new schema tables:
 * - sb_bookings (simplified - core booking with status)
 * - sb_booking_spaces (links spaces to bookings)
 * - updated sb_booking_extras (adds time slot tracking)
 * - sb_booking_packages (packages include spaces)
 */
final class CreateNewSchema
{
    public function run(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Create new sb_bookings table (simplified - no space_id per row)
        // This will be a fresh table for the new architecture
        $sql_bookings = "CREATE TABLE IF NOT EXISTS {$prefix}sb_bookings_new (
\t\t\tid                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
\t\t\tcustomer_name       VARCHAR(191)    NOT NULL,
\t\t\tcustomer_email     VARCHAR(191)    NOT NULL,
\t\t\tcustomer_phone    VARCHAR(50)     DEFAULT NULL,
\t\t\tbooking_date       DATE            NOT NULL,
\t\t\tstart_time         TIME            NOT NULL,
\t\t\tend_time          TIME            NOT NULL,
\t\t\tduration_hours    DECIMAL(4,2)    NOT NULL,
\t\t\tbase_price       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
\t\t\textras_price     DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
\t\t\tmodifier_price  DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
\t\t\ttotal_price      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
\t\t\tstatus          ENUM('pending','in_review','confirmed','cancelled','refunded') NOT NULL DEFAULT 'pending',
\t\t\tstripe_pi_id     VARCHAR(191)    DEFAULT NULL,
\t\t\tnotes            TEXT            DEFAULT NULL,
\t\t\tlookup_token     VARCHAR(64)     DEFAULT NULL,
\t\t\ttoken_expires    DATETIME        DEFAULT NULL,
\t\t\tmarketing_source VARCHAR(191)    DEFAULT NULL,
\t\t\tcreated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
\t\t\tupdated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
\t\t\tPRIMARY KEY    (id),
\t\t\tKEY idx_email         (customer_email),
\t\t\tKEY idx_status        (status),
\t\t\tKEY idx_lookup_token  (lookup_token),
\t\t\tKEY idx_date_status  (booking_date, status)
\t\t) $charset;";

        dbDelta($sql_bookings);

        // 2. Create sb_booking_spaces table (links spaces to bookings)
        $sql_booking_spaces = "CREATE TABLE IF NOT EXISTS {$prefix}sb_booking_spaces (
\t\t\tid              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
\t\t\tbooking_id      BIGINT UNSIGNED NOT NULL,
\t\t\tspace_id        BIGINT UNSIGNED NOT NULL,
\t\t\tpackage_id     BIGINT UNSIGNED DEFAULT NULL,
\t\t\tstart_time     TIME            NOT NULL,
\t\t\tend_time      TIME            NOT NULL,
\t\t\tcreated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
\t\t\tPRIMARY KEY  (id),
\t\t\tKEY idx_booking     (booking_id),
\t\t\tKEY idx_space      (space_id),
\t\t\tKEY idx_space_date (space_id, start_time, end_time)
\t\t) $charset;";

        dbDelta($sql_booking_spaces);

        // 3. Update sb_booking_extras table - add start_time/end_time columns
        // Check if columns exist first
        $extras_columns = $wpdb->get_results("DESCRIBE {$prefix}sb_booking_extras", ARRAY_A);
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
            $wpdb->query("ALTER TABLE {$prefix}sb_booking_extras ADD COLUMN start_time TIME NOT NULL AFTER extra_id");
        }

        if (!$has_end_time) {
            $wpdb->query("ALTER TABLE {$prefix}sb_booking_extras ADD COLUMN end_time TIME NOT NULL AFTER start_time");
        }

        // 4. Create sb_booking_packages table (packages include spaces)
        $sql_booking_packages = "CREATE TABLE IF NOT EXISTS {$prefix}sb_booking_packages (
\t\t\tid            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
\t\t\tbooking_id  BIGINT UNSIGNED NOT NULL,
\t\t\tpackage_id BIGINT UNSIGNED NOT NULL,
\t\t\tspace_id   BIGINT UNSIGNED NOT NULL,
\t\t\tcreated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
\t\t\tPRIMARY KEY (id),
\t\t\tKEY idx_booking  (booking_id),
\t\t\tKEY idx_package (package_id),
\t\t\tKEY idx_space   (space_id)
\t\t) $charset;";

        dbDelta($sql_booking_packages);

        // Log success
        error_log('SB Migration: New schema created successfully');
    }
}
