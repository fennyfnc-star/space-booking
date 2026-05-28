<?php
/** Fixed migration runner. */
require_once '../../../wp-load.php';

require_once __DIR__ . '/Installer.php';
require_once __DIR__ . '/Migrations/AddExpiredAt.php';

$installer = new \SpaceBooking\Installer();
$installer->create_tables();

echo "Migration run complete. Check for expired_at column.\n";