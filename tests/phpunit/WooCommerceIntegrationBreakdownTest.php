<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SpaceBooking\Integrations\WooCommerceIntegration;

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value): string
    {
        return trim(strip_tags((string) $value));
    }
}

if (!function_exists('wc_price')) {
    function wc_price($amount): string
    {
        return '<span class="woocommerce-Price-amount">$' . number_format((float) $amount, 2) . '</span>';
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false): string
    {
        $stripped = strip_tags((string) $text);
        return $remove_breaks ? preg_replace('/[\r\n\t ]+/', ' ', $stripped) ?? $stripped : $stripped;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

require_once dirname(__DIR__, 2) . '/includes/Integrations/WooCommerceIntegration.php';

final class WooCommerceIntegrationBreakdownTest extends TestCase
{
    public function test_render_cart_item_name_uses_display_title_when_available(): void
    {
        $result = WooCommerceIntegration::render_cart_item_name(
            'Original product name',
            ['sb_display_title' => 'Booking Suite'],
            'cart-item-key'
        );

        $this->assertSame('Booking Suite', $result);
    }

    public function test_render_cart_item_name_falls_back_to_original_name_without_display_title(): void
    {
        $result = WooCommerceIntegration::render_cart_item_name(
            'Original product name',
            [],
            'cart-item-key'
        );

        $this->assertSame('Original product name', $result);
    }

    public function test_normalize_breakdown_rows_maps_formatted_amounts_and_line_total_fallback(): void
    {
        $rows = $this->normalizeBreakdownRows([
            [
                'label' => '<strong>Base package</strong>',
                'formatted' => '<b>$120.00</b>',
                'amount' => '120',
            ],
            [
                'label' => 'Add-on',
                'line_total' => '45.5',
            ],
            [
                'label' => '',
                'amount' => '10',
            ],
            [
                'label' => 'No amount',
            ],
            'not-a-row',
        ]);

        $this->assertSame([
            [
                'label' => 'Base package',
                'display' => '$120.00',
                'amount' => 120.0,
            ],
            [
                'label' => 'Add-on',
                'display' => '$45.50',
                'amount' => 45.5,
            ],
        ], $rows);
    }

    public function test_normalize_breakdown_rows_keeps_zero_priced_lines(): void
    {
        $rows = $this->normalizeBreakdownRows([
            [
                'label' => 'Included add-on',
                'amount' => '0',
            ],
            [
                'label' => 'Included package',
                'line_total' => 0,
            ],
        ]);

        $this->assertSame([
            [
                'label' => 'Included add-on',
                'display' => '$0.00',
                'amount' => 0.0,
            ],
            [
                'label' => 'Included package',
                'display' => '$0.00',
                'amount' => 0.0,
            ],
        ], $rows);
    }

    public function test_cart_line_item_path_only_excludes_negative_line_totals(): void
    {
        $serviceFile = dirname(__DIR__, 2) . '/includes/Services/WooCommerceService.php';
        $contents = (string) file_get_contents($serviceFile);

        $this->assertStringContainsString('if ($line_total < 0)', $contents);
        $this->assertStringNotContainsString('if ($line_total <= 0)', $contents);
    }

    private function normalizeBreakdownRows(array $breakdown): array
    {
        $method = new ReflectionMethod(WooCommerceIntegration::class, 'normalize_breakdown_rows');
        $method->setAccessible(true);

        return $method->invoke(null, $breakdown);
    }
}
