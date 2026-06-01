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
        $line_item_meta = $this->build_line_item_meta($booking_data, $booking_id, $line_items);
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
            if ($line_total < 0) {
                continue;
            }

            $line_label = sanitize_text_field((string) ($line['label'] ?? 'Booking Item'));
            $line_type = sanitize_text_field((string) ($line['type'] ?? 'item'));
            $reference_id = (int) ($line['reference_id'] ?? 0);
            $meta = $line_item_meta[$index] ?? [];

            $cart_item_data = [
                'sb_booking_id' => $booking_id,
                'sb_checkout_model' => self::CHECKOUT_MODEL,
                self::SNAPSHOT_KEY => $snapshot_json,
                'sb_cart_price' => $line_total,
                'sb_display_title' => $line_label,
                'sb_line_type' => $line_type,
                'sb_line_reference_id' => $reference_id,
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
            if (!empty($meta)) {
                $cart_item_data['sb_component_meta'] = wp_json_encode($meta);
            }

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
        if (!empty($values['sb_display_title'])) {
            $item->set_name(sanitize_text_field((string) $values['sb_display_title']));
        }
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
        if (!empty($values['sb_component_meta']) && is_string($values['sb_component_meta'])) {
            $component_meta = json_decode((string) $values['sb_component_meta'], true);
            if (is_array($component_meta)) {
                self::persist_component_meta_to_order_item($item, $component_meta);
            }
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
        $extras_details = is_array($booking_data['extras_details'] ?? null) ? $booking_data['extras_details'] : [];
        $breakdown = is_array($booking_data['breakdown'] ?? null) ? $booking_data['breakdown'] : [];
        $line_items = $this->build_canonical_line_items_from_breakdown($breakdown, $items, $extras_details);
        if (empty($line_items)) {
            $line_items = $this->build_legacy_line_items_fallback($items, $extras_breakdown, $extras_details, $breakdown);
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
     * Canonical path: one WooCommerce line item per breakdown row, including 0.00 rows.
     */
    private function build_canonical_line_items_from_breakdown(array $breakdown, array $items, array $extras_details): array
    {
        $lines = [];
        $extras_map = $this->build_extras_label_map($extras_details);

        foreach ($breakdown as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            $amount = (float) ($row['amount'] ?? 0.0);
            if ($label === '' || $amount < 0) {
                continue;
            }

            $identity = $this->resolve_line_identity($label, $items, $extras_map);
            $quantity = max(1, (int) ($identity['quantity'] ?? 1));
            $line_total = (float) $amount;
            $unit_price = $quantity > 0 ? ($line_total / $quantity) : $line_total;

            $lines[] = [
                'type' => sanitize_text_field((string) ($identity['type'] ?? 'item')),
                'reference_id' => (int) ($identity['reference_id'] ?? 0),
                'label' => $label,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'line_total' => $line_total,
                'breakdown_index' => (int) $index,
            ];
        }

        return $lines;
    }

    /**
     * Compatibility fallback if canonical breakdown is unavailable.
     */
    private function build_legacy_line_items_fallback(array $items, array $extras_breakdown, array $extras_details, array $breakdown): array
    {
        $line_items = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $amount = (float) ($item['subtotal'] ?? 0.0);
            if ($amount < 0) {
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

        $extras_by_label = $this->build_extras_label_map($extras_details);
        foreach ($extras_breakdown as $extra) {
            if (!is_array($extra)) {
                continue;
            }
            $amount = (float) ($extra['amount'] ?? 0.0);
            if ($amount < 0) {
                continue;
            }
            $label = sanitize_text_field((string) ($extra['label'] ?? 'Extra'));
            if ($label === '') {
                continue;
            }
            $extra_ref = $extras_by_label[$label] ?? ['extra_id' => 0, 'paid_qty' => 1];
            $line_items[] = [
                'type' => 'extra',
                'reference_id' => (int) ($extra_ref['extra_id'] ?? 0),
                'label' => $label,
                'quantity' => max(1, (int) ($extra_ref['paid_qty'] ?? 1)),
                'unit_price' => $amount,
                'line_total' => $amount,
            ];
        }

        $existing_labels = array_map(static function (array $line): string {
            return sanitize_text_field((string) ($line['label'] ?? ''));
        }, $line_items);
        $existing_labels = array_values(array_filter(array_unique($existing_labels)));

        foreach ($breakdown as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            $amount = (float) ($row['amount'] ?? 0.0);
            if ($label === '' || $amount < 0) {
                continue;
            }
            if (stripos($label, 'package inclusion') === false) {
                continue;
            }
            if (in_array($label, $existing_labels, true)) {
                continue;
            }
            $line_items[] = [
                'type' => 'inclusion',
                'reference_id' => 0,
                'label' => $label,
                'quantity' => 1,
                'unit_price' => 0.0,
                'line_total' => 0.0,
            ];
            $existing_labels[] = $label;
        }

        return $line_items;
    }

    private function build_extras_label_map(array $extras_details): array
    {
        $extras_by_label = [];
        foreach ($extras_details as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $label = sanitize_text_field((string) ($detail['title'] ?? ''));
            if ($label === '') {
                continue;
            }
            $extras_by_label[$label] = [
                'extra_id' => (int) ($detail['extra_id'] ?? 0),
                'paid_qty' => max(0, (int) ($detail['paid_qty'] ?? 1)),
            ];
        }
        return $extras_by_label;
    }

    private function resolve_line_identity(string $label, array $items, array $extras_by_label): array
    {
        $normalized_label = sanitize_text_field(trim(preg_replace('/\s+/', ' ', $label) ?? ''));
        $base_label = preg_replace('/\s*\(.+?\)\s*$/', '', $normalized_label) ?: $normalized_label;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item_title = sanitize_text_field((string) ($item['title'] ?? ''));
            if ($item_title === '') {
                continue;
            }
            if ($normalized_label === $item_title || $base_label === $item_title || str_starts_with($normalized_label, $item_title . ' ')) {
                return [
                    'type' => sanitize_text_field((string) ($item['type'] ?? 'item')),
                    'reference_id' => (int) ($item['id'] ?? 0),
                    'quantity' => 1,
                ];
            }
        }

        if (isset($extras_by_label[$base_label])) {
            $extra_ref = $extras_by_label[$base_label];
            return [
                'type' => 'extra',
                'reference_id' => (int) ($extra_ref['extra_id'] ?? 0),
                'quantity' => max(1, (int) ($extra_ref['paid_qty'] ?? 1)),
            ];
        }
        if (isset($extras_by_label[$normalized_label])) {
            $extra_ref = $extras_by_label[$normalized_label];
            return [
                'type' => 'extra',
                'reference_id' => (int) ($extra_ref['extra_id'] ?? 0),
                'quantity' => max(1, (int) ($extra_ref['paid_qty'] ?? 1)),
            ];
        }

        if (stripos($normalized_label, 'package inclusion') !== false) {
            return [
                'type' => 'inclusion',
                'reference_id' => 0,
                'quantity' => 1,
            ];
        }

        return [
            'type' => 'item',
            'reference_id' => 0,
            'quantity' => 1,
        ];
    }

    /**
     * Build per-line component metadata for downstream WooCommerce order item meta.
     */
    private function build_line_item_meta(array $booking_data, int $booking_id, array $line_items): array
    {
        $duration_hours = (float) ($booking_data['duration_hours'] ?? 0.0);
        $extras_input = is_array($booking_data['extras'] ?? null) ? $booking_data['extras'] : [];
        $extras_by_id = [];
        foreach ($extras_input as $extra) {
            if (!is_array($extra)) {
                continue;
            }
            $extra_id = (int) ($extra['extra_id'] ?? 0);
            if ($extra_id <= 0) {
                continue;
            }
            $extras_by_id[$extra_id] = max(1, (int) ($extra['quantity'] ?? 1));
        }

        $package_answers = $this->get_package_answers_for_booking($booking_id);
        $meta_rows = [];

        foreach ($line_items as $index => $line) {
            if (!is_array($line)) {
                continue;
            }

            $type = sanitize_text_field((string) ($line['type'] ?? 'item'));
            $reference_id = (int) ($line['reference_id'] ?? 0);
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $label = sanitize_text_field((string) ($line['label'] ?? 'Booking Item'));
            $meta = [
                'component_label' => $label,
                'component_type' => $type,
                'reference_id' => $reference_id,
                'quantity' => $quantity,
            ];

            if ($type === 'sb_space') {
                $meta['hours'] = $duration_hours > 0 ? round($duration_hours, 2) : 0.0;
            } elseif ($type === 'sb_package') {
                $meta['package_questions'] = $package_answers[$reference_id] ?? [];
            } elseif ($type === 'extra') {
                $extra_qty = $reference_id > 0 && isset($extras_by_id[$reference_id]) ? (int) $extras_by_id[$reference_id] : $quantity;
                $meta['quantity'] = max(1, $extra_qty);
                $meta['details'] = $label;
            }

            $meta_rows[$index] = $meta;
        }

        return $meta_rows;
    }

    /**
     * Read and sanitize package question answers stored on booking meta.
     */
    private function get_package_answers_for_booking(int $booking_id): array
    {
        if ($booking_id <= 0) {
            return [];
        }

        $repo = new BookingRepository();
        $raw = $repo->get_meta($booking_id, '_sb_package_question_answers');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $answers = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $package_id = (int) ($row['package_id'] ?? 0);
            if ($package_id <= 0) {
                continue;
            }
            $question = sanitize_text_field((string) ($row['field_label'] ?? ''));
            $raw_answer = $row['value'] ?? '';
            if (is_array($raw_answer)) {
                $raw_answer = implode(', ', array_map(static fn($v): string => sanitize_text_field((string) $v), $raw_answer));
            }
            $answer = sanitize_text_field((string) $raw_answer);
            if ($question === '' || $answer === '') {
                continue;
            }
            if (!isset($answers[$package_id]) || !is_array($answers[$package_id])) {
                $answers[$package_id] = [];
            }
            $answers[$package_id][] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $answers;
    }

    /**
     * Persist human-readable component metadata on WC order line item.
     */
    private static function persist_component_meta_to_order_item($item, array $component_meta): void
    {
        $type = sanitize_text_field((string) ($component_meta['component_type'] ?? 'item'));
        $qty = max(1, (int) ($component_meta['quantity'] ?? 1));

        $item->add_meta_data('sb_component_type', $type);
        $item->add_meta_data('sb_component_qty', $qty);

        if ($type === 'sb_space') {
            $hours = isset($component_meta['hours']) && is_numeric($component_meta['hours']) ? (float) $component_meta['hours'] : 0.0;
            if ($hours > 0) {
                $item->add_meta_data('Hours', (string) round($hours, 2));
            }
        }

        if ($type === 'sb_package') {
            $questions = is_array($component_meta['package_questions'] ?? null) ? $component_meta['package_questions'] : [];
            foreach ($questions as $qa) {
                if (!is_array($qa)) {
                    continue;
                }
                $question = sanitize_text_field((string) ($qa['question'] ?? ''));
                $answer = sanitize_text_field((string) ($qa['answer'] ?? ''));
                if ($question === '' || $answer === '') {
                    continue;
                }
                $item->add_meta_data('Package: ' . $question, $answer);
            }
        }

        if ($type === 'extra') {
            $details = sanitize_text_field((string) ($component_meta['details'] ?? ''));
            if ($details !== '') {
                $item->add_meta_data('Extra Details', $details);
            }
        }
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
