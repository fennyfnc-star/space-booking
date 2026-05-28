<?php
/** One-time migration runner */
require_once __DIR__ . '/Installer.php';

echo "Running AddBookingMeta migration...\n";

$wpdb = $GLOBALS['wpdb'] ?? null;
if (!$wpdb) {
    die('WPDB not available.');
}

try {
    $migration = new \SpaceBooking\Migrations\AddBookingMeta();
    $migration->run();
    echo "✅ AddBookingMeta migration complete.\n";
    echo "Check table: {$wpdb->prefix}sb_booking_meta\n";
} catch (Exception $e) {
    die('❌ Migration failed: ' . $e->getMessage() . "\n");
}
?>