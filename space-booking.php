<?php

/**
 * Plugin Name:       Space Booking
 * Plugin URI:        https://example.com/space-booking
 * Description:       Hourly space rental & shared asset booking plugin with WooCommerce payments.
 * Requires Plugins:   woocommerce
 * Version:           2.2.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Senior WP Architect
 * License:           GPL-2.0-or-later
 * Text Domain:       space-booking
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

// ── Constants ────────────────────────────────────────────────────────────────
define('SB_VERSION', '1.0.0');
define('SB_FILE', __FILE__);
define('SB_DIR', plugin_dir_path(__FILE__));
define('SB_URL', plugin_dir_url(__FILE__));
define('SB_ASSETS_URL', SB_URL . 'assets/');
define('SB_PLUGIN_SLUG', 'space-booking');

if (file_exists(SB_DIR . 'plugin-update-checker/plugin-update-checker.php')) {
	require SB_DIR . 'plugin-update-checker/plugin-update-checker.php';
	if (class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
		$myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/xzud/space-booking/',
			__FILE__,
			'space-booking'
		);
		$myUpdateChecker->setBranch('main');
	}
}

// ── Autoloader ───────────────────────────────────────────────────────────────
spl_autoload_register(static function (string $class): void {
	$prefix = 'SpaceBooking\\';
	$base = SB_DIR . 'includes/';

	if (!str_starts_with($class, $prefix)) {
		return;
	}

	$relative = str_replace('\\', '/', substr($class, strlen($prefix)));
	$file = $base . $relative . '.php';

	if (is_readable($file)) {
		require $file;
	}
});

// ── Admin AJAX ────────────────────────────────────────────────────────────────
if (is_admin()) {
	require_once SB_DIR . 'includes/Admin/ajax-handlers.php';
}

// ── Remove WordPress admin footer text ───────────────────────────
add_filter('admin_footer_text', '__return_empty_string');
add_filter('update_footer', '__return_empty_string');

// ── Bootstrap ────────────────────────────────────────────────────────────────
add_action('plugins_loaded', static function (): void {
	\SpaceBooking\Plugin::instance()->boot();
});

// ── Cron for cleaning expired bookings ────────────────────────────────
add_action('sb_cleanup_expired_bookings', function () {
	$repo = new \SpaceBooking\Services\BookingRepository();
	$deleted = $repo->cleanup_expired();
	error_log('SpaceBooking cron: Cleaned ' . $deleted . ' expired pending bookings');
});

// Schedule cron on activation
register_activation_hook(__FILE__, function () {
	if (!wp_next_scheduled('sb_cleanup_expired_bookings')) {
		wp_schedule_event(time(), 'hourly', 'sb_cleanup_expired_bookings');
	}
});

// Clear cron on deactivation
register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook('sb_cleanup_expired_bookings');
});

register_activation_hook(__FILE__, [\SpaceBooking\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [\SpaceBooking\Installer::class, 'deactivate']);