<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SecurityAndLifecycleRegressionTest extends TestCase
{
    private string $pluginRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginRoot = dirname(__DIR__, 2);
    }

    public function test_booking_controller_retains_spam_protection_guards(): void
    {
        $file = $this->pluginRoot . '/includes/Controllers/BookingController.php';
        $this->assertFileExists($file);
        $contents = (string) file_get_contents($file);

        $this->assertStringContainsString('validate_nonce', $contents, 'Nonce validation guard is missing.');
        $this->assertStringContainsString('is_honeypot_triggered', $contents, 'Honeypot guard is missing.');
        $this->assertStringContainsString('is_submit_too_fast', $contents, 'Min submit-time guard is missing.');
        $this->assertStringContainsString('is_rate_limited', $contents, 'Rate-limit guard is missing.');
        $this->assertStringContainsString('has_recent_duplicate', $contents, 'Duplicate booking guard is missing.');
    }

    public function test_booking_controller_retains_recaptcha_gate_before_checkout_creation(): void
    {
        $file = $this->pluginRoot . '/includes/Controllers/BookingController.php';
        $this->assertFileExists($file);
        $contents = (string) file_get_contents($file);

        $captchaPos = strpos($contents, 'verify_token(');
        $checkoutPos = strpos($contents, 'add_booking_to_cart(');
        $this->assertNotFalse($captchaPos, 'reCAPTCHA verification call not found.');
        $this->assertNotFalse($checkoutPos, 'WooCommerce checkout creation call not found.');
        $this->assertLessThan($checkoutPos, $captchaPos, 'reCAPTCHA must be verified before checkout/cart creation.');
    }

    public function test_booking_repository_retains_trash_restore_and_permanent_delete_paths(): void
    {
        $file = $this->pluginRoot . '/includes/Services/BookingRepository.php';
        $this->assertFileExists($file);
        $contents = (string) file_get_contents($file);

        $this->assertStringContainsString('function move_to_trash', $contents);
        $this->assertStringContainsString('function restore_from_trash', $contents);
        $this->assertStringContainsString('function delete_permanently', $contents);
        $this->assertStringContainsString('append_audit_log', $contents, 'Audit logging for lifecycle actions is missing.');
    }

    public function test_customer_lookup_flow_keeps_one_time_token_and_anti_enumeration_guards(): void
    {
        $file = $this->pluginRoot . '/includes/Controllers/CustomerController.php';
        $this->assertFileExists($file);
        $contents = (string) file_get_contents($file);

        $this->assertStringContainsString('If an account exists for that email', $contents, 'Generic anti-enumeration message is missing.');
        $this->assertStringContainsString('delete_transient($key)', $contents, 'Lookup token should be consumed with one-time invalidation.');
        $this->assertStringContainsString('is_lookup_rate_limited', $contents, 'Lookup rate limiting guard is missing.');
        $this->assertStringContainsString('sb_lookup_token_ttl_minutes', $contents, 'Lookup token TTL setting is missing.');
        $this->assertStringContainsString('lookup_access_granted', $contents, 'Lookup access audit event is missing.');
    }

    public function test_package_meta_builder_supports_repeater_and_others_flag(): void
    {
        $file = $this->pluginRoot . '/includes/Admin/PackageMetaBox.php';
        $this->assertFileExists($file);
        $contents = (string) file_get_contents($file);

        $this->assertStringContainsString('_sb_package_theme_meta_fields', $contents);
        $this->assertStringContainsString('ALLOWED_ANSWER_TYPES', $contents, 'Answer type allow-list should exist.');
        $this->assertStringContainsString('Allow "Others" option', $contents, 'Admin builder should support Others toggle.');
        $this->assertStringContainsString('sanitize_theme_meta_fields', $contents, 'Theme meta fields should be sanitized on save.');
    }

    public function test_package_question_answers_are_persisted_and_visible_in_admin_booking_edit(): void
    {
        $bookingController = (string) file_get_contents($this->pluginRoot . '/includes/Controllers/BookingController.php');
        $this->assertStringContainsString('package_question_answers', $bookingController);
        $this->assertStringContainsString('_sb_package_question_answers', $bookingController);

        $bookingEditTemplate = (string) file_get_contents($this->pluginRoot . '/templates/admin/page-booking-edit.php');
        $this->assertStringContainsString('Package Answers', $bookingEditTemplate);
        $this->assertStringContainsString('_sb_package_question_answers', $bookingEditTemplate);
        $this->assertStringContainsString('Booking Details', $bookingEditTemplate, 'Primary booking details block should exist.');
        $this->assertStringContainsString('$package_answer_rows', $bookingEditTemplate, 'Package answers should be normalized once for primary details rendering.');
    }
}
