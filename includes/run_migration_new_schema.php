<?php

/**
 * One-time script to create missing booking tables.
 * Run via browser: http://localhost/kukoolala/wp-content/plugins/space-booking/includes/run_migration_new_schema.php
 * Or via CLI: php includes/run_migration_new_schema.php
 */

// Load WordPress
require_once '../../../wp-load.php';

require_once 'Migrations/CreateBookingSpacesTable.php';

echo "Starting migration: CreateBookingSpacesTable\n";

// Run the migration
$migration = new \SpaceBooking\Migrations\CreateBookingSpacesTable();
$migration->run();

echo "Migration completed. The following tables should now exist:\n";
echo "- wp_sb_booking_spaces\n";
echo "- wp_sb_booking_packages\n";
echo "- wp_sb_booking_meta\n";
echo "- wp_sb_booking_extras (updated with start_time/end_time)\n";
echo "\nCheck debug.log for details.\n";
