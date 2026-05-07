<?php

/**
 * Bootstrap for Pest Tests
 * Loads WordPress and the plugin for testing
 */
define('ABSPATH', 'C:/xampp/htdocs/kukoolala/');
define('WP_DEBUG', true);

// Load WordPress
require_once ABSPATH . 'wp-load.php';

// Load the plugin manually since we're not activating it
require_once dirname(__FILE__) . '/../space-booking.php';
require_once dirname(__FILE__) . '/../includes/Plugin.php';

// Bootstrap the plugin
\spaceBooking\Plugin::instance()->boot();

// Make global $wpdb available
global $wpdb;

// Set table names
$wpdb->sb_bookings = $wpdb->prefix . 'sb_bookings';
$wpdb->sb_booking_extras = $wpdb->prefix . 'sb_booking_extras';
$wpdb->sb_booking_meta = $wpdb->prefix . 'sb_booking_meta';
