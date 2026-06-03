<?php
/** @var array $booking */
/** @var array $extras */
/** @var array $package_answer_rows */

/**
 * Convert 24-hour time to 12-hour format with AM/PM
 */
function sb_format_time_12hour(string $time): string
{
    if (empty($time) || strlen($time) < 5)
        return $time;
    [$hourStr, $minuteStr] = explode(':', substr($time, 0, 5));
    $hour = (int) $hourStr;
    $minutes = $minuteStr;
    $period = $hour >= 12 ? 'PM' : 'AM';
    $hour = $hour % 12;
    if ($hour === 0)
        $hour = 12;
    return sprintf('%d:%s %s', $hour, $minutes, $period);
}

$site_name = get_bloginfo('name');
$space_name = get_the_title((int) $booking['space_id']);
$admin_url = admin_url('admin.php?page=space-booking');
$date_display = \SpaceBooking\Services\DateDisplayHelper::format_booking_date((string) ($booking['booking_date'] ?? ''));
$qa_html = \SpaceBooking\Services\EmailTemplateHelper::render_package_qa_html(
    is_array($package_answer_rows ?? null) ? $package_answer_rows : []
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>New Booking Notification</title>
    <style>
    body {
        font-family: Arial, sans-serif;
        background: #f4f4f4;
        margin: 0;
        padding: 20px
    }

    .wrap {
        max-width: 600px;
        margin: auto;
        background: #fff;
        border-radius: 8px;
        padding: 32px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .08)
    }

    table {
        width: 100%;
        border-collapse: collapse
    }

    th {
        text-align: left;
        color: #555;
        padding: 8px 0;
        width: 40%;
        font-weight: 600
    }

    td {
        padding: 8px 0;
        color: #222;
        border-bottom: 1px solid #eee
    }

    .btn {
        display: inline-block;
        background: #7A48B0;
        color: #fff;
        padding: 10px 20px;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 700;
        margin-top: 16px
    }
    </style>
</head>

<body>
    <div class="wrap">
        <h2>🔔 New Booking – <?php echo esc_html($space_name); ?></h2>
        <table>
            <tr>
                <th>Customer</th>
                <td><?php echo esc_html($booking['customer_name']); ?></td>
            </tr>
            <tr>
                <th>Email</th>
                <td><?php echo esc_html($booking['customer_email']); ?></td>
            </tr>
            <tr>
                <th>Phone</th>
                <td><?php echo esc_html($booking['customer_phone'] ?: '—'); ?></td>
            </tr>
            <tr>
                <th>Space</th>
                <td><?php echo esc_html($space_name); ?></td>
            </tr>
            <tr>
                <th>Date</th>
                <td><?php echo esc_html($date_display ?: (string) $booking['booking_date']); ?></td>
            </tr>
            <tr>
                <th>Time</th>
                <td><?php echo esc_html(sb_format_time_12hour($booking['start_time']) . ' – ' . sb_format_time_12hour($booking['end_time'])); ?>
                </td>
            </tr>
            <?php 
            // Get package inclusions from booking meta
            $package_inclusions = get_post_meta($booking['id'], '_sb_package_inclusions', true);
            $inclusions_list = [];
            if ($package_inclusions) {
                $inclusions_list = is_string($package_inclusions) ? json_decode($package_inclusions, true) : $package_inclusions;
            }
            ?>
            <?php if (!empty($inclusions_list)): ?>
            <tr>
                <th>Package Inclusions</th>
                <td>
                    <?php foreach ($inclusions_list as $inc): ?>
                        <span style="display: inline-block; margin-right: 8px; background: #e7f3ff; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                            ✓ <?php echo esc_html($inc['title']); ?>
                        </span>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php if (!empty($extras)): ?>
            <tr>
                <th>Extras</th>
                <td><?php echo esc_html(implode(', ', array_column($extras, 'extra_name'))); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Total</th>
                <td><strong><?php echo \SpaceBooking\Services\CurrencyService::format((float) $booking['total_price']); ?></strong>
                </td>
            </tr>

            <tr>
                <th>Status</th>
                <td><?php echo esc_html(ucfirst($booking['status'])); ?></td>
            </tr>
        </table>
        <?php if ($qa_html !== ''): ?>
            <?php echo wp_kses_post($qa_html); ?>
        <?php endif; ?>
        <a class="btn" href="<?php echo esc_url($admin_url); ?>">View in Admin →</a>
    </div>
</body>

</html>
