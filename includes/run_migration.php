<?php

/**
 * One-time script to run migrations.
 * Run: php includes/run_migration.php
 */
require_once '../../../wp-load.php';

require_once 'Installer.php';
require_once 'Migrations/AddExpiredAt.php';

(new \SpaceBooking\Installer())->create_tables();  // Triggers dbDelta + migration

echo "Migration completed. Check wp_sb_bookings table for 'expired_at' column.\n";