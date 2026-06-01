<?php declare(strict_types=1);

namespace SpaceBooking;

/**
 * Handles plugin activation / deactivation / DB schema creation.
 */
final class Installer
{
	public static function activate(): void
	{
		if (!class_exists('WooCommerce')) {
			deactivate_plugins(SB_FILE);
			wp_die('Space Booking requires WooCommerce to be installed and activated first.');
		}
		self::create_tables();
		self::set_default_options();
		flush_rewrite_rules();
	}

	public static function deactivate(): void
	{
		flush_rewrite_rules();
	}

	// ── Schema ───────────────────────────────────────────────────────────────
	private static function create_tables(): void
	{
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// Bookings - NEW SCHEMA: Main booking table (no space_id per row)
		// Spaces are linked via sb_booking_spaces table
		$sql_bookings = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sb_bookings (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_name       VARCHAR(191)    NOT NULL,
			customer_email     VARCHAR(191)    NOT NULL,
			customer_phone    VARCHAR(50)     DEFAULT NULL,
			booking_date       DATE            NOT NULL,
			start_time         TIME            NOT NULL,
			end_time          TIME            NOT NULL,
			duration_hours  DECIMAL(4,2)    NOT NULL,
			base_price     DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			extras_price   DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			modifier_price DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			total_price   DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			status        ENUM('pending','in_review','confirmed','cancelled','refunded','trashed','deleted') NOT NULL DEFAULT 'pending',
			stripe_pi_id   VARCHAR(191)    DEFAULT NULL,
			notes          TEXT            DEFAULT NULL,
			lookup_token  VARCHAR(64)     DEFAULT NULL,
			token_expires DATETIME        DEFAULT NULL,
			marketing_source VARCHAR(191)  DEFAULT NULL,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_email        (customer_email),
			KEY idx_status       (status),
			KEY idx_lookup_token (lookup_token),
			KEY idx_date_status (booking_date, status)
		) $charset;";

		// Booking Extras (pivot)
		$sql_extras = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sb_booking_extras (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id  BIGINT UNSIGNED NOT NULL,
			extra_id    BIGINT UNSIGNED NOT NULL,
			quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			unit_price  DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			PRIMARY KEY (id),
			KEY idx_booking (booking_id),
			KEY idx_extra   (extra_id)
		) $charset;";

		// Booking Spaces (new table - links spaces to bookings)
		$sql_booking_spaces = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sb_booking_spaces (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id    BIGINT UNSIGNED NOT NULL,
			space_id      BIGINT UNSIGNED NOT NULL,
			package_id   BIGINT UNSIGNED DEFAULT NULL,
			start_time   TIME            NOT NULL,
			end_time    TIME            NOT NULL,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_booking    (booking_id),
			KEY idx_space     (space_id),
			KEY idx_space_slot (space_id, start_time, end_time)
		) $charset;";

		// Booking Packages (new table - packages include spaces)
		$sql_booking_packages = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sb_booking_packages (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id  BIGINT UNSIGNED NOT NULL,
			package_id BIGINT UNSIGNED NOT NULL,
			space_id   BIGINT UNSIGNED NOT NULL,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_booking (booking_id),
			KEY idx_package (package_id),
			KEY idx_space  (space_id)
		) $charset;";

		// Pricing Rules
		$sql_pricing = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sb_pricing_rules (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			space_id     BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = global rule',
			rule_type    ENUM('weekend','weekday','night','day','holiday','date_range','date_specific') NOT NULL,
			modifier     ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
			value        DECIMAL(10,2)   NOT NULL DEFAULT 0.00 COMMENT '+/- amount',
			days_of_week VARCHAR(20)     DEFAULT NULL COMMENT 'CSV e.g. 0,6 for Sun/Sat',
			start_time   TIME            DEFAULT NULL,
			end_time     TIME            DEFAULT NULL,
			start_date   DATE            DEFAULT NULL,
			end_date     DATE            DEFAULT NULL,
			label        VARCHAR(191)    DEFAULT NULL,
			priority     TINYINT UNSIGNED NOT NULL DEFAULT 10,
			is_active    TINYINT(1)      NOT NULL DEFAULT 1,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_space    (space_id),
			KEY idx_type     (rule_type),
			KEY idx_active   (is_active)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql_bookings);
		dbDelta($sql_extras);
		dbDelta($sql_booking_spaces);
		dbDelta($sql_booking_packages);
		dbDelta($sql_pricing);

		// Run migrations
		(new \SpaceBooking\Migrations\AddExpiredAt())->run();
		(new \SpaceBooking\Migrations\AddInReviewStatus())->run();
		(new \SpaceBooking\Migrations\AddParentBookingId())->run();
		(new \SpaceBooking\Migrations\AddBookingMeta())->run();
		(new \SpaceBooking\Migrations\MakeCustomerFieldsOptional())->run();
		(new \SpaceBooking\Migrations\AddOrderId())->run();
		(new \SpaceBooking\Migrations\CreateBookingSpacesTable())->run();
		(new \SpaceBooking\Migrations\AddTrashStatuses())->run();

		update_option('sb_db_version', SB_VERSION);
	}

	// ── Default options ──────────────────────────────────────────────────────
	private static function set_default_options(): void
	{
		add_option('sb_global_open_time', '09:00');
		add_option('sb_global_close_time', '22:00');
		add_option('sb_slot_interval_minutes', '60');
		add_option('sb_currency', 'usd');
		add_option('sb_admin_email', get_option('admin_email'));
		add_option('sb_email_from_name', get_option('blogname'));
		add_option('sb_magic_link_ttl_minutes', '30');
		add_option('sb_buffer_pre_minutes', 15);
		add_option('sb_buffer_post_minutes', 15);
		add_option('sb_checkout_model', 'reusable_product_v1');
		add_option('sb_wc_reusable_product_id', 0);
		add_option('sb_confirmation_email_template', '<p>Dear [customer_name],</p><p>Thank you for your booking #[order_id]. Details:</p><ul><li>Space: [space_name]</li><li>Date/Time: [booking_date]</li><li>Total: [total_price]</li></ul>[package_inclusions][package_question_answers][price_breakdown]<p>Access instructions: [access_instructions]</p>');
	}
}
