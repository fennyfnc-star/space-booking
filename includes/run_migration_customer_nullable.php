<?php

/**
 * Run migration to make customer_name and customer_email nullable.
 *
 * Run: php includes/run_migration_customer_nullable.php
 * Or access: /wp-admin/?sb_run_migration=customer_nullable
 */
if (defined('ABSPATH')) {
    // WordPress context
    require_once __DIR__ . '/Migrations/MakeCustomerFieldsOptional.php';
    $result = \SpaceBooking\Migrations\MakeCustomerFieldsOptional::run();
    echo $result ? "Migration complete: customer_name and customer_email are now nullable.\n" : "Migration failed.\n";
} else {
    // CLI context
    require_once __DIR__ . '/../../../wp-load.php';
    require_once __DIR__ . '/Migrations/MakeCustomerFieldsOptional.php';
    $result = \SpaceBooking\Migrations\MakeCustomerFieldsOptional::run();
    echo $result ? "Migration complete: customer_name and customer_email are now nullable.\n" : "Migration failed.\n";
}