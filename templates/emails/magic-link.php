<?php
/** @var string $link  */
/** @var string $email */
/** @var int    $ttl   */
$site_name = get_bloginfo( 'name' );
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Your Booking Overview Link</title>
<style>
  body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}
  .wrap{max-width:600px;margin:auto;background:#fff;border-radius:8px;padding:32px;box-shadow:0 2px 8px rgba(0,0,0,.08)}
  .btn{display:inline-block;background:#2d6a4f;color:#fff;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px;margin:20px 0}
  .note{color:#888;font-size:13px}
</style>
</head>
<body>
<div class="wrap">
  <h2>👋 Your Booking Overview</h2>
  <p>Hi there,</p>
  <p>You requested access to your booking history at <strong><?php echo esc_html( $site_name ); ?></strong>.</p>
  <p>Click the button below to view all your bookings. This link is valid for <strong><?php echo esc_html( $ttl ); ?> minutes</strong> and can only be used once.</p>

  <a class="btn" href="<?php echo esc_url( $link ); ?>">View My Bookings →</a>

  <p class="note">
    If you didn't request this email, you can safely ignore it.<br>
    This link was sent to <?php echo esc_html( $email ); ?>.
  </p>
</div>
</body>
</html>
