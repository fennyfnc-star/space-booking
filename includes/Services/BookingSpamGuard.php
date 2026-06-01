<?php declare(strict_types=1);

namespace SpaceBooking\Services;

/**
 * Centralized anti-spam and abuse checks for booking submissions.
 */
final class BookingSpamGuard
{
    private const RATE_LIMIT_WINDOW = 600; // 10 minutes.
    private const RATE_LIMIT_MAX_PER_IP = 8;
    private const RATE_LIMIT_MAX_PER_EMAIL = 5;
    private const MIN_SUBMIT_SECONDS = 4;
    private const DUPLICATE_WINDOW_SECONDS = 300; // 5 minutes.

    public function validate_nonce(?string $nonce): bool
    {
        if (!is_string($nonce) || $nonce === '') {
            return false;
        }
        return wp_verify_nonce($nonce, 'wp_rest') !== false;
    }

    public function get_request_ip(): string
    {
        $raw = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return sanitize_text_field($raw) ?: 'unknown';
    }

    public function is_honeypot_triggered(string $honeypot): bool
    {
        return trim($honeypot) !== '';
    }

    public function is_submit_too_fast(int $started_at_unix): bool
    {
        if ($started_at_unix <= 0) {
            return true;
        }

        $delta = time() - $started_at_unix;
        return $delta < self::MIN_SUBMIT_SECONDS;
    }

    public function is_rate_limited(string $ip, string $email): bool
    {
        $ip_count = (int) get_transient($this->ip_key($ip));
        $email_count = (int) get_transient($this->email_key($email));

        return $ip_count >= self::RATE_LIMIT_MAX_PER_IP || $email_count >= self::RATE_LIMIT_MAX_PER_EMAIL;
    }

    public function increment_rate_counters(string $ip, string $email): void
    {
        $this->bump_counter($this->ip_key($ip));
        $this->bump_counter($this->email_key($email));
    }

    public function has_recent_duplicate(string $email, string $date, string $start_time, string $end_time): bool
    {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}sb_bookings
             WHERE customer_email = %s
               AND booking_date = %s
               AND start_time = %s
               AND end_time = %s
               AND created_at >= (NOW() - INTERVAL %d SECOND)
               AND status IN ('pending','in_review','confirmed')",
            $email,
            $date,
            $start_time,
            $end_time,
            self::DUPLICATE_WINDOW_SECONDS
        );

        return (int) $wpdb->get_var($query) > 0;
    }

    public function log_suspicious_attempt(string $reason, array $context = []): void
    {
        $payload = [
            'timestamp_gmt' => gmdate('Y-m-d H:i:s'),
            'reason' => sanitize_text_field($reason),
            'ip' => $this->get_request_ip(),
            'context' => $context,
        ];

        error_log('SpaceBooking suspicious attempt: ' . wp_json_encode($payload));
    }

    private function ip_key(string $ip): string
    {
        return 'sb_rate_ip_' . md5($ip);
    }

    private function email_key(string $email): string
    {
        return 'sb_rate_email_' . md5(strtolower($email));
    }

    private function bump_counter(string $key): void
    {
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, self::RATE_LIMIT_WINDOW);
    }
}
