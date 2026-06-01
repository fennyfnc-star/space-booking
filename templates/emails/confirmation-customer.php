<?php
/** @var array $booking */
/** @var array $extras */
/** @var array $package_answer_rows */

$primary_color = \SpaceBooking\Services\EmailTemplateHelper::PRIMARY_COLOR;
$space_name = get_the_title((int) ($booking['space_id'] ?? 0)) ?: __('Space', 'space-booking');
$start_time = date('g:i A', strtotime((string) ($booking['start_time'] ?? '')));
$end_time = date('g:i A', strtotime((string) ($booking['end_time'] ?? '')));
$date_display = date_i18n(get_option('date_format'), strtotime((string) ($booking['booking_date'] ?? '')));
$total_display = \SpaceBooking\Services\CurrencyService::format((float) ($booking['total_price'] ?? 0));
$qa_html = \SpaceBooking\Services\EmailTemplateHelper::render_package_qa_html(
	is_array($package_answer_rows ?? null) ? $package_answer_rows : []
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title><?php esc_html_e('Booking Confirmation', 'space-booking'); ?></title>
</head>
<body style="font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:24px;">
	<div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;">
		<div style="background:<?php echo esc_attr($primary_color); ?>;color:#fff;padding:20px 24px;">
			<h2 style="margin:0;"><?php esc_html_e('Booking Confirmed', 'space-booking'); ?></h2>
		</div>
		<div style="padding:24px;color:#222;">
			<p><?php echo esc_html(sprintf(__('Hi %s, your booking is confirmed.', 'space-booking'), (string) ($booking['customer_name'] ?? __('Customer', 'space-booking')))); ?></p>
			<table style="width:100%;border-collapse:collapse;margin:0 0 16px;">
				<tr><td style="padding:8px 0;color:#666;width:38%;"><?php esc_html_e('Booking ID', 'space-booking'); ?></td><td style="padding:8px 0;"><?php echo esc_html((string) ($booking['id'] ?? '')); ?></td></tr>
				<tr><td style="padding:8px 0;color:#666;"><?php esc_html_e('Space', 'space-booking'); ?></td><td style="padding:8px 0;"><?php echo esc_html($space_name); ?></td></tr>
				<tr><td style="padding:8px 0;color:#666;"><?php esc_html_e('Date', 'space-booking'); ?></td><td style="padding:8px 0;"><?php echo esc_html($date_display); ?></td></tr>
				<tr><td style="padding:8px 0;color:#666;"><?php esc_html_e('Time', 'space-booking'); ?></td><td style="padding:8px 0;"><?php echo esc_html($start_time . ' - ' . $end_time); ?></td></tr>
				<tr><td style="padding:8px 0;color:#666;"><?php esc_html_e('Total', 'space-booking'); ?></td><td style="padding:8px 0;"><strong><?php echo wp_kses_post($total_display); ?></strong></td></tr>
			</table>

			<?php if ($qa_html !== ''): ?>
				<?php echo wp_kses_post($qa_html); ?>
			<?php endif; ?>

			<?php if (!empty($extras) && is_array($extras)): ?>
				<h3 style="font-size:15px;margin:16px 0 10px;color:#777;"><?php esc_html_e('Extras', 'space-booking'); ?></h3>
				<ul style="margin:0 0 8px;padding-left:18px;">
					<?php foreach ($extras as $extra): ?>
						<li>
							<?php echo esc_html((string) ($extra['title'] ?? $extra['extra_name'] ?? __('Extra', 'space-booking'))); ?>
							<?php echo esc_html(' x ' . (int) ($extra['quantity'] ?? 1)); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</body>
</html>

