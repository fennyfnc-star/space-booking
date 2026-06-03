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
	private array $admin_emails;

	public function __construct()
	{
		$this->from_name = (string) get_option('sb_email_from_name', get_option('blogname'));
		$this->admin_emails = $this->resolve_admin_emails();
		$this->from_email = $this->resolve_from_email();
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
		$repo = new BookingRepository();
		$package_answer_rows = EmailTemplateHelper::package_question_rows_from_meta_string(
			(string) $repo->get_meta((int) $booking['id'], '_sb_package_question_answers')
		);
		$date_display = DateDisplayHelper::format_booking_date((string) ($booking['booking_date'] ?? ''));
		$subject = sprintf(
			__('Booking Confirmed – %s on %s', 'space-booking'),
			get_the_title((int) $booking['space_id']),
			$date_display
		);

		$body = $this->render_template('emails/confirmation-customer.php', [
			'booking' => $booking,
			'extras' => $repo->get_extras((int) $booking['id']),
			'package_answer_rows' => $package_answer_rows,
		]);

		$this->send(
			$booking['customer_email'],
			$subject,
			$body
		);
	}

	private function send_admin_notification(array $booking): void
	{
		$repo = new BookingRepository();
		$package_answer_rows = EmailTemplateHelper::package_question_rows_from_meta_string(
			(string) $repo->get_meta((int) $booking['id'], '_sb_package_question_answers')
		);
		$date_display = DateDisplayHelper::format_booking_date((string) ($booking['booking_date'] ?? ''));
		$subject = sprintf(
			__('[New Booking] %s – %s', 'space-booking'),
			get_the_title((int) $booking['space_id']),
			$date_display
		);

		$body = $this->render_template('emails/notification-admin.php', [
			'booking' => $booking,
			'extras' => $repo->get_extras((int) $booking['id']),
			'package_answer_rows' => $package_answer_rows,
		]);

		$this->send($this->admin_emails, $subject, $body);
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

	private function send($to, string $subject, string $body): void
	{
		add_filter('wp_mail_from', fn() => $this->from_email);
		add_filter('wp_mail_from_name', fn() => $this->from_name);
		add_filter('wp_mail_content_type', fn() => 'text/html');

		wp_mail($to, $subject, $body);

		remove_all_filters('wp_mail_from');
		remove_all_filters('wp_mail_from_name');
		remove_all_filters('wp_mail_content_type');
	}

	/**
	 * Resolve admin recipients from the settings option.
	 *
	 * @return array<int, string>
	 */
	private function resolve_admin_emails(): array
	{
		$raw_value = (string) get_option('sb_admin_email', get_option('admin_email'));
		$emails = preg_split('/\s*,\s*/', $raw_value) ?: [];
		$recipients = [];

		foreach ($emails as $email) {
			$email = sanitize_email(trim($email));
			if ($email !== '' && is_email($email) && !in_array($email, $recipients, true)) {
				$recipients[] = $email;
			}
		}

		if (!empty($recipients)) {
			return $recipients;
		}

		$fallback = sanitize_email((string) get_option('admin_email'));
		return is_email($fallback) ? [$fallback] : [];
	}

	private function resolve_from_email(): string
	{
		if (!empty($this->admin_emails)) {
			return $this->admin_emails[0];
		}

		$fallback = sanitize_email((string) get_option('admin_email'));
		return is_email($fallback) ? $fallback : '';
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
		// Get package inclusions from booking meta table.
		$package_inclusions = $repo->get_meta((int) $booking_id, '_sb_package_inclusions');
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
		$package_answer_rows = EmailTemplateHelper::package_question_rows_from_meta_string(
			(string) $repo->get_meta((int) $booking_id, '_sb_package_question_answers')
		);
		$package_answers_html = EmailTemplateHelper::render_package_qa_html($package_answer_rows);
		
		$body = str_replace(
			[
				'[customer_name]',
				'[booking_date]',
				'[space_name]',
				'[access_instructions]',
				'[total_price]',
				'[order_id]',
				'[package_inclusions]',
				'[package_question_answers]'
			],
			[
				esc_html($booking['customer_name']),
				esc_html(DateDisplayHelper::format_booking_date((string) $booking['booking_date'])),
				esc_html(get_the_title((int) $booking['space_id'])),
				esc_html(get_post_meta((int) $booking['space_id'], '_sb_access_instructions', true) ?: 'TBD'),
				\SpaceBooking\Services\CurrencyService::format((float) $booking['total_price']),
				esc_html($order_id),
				$inclusions_html ?: '<em>No inclusions</em>',
				$package_answers_html
			],
			$template
		);

		$subject = sprintf(__('Booking Confirmation #%d', 'space-booking'), $booking_id);

		add_filter('wp_mail_content_type', fn() => 'text/html');
		wp_mail($booking['customer_email'], $subject, $body);
		if (!empty($this->admin_emails)) {
			wp_mail($this->admin_emails, $subject, $body);
		}
		remove_all_filters('wp_mail_content_type');

		/* translators: %s customer email */
		$order->add_order_note(sprintf(__('Booking confirmation email sent to %s.', 'space-booking'), $booking['customer_email']));
	}
}
