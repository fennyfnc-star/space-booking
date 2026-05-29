<?php declare(strict_types=1);

namespace SpaceBooking\Admin;

/**
 * Registers the top-level "Space Booking" admin menu and submenus.
 */
final class AdminMenu
{
	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menus']);
		add_action('admin_init', [$this, 'register_settings']);
	}

	public function add_menus(): void
	{
		// Top-level menu
		add_menu_page(
			__('Space Booking', 'space-booking'),
			__('Space Booking', 'space-booking'),
			'manage_options',
			'space-booking',
			[$this, 'page_dashboard'],
			'dashicons-calendar-alt',
			30
		);

		// Dashboard
		add_submenu_page(
			'space-booking',
			__('Dashboard', 'space-booking'),
			__('Dashboard', 'space-booking'),
			'manage_options',
			'space-booking',
			[$this, 'page_dashboard']
		);

		// Bookings list
		add_submenu_page(
			'space-booking',
			__('All Bookings', 'space-booking'),
			__('All Bookings', 'space-booking'),
			'manage_options',
			'space-booking-bookings',
			[$this, 'page_bookings']
		);

		// Spaces CPT
		add_submenu_page(
			'space-booking',
			__('Spaces', 'space-booking'),
			__('Spaces', 'space-booking'),
			'manage_options',
			'edit.php?post_type=sb_space'
		);

		// Extras CPT
		add_submenu_page(
			'space-booking',
			__('Extras', 'space-booking'),
			__('Extras', 'space-booking'),
			'manage_options',
			'edit.php?post_type=sb_extra'
		);

		// Packages CPT
		add_submenu_page(
			'space-booking',
			__('Packages', 'space-booking'),
			__('Packages', 'space-booking'),
			'manage_options',
			'edit.php?post_type=sb_package'
		);

		// Pricing Rules
		add_submenu_page(
			'space-booking',
			__('Pricing Rules', 'space-booking'),
			__('Pricing Rules', 'space-booking'),
			'manage_options',
			'space-booking-pricing',
			[$this, 'page_pricing']
		);

		// Tools (Export/Import)
		add_submenu_page(
			'space-booking',
			__('Tools', 'space-booking'),
			__('Tools', 'space-booking'),
			'manage_options',
			'space-booking-tools',
			[$this, 'page_tools']
		);

		// Settings
		add_submenu_page(
			'space-booking',
			__('Settings', 'space-booking'),
			__('Settings', 'space-booking'),
			'manage_options',
			'space-booking-settings',
			[$this, 'page_settings']
		);
	}

	// ── Settings API ─────────────────────────────────────────────────────────

	public function register_settings(): void
	{
		$settings = [
			'sb_global_open_time' => 'sanitize_text_field',
			'sb_global_close_time' => 'sanitize_text_field',
			'sb_slot_interval_minutes' => 'absint',
			'sb_buffer_pre_minutes' => 'absint',
			'sb_buffer_post_minutes' => 'absint',
			'sb_currency' => 'sanitize_text_field',
			'sb_admin_email' => 'sanitize_email',
			'sb_email_from_name' => 'sanitize_text_field',
			'sb_magic_link_ttl_minutes' => 'absint',
			'sb_booking_policy' => 'wp_kses_post',
			'sb_confirmation_email_template' => 'wp_kses_post',
		];

		foreach ($settings as $key => $callback) {
			register_setting('space_booking_settings', $key, ['sanitize_callback' => $callback]);
		}
	}

	// ── Page renderers ────────────────────────────────────────────────────────

	public function page_dashboard(): void
	{
		global $wpdb;

		$total_confirmed = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings WHERE status = 'confirmed'"
		);
		$total_pending = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings WHERE status = 'pending'"
		);
		$total_revenue = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(total_price), 0) FROM {$wpdb->prefix}sb_bookings WHERE status = 'confirmed'"
		);
		$recent = $wpdb->get_results(
			"SELECT b.*, p.post_title AS space_name
		\t FROM {$wpdb->prefix}sb_bookings b
		\t LEFT JOIN {$wpdb->posts} p ON p.ID = b.space_id
		\t ORDER BY b.created_at DESC LIMIT 10",
			ARRAY_A
		);
		?>
<div class="wrap">
    <h1><?php esc_html_e('Space Booking Dashboard', 'space-booking'); ?></h1>

    <div class="sb-admin-stats" style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:20px 0">
        <div class="sb-stat-card"
            style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.1)">
            <h3 style="margin:0 0 8px"><?php echo esc_html(number_format($total_confirmed)); ?></h3>
            <p style="color:#666;margin:0"><?php esc_html_e('Confirmed Bookings', 'space-booking'); ?></p>
        </div>
        <div class="sb-stat-card"
            style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.1)">
            <h3 style="margin:0 0 8px"><?php echo esc_html(number_format($total_pending)); ?></h3>
            <p style="color:#666;margin:0"><?php esc_html_e('Pending Bookings', 'space-booking'); ?></p>
        </div>
        <div class="sb-stat-card"
            style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.1)">
            <h3 style="margin:0 0 8px"><?php echo \SpaceBooking\Services\CurrencyService::format($total_revenue); ?>
            </h3>

            <p style="color:#666;margin:0"><?php esc_html_e('Total Revenue', 'space-booking'); ?></p>
        </div>
    </div>

    <h2><?php esc_html_e('Recent Bookings', 'space-booking'); ?></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('ID', 'space-booking'); ?></th>
                <th><?php esc_html_e('Customer', 'space-booking'); ?></th>
                <th><?php esc_html_e('Space', 'space-booking'); ?></th>
                <th><?php esc_html_e('Date', 'space-booking'); ?></th>
                <th><?php esc_html_e('Time', 'space-booking'); ?></th>
                <th><?php esc_html_e('Total', 'space-booking'); ?></th>
                <th><?php esc_html_e('Status', 'space-booking'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recent)): ?>
            <tr>
                <td colspan="7"><?php esc_html_e('No bookings yet.', 'space-booking'); ?></td>
            </tr>
            <?php else: ?>
            <?php foreach ($recent as $b): ?>
            <tr>
                <td><?php echo esc_html($b['id']); ?></td>
                <td><?php echo esc_html($b['customer_name']); ?><br><small><?php echo esc_html($b['customer_email']); ?></small>
                </td>
                <td><?php echo esc_html($b['space_name'] ?? '—'); ?></td>
                <td><?php echo esc_html($b['booking_date']); ?></td>
                <td><?php echo esc_html(substr($b['start_time'], 0, 5) . ' – ' . substr($b['end_time'], 0, 5)); ?>
                </td>
                <td>$<?php echo esc_html(number_format((float) $b['total_price'], 2)); ?></td>
                <td><span
                        class="sb-status sb-status--<?php echo esc_attr($b['status']); ?>"><?php echo esc_html(ucfirst($b['status'])); ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
	}

	public function page_bookings(): void
	{
		$booking_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
		if ($booking_id) {
			?>
<div class="wrap">
    <?php include __DIR__ . '/../../templates/admin/page-booking-edit.php'; ?>
</div>
<?php
			return;
		}
		?>
<div class="wrap">
    <?php include __DIR__ . '/../../templates/admin/page-bookings.php'; ?>
</div>
<?php
	}

	public function page_pricing(): void
	{
?>
<div class="wrap">
    <h1><?php esc_html_e('Pricing Rules', 'space-booking'); ?></h1>
    <p><?php esc_html_e('Pricing rules are stored in the database. Use the REST API or direct DB access to manage rules.', 'space-booking'); ?>
    </p>
    <a href="<?php echo esc_url(admin_url('admin.php?page=space-booking-settings')); ?>" class="button button-primary">
        <?php esc_html_e('Go to Settings', 'space-booking'); ?>
    </a>
</div>
<?php
	}

	public function page_settings(): void
	{
		$recaptcha_service = new \SpaceBooking\Services\RecaptchaService();
		$recaptcha_diag = $recaptcha_service->get_diagnostics();
?>
<div class="wrap">
    <h1><?php esc_html_e('Space Booking Settings', 'space-booking'); ?></h1>
    <form method="post" action="options.php">
        <?php settings_fields('space_booking_settings'); ?>
        <table class="form-table" role="presentation">
            <?php $this->settings_row('sb_global_open_time', __('Global Opening Time', 'space-booking'), 'time'); ?>
            <?php $this->settings_row('sb_global_close_time', __('Global Closing Time', 'space-booking'), 'time'); ?>
            <?php $this->settings_row('sb_slot_interval_minutes', __('Slot Interval (minutes)', 'space-booking'), 'number'); ?>
            <?php $this->settings_row('sb_buffer_pre_minutes', __('Global Pre-Event Buffer (minutes)', 'space-booking'), 'number'); ?>
            <p class="description">
                <?php esc_html_e('Minutes added before each booking start (cleanup/setup time). Blocks availability.', 'space-booking'); ?>
            </p>
            <?php $this->settings_row('sb_buffer_post_minutes', __('Global Post-Event Buffer (minutes)', 'space-booking'), 'number'); ?>
            <p class="description"><?php esc_html_e('Minutes added after each booking end.', 'space-booking'); ?></p>
            <?php \SpaceBooking\Services\CurrencyService::render_select('sb_currency'); ?><p class="description">
                <?php esc_html_e('Select your currency. Prices will be displayed with the appropriate symbol.', 'space-booking'); ?>
            </p>
            <?php $this->settings_row('sb_admin_email', __('Admin Notification Email', 'space-booking'), 'email'); ?>
            <?php $this->settings_row('sb_email_from_name', __('Email From Name', 'space-booking'), 'text'); ?>
            <?php $this->settings_row('sb_magic_link_ttl_minutes', __('Magic Link TTL (minutes)', 'space-booking'), 'number'); ?>
            <?php $this->policy_editor_row('sb_booking_policy', __('Booking Policy / Terms Agreement', 'space-booking')); ?>
            <tr>
                <th><?php esc_html_e('reCAPTCHA (WooCommerce)', 'space-booking'); ?></th>
                <td>
                    <p>
                        <?php esc_html_e('Booking reCAPTCHA inherits keys from WooCommerce-compatible captcha settings. No separate keys are stored by Space Booking.', 'space-booking'); ?>
                    </p>
                    <ul style="margin:8px 0 0 16px; list-style:disc;">
                        <li><?php echo esc_html(sprintf('Key detected: %s', $recaptcha_diag['has_keys'] ? 'Yes' : 'No')); ?>
                        </li>
                        <li><?php echo esc_html(sprintf('Version: %s', strtoupper((string) $recaptcha_diag['version']))); ?>
                        </li>
                        <li><?php echo esc_html(sprintf('Source: %s', (string) $recaptcha_diag['source'])); ?>
                        </li>
                        <li><?php echo esc_html(sprintf('Verification endpoint reachable: %s', $recaptcha_diag['endpoint_ok'] ? 'Yes' : 'No')); ?>
                            <?php if (!$recaptcha_diag['endpoint_ok'] && !empty($recaptcha_diag['endpoint_reason'])): ?>
                            (<?php echo esc_html((string) $recaptcha_diag['endpoint_reason']); ?>)
                            <?php endif; ?>
                        </li>
                        <li><?php echo esc_html(sprintf('Last failure: %s', $recaptcha_diag['last_failure'] !== '' ? (string) $recaptcha_diag['last_failure'] : 'None')); ?>
                        </li>
                    </ul>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
</div>
<?php
	}

	public function page_tools(): void
	{
?>
<div class="wrap">
    <?php include SB_DIR . 'templates/admin/page-tools.php'; ?>
</div>
<?php
	}

	private function settings_row(string $key, string $label, string $type): void
	{
		$value = esc_attr((string) get_option($key));
		echo "<tr><th><label for=\"{$key}\">{$label}</label></th><td>"
			. "<input id=\"{$key}\" name=\"{$key}\" type=\"{$type}\" value=\"{$value}\" class=\"regular-text\"></td></tr>";
	}

	private function policy_editor_row(string $key, string $label): void
	{
		$content = get_option($key, '');
?>
<tr>
    <th><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
    <td>
        <?php
		wp_editor($content, $key, [
			'textarea_name' => $key,
			'media_buttons' => true,
			'textarea_rows' => 10,
			'teeny' => false,
		]);
		?>
        <p class="description">
            <?php esc_html_e('Booking policy/terms that customers must agree to before payment. Use the WYSIWYG editor above.', 'space-booking'); ?>
        </p>
    </td>
</tr>
<?php
	}
}
