<?php declare(strict_types=1);

namespace SpaceBooking\Services;

/**
 * Handles all transactional email sending:
 *  - Booking confirmation (customer + admin)
 *  - Magic-link lookup email
 */
final class EmailService
{
	private string $from_name;
	private string $from_email;
	private string $admin_email;

	public function __construct()
	{
		$this->from_name = (string) get_option('sb_email_from_name', get_option('blogname'));
		$this->from_email = (string) get_option('sb_admin_email', get_option('admin_email'));
		$this->admin_email = (string) get_option('sb_admin_email', get_option('admin_email'));
	}

	// ── Booking Confirmation ─────────────────────────────────────────────────

	/**
	 * Send confirmation email to customer and notification to admin.
	 */
	public function send_confirmation(array $booking): void
	{
		$this->send_customer_confirmation($booking);
		$this->send_admin_notification($booking);
	}

	private function send_customer_confirmation(array $booking): void
	{
		$subject = sprintf(
			__('Booking Confirmed – %s on %s', 'space-booking'),
			get_the_title((int) $booking['space_id']),
			$booking['booking_date']
		);

		$body = $this->render_template('emails/confirmation-customer.php', [
			'booking' => $booking,
			'extras' => (new BookingRepository())->get_extras((int) $booking['id']),
		]);

		$this->send(
			$booking['customer_email'],
			$subject,
			$body
		);
	}

	private function send_admin_notification(array $booking): void
	{
		$subject = sprintf(
			__('[New Booking] %s – %s', 'space-booking'),
			get_the_title((int) $booking['space_id']),
			$booking['booking_date']
		);

		$body = $this->render_template('emails/notification-admin.php', [
			'booking' => $booking,
			'extras' => (new BookingRepository())->get_extras((int) $booking['id']),
		]);

		$this->send($this->admin_email, $subject, $body);
	}

	// ── Magic Link ───────────────────────────────────────────────────────────

	/**
	 * Send a one-time magic link to the user so they can view their bookings.
	 */
	public function send_magic_link(string $email, string $token): void
	{
		$ttl = (int) get_option('sb_magic_link_ttl_minutes', 30);
		$link = add_query_arg([
			'sb_token' => $token,
		], home_url('/booking-lookup/'));

		$subject = __('Your Booking Overview Link', 'space-booking');

		$body = $this->render_template('emails/magic-link.php', [
			'link' => $link,
			'email' => $email,
			'ttl' => $ttl,
		]);

		$this->send($email, $subject, $body);
	}

	// ── Core Send ────────────────────────────────────────────────────────────

	private function send(string $to, string $subject, string $body): void
	{
		add_filter('wp_mail_from', fn() => $this->from_email);
		add_filter('wp_mail_from_name', fn() => $this->from_name);
		add_filter('wp_mail_content_type', fn() => 'text/html');

		wp_mail($to, $subject, $body);

		remove_all_filters('wp_mail_from');
		remove_all_filters('wp_mail_from_name');
		remove_all_filters('wp_mail_content_type');
	}

	// ── Template Renderer ────────────────────────────────────────────────────

	private function render_template(string $template, array $vars = []): string
	{
		$file = SB_DIR . 'templates/' . $template;

		if (!file_exists($file)) {
			return '';
		}

		extract($vars, EXTR_SKIP);  // phpcs:ignore WordPress.PHP.DontExtract
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}

	/**
	 * Send custom confirmation email from order action
	 */
	public function send_confirmation_email(int $order_id): void
	{
		$order = wc_get_order($order_id);
		if (!$order instanceof \WC_Order) {
			return;
		}

		$booking_id = $order->get_meta('_sb_booking_id');
		if (!$booking_id) {
			$order->add_order_note('ERROR: No booking ID in order meta.');
			return;
		}

		$repo = new BookingRepository();
		$booking = $repo->find((int) $booking_id);
		if (!$booking || empty($booking['customer_email'])) {
			$order->add_order_note('ERROR: Booking not found or no email.');
			return;
		}

		$template = (string) get_option('sb_confirmation_email_template');
		if (empty($template)) {
			$order->add_order_note('ERROR: No email template configured.');
			return;
		}

		// Parse shortcodes
		// Get package inclusions for email
		$package_inclusions = get_post_meta((int) $booking_id, '_sb_package_inclusions', true);
		$inclusions_html = '';
		if ($package_inclusions) {
			$inclusions = is_string($package_inclusions) ? json_decode($package_inclusions, true) : $package_inclusions;
			if (!empty($inclusions)) {
				$inclusions_html = '<ul>';
				foreach ($inclusions as $inc) {
					$inclusions_html .= '<li>✓ ' . esc_html($inc['title'] ?? $inc['label'] ?? 'Included item') . '</li>';
				}
				$inclusions_html .= '</ul>';
			}
		}
		
		$body = str_replace(
			[
				'[customer_name]',
				'[booking_date]',
				'[space_name]',
				'[access_instructions]',
				'[total_price]',
				'[order_id]',
				'[package_inclusions]'
			],
			[
				esc_html($booking['customer_name']),
				esc_html($booking['booking_date']),
				esc_html(get_the_title((int) $booking['space_id'])),
				esc_html(get_post_meta((int) $booking['space_id'], '_sb_access_instructions', true) ?: 'TBD'),
				\SpaceBooking\Services\CurrencyService::format((float) $booking['total_price']),
				esc_html($order_id),
				$inclusions_html ?: '<em>No inclusions</em>'
			],
			$template
		);

		$subject = sprintf(__('Booking Confirmation #%d', 'space-booking'), $booking_id);

		add_filter('wp_mail_content_type', fn() => 'text/html');
		wp_mail($booking['customer_email'], $subject, $body);
		remove_all_filters('wp_mail_content_type');

		/* translators: %s customer email */
		$order->add_order_note(sprintf(__('Booking confirmation email sent to %s.', 'space-booking'), $booking['customer_email']));
	}
}