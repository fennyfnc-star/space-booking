<?php declare(strict_types=1);

namespace SpaceBooking\Services;

use WC_Product_Simple;

/**
 * WooCommerce integration service.
 */
final class WooCommerceService
{
    private const OPTION_REUSABLE_PRODUCT_ID = 'sb_wc_reusable_product_id';
    private const OPTION_CHECKOUT_MODEL = 'sb_checkout_model';
    private const CHECKOUT_MODEL = 'reusable_product_v1';
    private const SNAPSHOT_KEY = 'sb_price_snapshot_v1';

    /**
     * Add booking to cart using one reusable hidden WooCommerce product.
     *
     * @throws \RuntimeException if WooCommerce is not available
     */
    public function add_booking_to_cart(array $booking_data, float $total_price, int $booking_id): string
    {
        if (!function_exists('WC') || !WC()) {
            throw new \RuntimeException('WooCommerce not initialized.');
        }
        if (WC()->cart === null) {
            throw new \RuntimeException('WooCommerce cart not available.');
        }

        WC()->cart->empty_cart();

        if (WC()->session) {
            WC()->session->set('sb_booking_id', $booking_id);
        }

        $snapshot = $this->build_price_snapshot($booking_data, $total_price, $booking_id);
        $snapshot_json = wp_json_encode($snapshot);
        if (!is_string($snapshot_json)) {
            throw new \RuntimeException('Failed to encode booking snapshot.');
        }

        $product_id = $this->get_or_create_reusable_product_id();
        $line_items = is_array($snapshot['line_items'] ?? null) ? $snapshot['line_items'] : [];
        if (empty($line_items)) {
            $line_items = [[
                'type' => 'booking',
                'reference_id' => 0,
                'label' => $snapshot['title'],
                'quantity' => 1,
                'unit_price' => (float) $snapshot['total'],
                'line_total' => (float) $snapshot['total'],
            ]];
        }

        wc_clear_notices();
        $added_count = 0;
        foreach ($line_items as $index => $line) {
            $line_total = (float) ($line['line_total'] ?? 0);
            if ($line_total <= 0) {
                continue;
            }

            $line_label = sanitize_text_field((string) ($line['label'] ?? 'Booking Item'));
            $line_type = sanitize_text_field((string) ($line['type'] ?? 'item'));

            $cart_item_data = [
                'sb_booking_id' => $booking_id,
                'sb_checkout_model' => self::CHECKOUT_MODEL,
                self::SNAPSHOT_KEY => $snapshot_json,
                'sb_cart_price' => $line_total,
                'sb_display_title' => $line_label,
                'sb_line_type' => $line_type,
                'sb_line_reference_id' => (int) ($line['reference_id'] ?? 0),
                'sb_space_ids' => wp_json_encode($booking_data['space_ids'] ?? []),
                'sb_package_ids' => wp_json_encode($booking_data['package_ids'] ?? []),
                'sb_selected_item_ids' => wp_json_encode($booking_data['selected_item_ids'] ?? []),
                'sb_date' => (string) ($booking_data['date'] ?? ''),
                'sb_start_time' => (string) ($booking_data['start_time'] ?? ''),
                'sb_end_time' => (string) ($booking_data['end_time'] ?? ''),
                'sb_extras' => wp_json_encode($booking_data['extras'] ?? []),
                'sb_breakdown' => wp_json_encode([$line]),
                // Prevent WooCommerce from merging lines with same product ID.
                'sb_line_key' => md5($booking_id . '|' . $index . '|' . $line_label . '|' . $line_total),
            ];

            $cart_key = WC()->cart->add_to_cart($product_id, 1, 0, [], $cart_item_data);
            if ($cart_key) {
                $added_count++;
            }
        }

        if ($added_count === 0) {
            throw new \RuntimeException('Failed to add booking line items to cart.');
        }

        $actual_total = (float) WC()->cart->get_total('edit');
        $expected_total = (float) $snapshot['total'];
        $tolerance = max(0.01, $expected_total * 0.01);
        if (abs($actual_total - $expected_total) > $tolerance) {
            WC()->cart->empty_cart();
            throw new \RuntimeException(sprintf(
                'Price validation failed. Expected %.2f, got %.2f.',
                $expected_total,
                $actual_total
            ));
        }

        return wc_get_checkout_url();
    }

    /**
     * WC Checkout: save booking snapshot and linking metadata on each order line item.
     */
    public static function save_booking_meta_to_order_line_item($item, $cart_item_key, $values, $order): void
    {
        if (isset($values['sb_booking_id'])) {
            $item->add_meta_data('sb_booking_id', (int) $values['sb_booking_id']);
        }
        if (isset($values['sb_checkout_model'])) {
            $item->add_meta_data('sb_checkout_model', sanitize_text_field((string) $values['sb_checkout_model']));
        }
        if (isset($values[self::SNAPSHOT_KEY])) {
            $item->add_meta_data(self::SNAPSHOT_KEY, (string) $values[self::SNAPSHOT_KEY]);
        }
        if (isset($values['sb_display_title'])) {
            $item->add_meta_data('sb_display_title', sanitize_text_field((string) $values['sb_display_title']));
        }
        if (isset($values['sb_breakdown'])) {
            $item->add_meta_data('sb_breakdown', (string) $values['sb_breakdown']);
        }
    }

    /**
     * Hook registration helper.
     */
    public static function register_hooks(): void
    {
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'save_booking_meta_to_order_line_item'], 10, 4);
    }

    /**
     * Get booking ID from WC session (reliable retrieval, race condition fix).
     */
    public function get_booking_id_from_session(): ?int
    {
        if (function_exists('WC') && WC() && WC()->session) {
            $session_booking_id = WC()->session->get('sb_booking_id');
            if ($session_booking_id) {
                return (int) $session_booking_id;
            }
        }

        if (function_exists('WC') && WC() && WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (isset($cart_item['sb_booking_id'])) {
                    return (int) $cart_item['sb_booking_id'];
                }
            }
        }

        return null;
    }

    /**
     * Build immutable snapshot payload for booking pricing.
     */
    private function build_price_snapshot(array $booking_data, float $total_price, int $booking_id): array
    {
        $items = is_array($booking_data['items'] ?? null) ? $booking_data['items'] : [];
        $extras_breakdown = is_array($booking_data['extras_breakdown'] ?? null) ? $booking_data['extras_breakdown'] : [];
        $line_items = [];

        foreach ($items as $item) {
            $amount = (float) ($item['subtotal'] ?? 0.0);
            if ($amount <= 0) {
                continue;
            }
            $line_items[] = [
                'type' => sanitize_text_field((string) ($item['type'] ?? 'item')),
                'reference_id' => (int) ($item['id'] ?? 0),
                'label' => sanitize_text_field((string) ($item['title'] ?? 'Item')),
                'quantity' => 1,
                'unit_price' => $amount,
                'line_total' => $amount,
            ];
        }

        foreach ($extras_breakdown as $extra) {
            $amount = (float) ($extra['amount'] ?? 0.0);
            if ($amount <= 0) {
                continue;
            }
            $line_items[] = [
                'type' => 'extra',
                'reference_id' => 0,
                'label' => sanitize_text_field((string) ($extra['label'] ?? 'Extra')),
                'quantity' => 1,
                'unit_price' => $amount,
                'line_total' => $amount,
            ];
        }

        return [
            'version' => 1,
            'booking_id' => $booking_id,
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : get_option('woocommerce_currency', 'USD'),
            'date' => (string) ($booking_data['date'] ?? ''),
            'start_time' => (string) ($booking_data['start_time'] ?? ''),
            'end_time' => (string) ($booking_data['end_time'] ?? ''),
            'title' => sprintf('Space Booking #%d', $booking_id),
            'base_price' => (float) ($booking_data['base_price'] ?? 0.0),
            'extras_price' => (float) ($booking_data['extras_price'] ?? 0.0),
            'modifier_price' => (float) ($booking_data['modifier_price'] ?? 0.0),
            'total' => (float) $total_price,
            'line_items' => $line_items,
            'captured_at_gmt' => gmdate('c'),
        ];
    }

    /**
     * Cleanup helper for legacy per-booking generated products.
     *
     * @return array{scanned:int,deleted:int,ids:array<int>}
     */
    public function cleanup_legacy_generated_products(int $limit = 100, bool $dry_run = true): array
    {
        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'private', 'trash'],
            'posts_per_page' => max(1, $limit),
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_sb_created',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $ids = array_map('intval', $query->posts ?? []);
        if ($dry_run || empty($ids)) {
            return [
                'scanned' => count($ids),
                'deleted' => 0,
                'ids' => $ids,
            ];
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if ((bool) wp_delete_post($id, true)) {
                $deleted++;
            }
        }

        return [
            'scanned' => count($ids),
            'deleted' => $deleted,
            'ids' => $ids,
        ];
    }

    /**
     * Resolve reusable product ID, creating the hidden virtual product if missing.
     */
    private function get_or_create_reusable_product_id(): int
    {
        $existing_id = (int) get_option(self::OPTION_REUSABLE_PRODUCT_ID, 0);
        if ($existing_id > 0) {
            $product = wc_get_product($existing_id);
            if ($product && get_post_type($existing_id) === 'product') {
                return $existing_id;
            }
        }

        $product = new WC_Product_Simple();
        $product->set_name('Space Booking');
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_virtual(true);
        $product->set_sold_individually(true);
        $product->set_manage_stock(false);
        $product->set_regular_price('0');
        $product->set_description('Reusable hidden product used as checkout carrier for Space Booking orders.');
        $product->save();

        $product_id = (int) $product->get_id();
        if ($product_id <= 0) {
            throw new \RuntimeException('Failed to create reusable WooCommerce booking product.');
        }

        update_post_meta($product_id, '_sb_reusable_product', '1');
        update_option(self::OPTION_REUSABLE_PRODUCT_ID, $product_id);
        update_option(self::OPTION_CHECKOUT_MODEL, self::CHECKOUT_MODEL);

        return $product_id;
    }
}
