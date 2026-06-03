<?php

declare(strict_types=1);

namespace SpaceBooking\Services {
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        function get_option($option, $default = false) {
            return $GLOBALS['sb_currency_test_options'][$option] ?? $default;
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__, 2) . '/includes/Services/CurrencyService.php';

    final class CurrencyServiceTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['sb_currency_test_options'] = [
                'sb_currency' => 'USD',
            ];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['sb_currency_test_options']);
            parent::tearDown();
        }

        public function test_format_prefixes_the_currency_symbol_before_the_amount(): void
        {
            $this->assertSame('$12.34', \SpaceBooking\Services\CurrencyService::format(12.34));
        }

        public function test_format_places_the_negative_sign_before_the_currency_symbol(): void
        {
            $this->assertSame('-$12.34', \SpaceBooking\Services\CurrencyService::format(-12.34));
        }
    }
}
