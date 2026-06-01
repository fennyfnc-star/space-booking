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

    public function test_booking_controller_allows_submission_when_recaptcha_is_missing(): void
    {
        $controller = (string) file_get_contents($this->pluginRoot . '/includes/Controllers/BookingController.php');
        $plugin = (string) file_get_contents($this->pluginRoot . '/includes/Plugin.php');
        $paymentStep = (string) file_get_contents($this->pluginRoot . '/src/components/steps/Step5Payment.tsx');

        $this->assertStringContainsString("empty(\$recaptcha_config['has_keys'])", $controller);
        $this->assertStringContainsString('Booking protection is not configured. This booking was submitted without captcha verification.', $controller);
        $this->assertStringContainsString('hasKeys', $plugin);
        $this->assertStringContainsString('Bookings will still submit, but they are unprotected until keys are configured.', $plugin);
        $this->assertStringContainsString('Booking is unprotected because WooCommerce reCAPTCHA is not configured.', $paymentStep);
        $this->assertStringContainsString('recaptchaProtectionActive', $paymentStep);
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

    public function test_booking_emails_include_package_answers_and_brand_color(): void
    {
        $helperFile = (string) file_get_contents($this->pluginRoot . '/includes/Services/EmailTemplateHelper.php');
        $this->assertStringContainsString('PRIMARY_COLOR', $helperFile);
        $this->assertStringContainsString('#7A48B0', $helperFile);
        $this->assertStringContainsString('render_package_qa_html', $helperFile);

        $emailService = (string) file_get_contents($this->pluginRoot . '/includes/Services/EmailService.php');
        $this->assertStringContainsString('package_answer_rows', $emailService);
        $this->assertStringContainsString('[package_question_answers]', $emailService);

        $adminEmailTemplate = (string) file_get_contents($this->pluginRoot . '/templates/emails/notification-admin.php');
        $this->assertStringContainsString('#7A48B0', $adminEmailTemplate);
        $this->assertStringContainsString('package_answer_rows', $adminEmailTemplate);

        $ajaxHandlers = (string) file_get_contents($this->pluginRoot . '/includes/Admin/ajax-handlers.php');
        $this->assertStringContainsString('EmailTemplateHelper::PRIMARY_COLOR', $ajaxHandlers);
        $this->assertStringContainsString('package_answers_html', $ajaxHandlers);
    }

    public function test_package_questions_qa_checklist_contracts_across_booking_views(): void
    {
        $step5Payment = (string) file_get_contents($this->pluginRoot . '/src/components/steps/Step5Payment.tsx');
        $step6Confirmation = (string) file_get_contents($this->pluginRoot . '/src/components/steps/Step6Confirmation.tsx');
        $adminEdit = (string) file_get_contents($this->pluginRoot . '/templates/admin/page-booking-edit.php');
        $emailHelper = (string) file_get_contents($this->pluginRoot . '/includes/Services/EmailTemplateHelper.php');
        $adminEmailTemplate = (string) file_get_contents($this->pluginRoot . '/templates/emails/notification-admin.php');
        $customerEmailTemplate = (string) file_get_contents($this->pluginRoot . '/templates/emails/confirmation-customer.php');

        // 1) Booking without package: no package Q&A section should be shown.
        $this->assertStringContainsString('packageQuestionReviewRows.length > 0', $step5Payment);
        $this->assertStringContainsString('packageQuestionRows.length > 0', $step6Confirmation);
        $this->assertStringContainsString('!empty($package_answer_rows)', $adminEdit);
        $this->assertStringContainsString("if (empty(\$rows))", $emailHelper);

        // 2) Package selected but no questions configured: no package Q&A section shown.
        $this->assertStringContainsString('const fields = Array.isArray(pkg.theme_meta_fields) ? pkg.theme_meta_fields : [];', $step5Payment);
        $this->assertStringContainsString('if (!answer) return;', $step5Payment);

        // 3) Package with answers should render in checkout/confirmation/admin/emails.
        $this->assertStringContainsString('Package Answers', $step5Payment);
        $this->assertStringContainsString('Package Answers', $step6Confirmation);
        $this->assertStringContainsString('Package Answers', $adminEdit);
        $this->assertStringContainsString('render_package_qa_html', $emailHelper);
        $this->assertStringContainsString('package_answer_rows', $adminEmailTemplate);
        $this->assertStringContainsString('package_answer_rows', $customerEmailTemplate);

        // 4) Others selected should appear consistently.
        $this->assertStringContainsString('Others:', $step5Payment);
        $this->assertStringContainsString('Others:', $step6Confirmation);
        $this->assertStringContainsString('Others explanation:', $adminEdit);
        $this->assertStringContainsString('Others explanation:', $emailHelper);

        // 5) Multi-package readability: answers are tied to package identity.
        $this->assertStringContainsString('${field.label} (${pkg.title})', $step5Payment);
        $this->assertStringContainsString('`${row.label}-${index}`', $step6Confirmation);
    }
}
