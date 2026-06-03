<?php declare(strict_types=1);

namespace SpaceBooking\Services;

final class RecaptchaService
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function get_config(): array
    {
        $site_key = '';
        $secret_key = '';
        $version = 'v3';
        $source = 'none';

        $direct_candidates = [
            ['site' => 'woocommerce_recaptcha_site_key', 'secret' => 'woocommerce_recaptcha_secret_key', 'version' => 'woocommerce_recaptcha_version'],
            ['site' => 'woocommerce_google_recaptcha_site_key', 'secret' => 'woocommerce_google_recaptcha_secret_key', 'version' => 'woocommerce_google_recaptcha_version'],
            ['site' => 'wc_recaptcha_site_key', 'secret' => 'wc_recaptcha_secret_key', 'version' => 'wc_recaptcha_version'],
            ['site' => 'wc_google_recaptcha_site_key', 'secret' => 'wc_google_recaptcha_secret_key', 'version' => 'wc_google_recaptcha_version'],
            ['site' => 'recaptcha_site_key', 'secret' => 'recaptcha_secret_key', 'version' => 'recaptcha_version'],
            ['site' => 'google_recaptcha_site_key', 'secret' => 'google_recaptcha_secret_key', 'version' => 'google_recaptcha_version'],
        ];

        foreach ($direct_candidates as $candidate) {
            $candidate_site = (string) get_option($candidate['site'], '');
            $candidate_secret = (string) get_option($candidate['secret'], '');
            if ($candidate_site !== '' && $candidate_secret !== '') {
                $site_key = $candidate_site;
                $secret_key = $candidate_secret;
                $version = $this->normalize_version((string) get_option($candidate['version'], 'v3'));
                $source = sprintf('options:%s,%s', $candidate['site'], $candidate['secret']);
                break;
            }
        }

        if ($site_key === '' || $secret_key === '') {
            $array_options = [
                'woocommerce_recaptcha_settings',
                'woocommerce_google_recaptcha_settings',
                'woocommerce_ppcp-recaptcha_settings',
                'wc_recaptcha_settings',
                'wc_google_recaptcha_settings',
                'recaptcha_woocommerce_settings',
                'recaptcha_settings',
                'google_recaptcha_settings',
                'woocommerce_captcha_settings',
                'wc_captcha_settings',
                'captcha_settings',
            ];
            foreach ($array_options as $option_name) {
                $settings = get_option($option_name, []);
                if (!is_array($settings)) {
                    continue;
                }
                $candidate_site = (string) ($settings['site_key']
                    ?? $settings['siteKey']
                    ?? $settings['site_key_v3']
                    ?? $settings['site_key_v2']
                    ?? $settings['recaptcha_site_key']
                    ?? $settings['google_recaptcha_site_key']
                    ?? $settings['captcha_site_key']
                    ?? '');
                $candidate_secret = (string) ($settings['secret_key']
                    ?? $settings['secretKey']
                    ?? $settings['secret_key_v3']
                    ?? $settings['secret_key_v2']
                    ?? $settings['recaptcha_secret_key']
                    ?? $settings['google_recaptcha_secret_key']
                    ?? $settings['captcha_secret_key']
                    ?? '');
                if ($candidate_site !== '' && $candidate_secret !== '') {
                    $site_key = $candidate_site;
                    $secret_key = $candidate_secret;
                    $version = $this->normalize_version((string) ($settings['version']
                        ?? $settings['recaptcha_version']
                        ?? $settings['google_recaptcha_version']
                        ?? (!empty($settings['site_key_v3']) ? 'v3' : (!empty($settings['site_key_v2']) ? 'v2' : 'v3'))
                        ?? $settings['captcha_version']
                        ?? 'v3'));
                    $source = 'option_array:' . $option_name;
                    break;
                }
            }
        }

        $config = [
            'enabled' => true,
            'version' => $version,
            'site_key' => $site_key,
            'secret_key' => $secret_key,
            'source' => $source,
            'has_keys' => ($site_key !== '' && $secret_key !== ''),
            'last_failure' => (string) get_option('sb_recaptcha_last_failure', ''),
        ];

        return apply_filters('sb_recaptcha_wc_config', $config);
    }

    public function verify_token(string $token, string $remote_ip = '', string $expected_action = 'space_booking_submit'): array
    {
        $config = $this->get_config();
        if (empty($config['has_keys'])) {
            $this->remember_failure('Missing WooCommerce reCAPTCHA keys');
            return ['success' => false, 'message' => 'Captcha keys are not configured.'];
        }

        if ($token === '') {
            $this->remember_failure('Missing reCAPTCHA token');
            return ['success' => false, 'message' => 'Missing captcha token.'];
        }

        if ($this->is_token_reused($token)) {
            $this->remember_failure('reCAPTCHA token replay detected');
            return ['success' => false, 'message' => 'Captcha token already used.'];
        }

        $body = [
            'secret' => (string) $config['secret_key'],
            'response' => $token,
        ];
        if ($remote_ip !== '') {
            $body['remoteip'] = $remote_ip;
        }

        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 8,
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->remember_failure('reCAPTCHA verify request failed: ' . $response->get_error_message());
            return ['success' => false, 'message' => 'Captcha verification request failed.'];
        }

        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($payload) || empty($payload['success'])) {
            $codes = is_array($payload['error-codes'] ?? null) ? implode(',', $payload['error-codes']) : 'unknown';
            $this->remember_failure('reCAPTCHA rejected token: ' . $codes);
            return ['success' => false, 'message' => 'Captcha verification failed.'];
        }

        $version = (string) ($config['version'] ?? 'v3');
        if ($version === 'v3') {
            $score = (float) ($payload['score'] ?? 0.0);
            $action = (string) ($payload['action'] ?? '');
            if ($score < 0.5 || ($expected_action !== '' && $action !== '' && $action !== $expected_action)) {
                $this->remember_failure(sprintf('reCAPTCHA v3 rejected by score/action (score=%s, action=%s)', (string) $score, $action));
                return ['success' => false, 'message' => 'Captcha risk score too low.'];
            }
        }

        $this->mark_token_used($token);
        update_option('sb_recaptcha_last_failure', '', false);
        return ['success' => true, 'message' => 'Captcha verified.'];
    }

    public function get_diagnostics(): array
    {
        $config = $this->get_config();
        $endpoint_ok = true;
        $endpoint_reason = '';
        $ping = wp_remote_get('https://www.google.com/recaptcha/api.js', ['timeout' => 5]);
        if (is_wp_error($ping)) {
            $endpoint_ok = false;
            $endpoint_reason = $ping->get_error_message();
        } else {
            $code = (int) wp_remote_retrieve_response_code($ping);
            $endpoint_ok = $code >= 200 && $code < 400;
            if (!$endpoint_ok) {
                $endpoint_reason = 'HTTP ' . $code;
            }
        }

        return [
            'has_keys' => (bool) $config['has_keys'],
            'version' => (string) $config['version'],
            'source' => (string) $config['source'],
            'endpoint_ok' => $endpoint_ok,
            'endpoint_reason' => $endpoint_reason,
            'last_failure' => (string) $config['last_failure'],
        ];
    }

    private function normalize_version(string $version): string
    {
        return strtolower($version) === 'v2' ? 'v2' : 'v3';
    }

    private function token_key(string $token): string
    {
        return 'sb_recaptcha_token_' . md5($token);
    }

    private function is_token_reused(string $token): bool
    {
        return (bool) get_transient($this->token_key($token));
    }

    private function mark_token_used(string $token): void
    {
        set_transient($this->token_key($token), 1, 600);
    }

    private function remember_failure(string $reason): void
    {
        update_option('sb_recaptcha_last_failure', sanitize_text_field($reason), false);
        error_log('SpaceBooking reCAPTCHA: ' . $reason);
    }
}
