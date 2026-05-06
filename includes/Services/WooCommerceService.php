<?php declare(strict_types=1);

namespace SpaceBooking\Services;

use WC_Cart;
use WC_Order;
use WC_Product_Simple;

/**
 * WooCommerce integration service.
 */
final class WooCommerceService
{
    /**
     * Create booking + extras as SEPARATE virtual products in WooCommerce cart.
     *
     * @param array $booking_data  From BookingController (space_id, date, etc.)
     * @param float $total_price   Total (for validation only)
     * @param int   $booking_id    DB booking ID
     * @return string WC checkout URL
     * @throws \RuntimeException if WooCommerce is not available
     */
    public function add_booking_to_cart(array $booking_data, float $total_price, int $booking_id): string
    {
        $items = $booking_data['items'] ?? [];
        $extras_breakdown = $booking_data['extras_breakdown'] ?? [];
        $extras = $booking_data['extras'] ?? [];
        $selected_item_ids = $booking_data['selected_item_ids'] ?? [];

        // Validate WooCommerce is available BEFORE any operations
        if (!function_exists('WC') || !WC()) {
            error_log('SpaceBooking WC: ERROR - WooCommerce not initialized');
            throw new \RuntimeException('WooCommerce not initialized.');
        }

        if (WC()->cart === null) {
            error_log('SpaceBooking WC: ERROR - WooCommerce cart is null');
            throw new \RuntimeException('WooCommerce cart not available.');
        }

        error_log('SpaceBooking WC: Creating ' . count($items) . ' item products + ' . count($extras) . ' extras for booking #' . $booking_id);

        // Helper to create consistent virtual product
        $create_product = function ($name, $price, $item_breakdown = [], $extra_id = 0, $start_time = '', $end_time = '', $duration_hours = 0, $thumbnail_url = '') use ($booking_id, $booking_data) {
            $product = new WC_Product_Simple();
            $product->set_name($name);
            $product->set_regular_price($price);
            $product->set_status('publish');
            $product->set_catalog_visibility('hidden');
            $product->set_virtual(true);
            $product->set_manage_stock(true);
            $product->set_stock_quantity(1);
            $product->set_stock_status('instock');
            $product->set_sold_individually(true);

            // Set product image from space featured image (like Step1 display)
            if (!empty($thumbnail_url)) {
                // Get attachment ID from URL
                $attachment_id = attachment_url_to_postid($thumbnail_url);
                if ($attachment_id) {
                    $product->set_image_id($attachment_id);
                }
            }

            $product->save();

            // Meta for breakdown
            if (!empty($item_breakdown)) {
                $product->add_meta_data('sb_item_breakdown', wp_json_encode($item_breakdown), true);
            }

            // Time slot meta data
            $product->add_meta_data('sb_start_time', $start_time, true);
            $product->add_meta_data('sb_end_time', $end_time, true);
            $product->add_meta_data('sb_duration_hours', $duration_hours, true);
            $product->add_meta_data('sb_date', $booking_data['date'] ?? '', true);

            error_log('SpaceBooking WC: Created product ID ' . $product->get_id() . ' "' . $name . '" $' . $price . ($thumbnail_url ? ' with image' : ''));
            return $product;
        };

        // Clear cart first
        WC()->cart->empty_cart();

        // Common meta for ALL items
        $common_meta = [
            'sb_booking_id' => $booking_id,
            'sb_lead_space_id' => $booking_data['space_id'],
            'sb_selected_item_ids' => wp_json_encode($selected_item_ids),
            'sb_date' => $booking_data['date'],
            'sb_start_time' => $booking_data['start_time'],
            'sb_end_time' => $booking_data['end_time'],
            'sb_customer_name' => $booking_data['customer_name'],
            'sb_customer_email' => $booking_data['customer_email'],
            'sb_extras' => wp_json_encode($extras),
        ];

        $added_count = 0;

        // Time slot info for product naming
        $booking_date = $booking_data['date'] ?? '';
        $start_time = $booking_data['start_time'] ?? '';
        $end_time = $booking_data['end_time'] ?? '';

        // Calculate duration from start/end time if not provided, fallback calculation
        $duration_hours = 0;
        if (isset($booking_data['duration_hours']) && $booking_data['duration_hours'] > 0) {
            $duration_hours = round((float) $booking_data['duration_hours'], 1);
        } elseif (!empty($start_time) && !empty($end_time)) {
            // Calculate duration from time difference
            $start_minutes = strtotime($start_time) - strtotime('00:00:00');
            $end_minutes = strtotime($end_time) - strtotime('00:00:00');
            if ($start_time > $end_time) {
                // Handle overnight (e.g., 22:00 to 02:00)
                $end_minutes += 86400;  // Add 24 hours
            }
            $duration_hours = round(($end_minutes - $start_minutes) / 3600, 1);
        }

        error_log('SpaceBooking WC: duration_hours=' . $duration_hours . ' (start=' . $start_time . ', end=' . $end_time . ')');

        // 1. Add ONE PRODUCT PER ITEM (spaces/packages)
        foreach ($items as $item) {
            $item_name = $item['title'];
            $thumbnail_url = $item['thumbnail'] ?? '';
            $item_product = $create_product($item_name, $item['subtotal'], $item['breakdown'], $item['id'], $start_time, $end_time, $duration_hours, $thumbnail_url);

            // Description w/ time
            $desc = '<strong>' . $item['title'] . '</strong><br>';
            $desc .= $booking_data['date'] . ' ' . $booking_data['start_time'] . '–' . $booking_data['end_time'];
            if ($item['type'] === 'sb_space') {
                $desc .= ' (' . $item['breakdown'][0]['label'] . ')';
            }
            $item_product->set_description($desc);
            $item_product->save();

            wc_clear_notices();
            $cart_key = WC()->cart->add_to_cart($item_product->get_id(), 1, 0, [], array_merge($common_meta, [
                'sb_item_id' => $item['id'],
                'sb_item_type' => $item['type'],
                'sb_item_subtotal' => $item['subtotal']
            ]));

            if ($cart_key) {
                $added_count++;
                error_log('SpaceBooking WC: Item ' . $item['id'] . ' (' . $item['type'] . ') added, key: ' . $cart_key);
            }
        }

        // 2. Add EXTRA products (separate line items, use breakdown if available)
        foreach ($extras_breakdown as $extra_item) {
            // Match to extras data for ID/qty
            $matched_extra = null;
            foreach ($extras as $extra_data) {
                if (strpos($extra_item['label'], get_the_title($extra_data['extra_id'])) !== false) {
                    $matched_extra = $extra_data;
                    break;
                }
            }

            $extra_id = $matched_extra['extra_id'] ?? 0;
            $extra_title = $extra_item['label'];
            $extra_price = $extra_item['amount'];

            // Extras product naming with time slot info
            $extra_product_name = $extra_title;
            $extra_product = $create_product($extra_product_name, $extra_price, [], $extra_id, $start_time, $end_time, $duration_hours);
            $extra_product->set_description('Booking extra #' . $booking_id . ' | Item: ' . $extra_id . ' | ' . $booking_date . ' ' . $start_time . '-' . $end_time);
            $extra_product->save();

            $extra_key = WC()->cart->add_to_cart($extra_product->get_id(), 1, 0, [], array_merge($common_meta, [
                'sb_extra_id' => $extra_id
            ]));

            if ($extra_key) {
                $added_count++;
                error_log('SpaceBooking WC: Extra "' . $extra_title . '" ($' . $extra_price . ') added');
            }
        }

        error_log('SpaceBooking WC: Booking #' . $booking_id . ' fully added (' . $added_count . ' line items) | Cart total: ' . WC()->cart->get_cart_total());

        return wc_get_checkout_url();
    }

    /**
     * Confirm booking from WC order payment complete.
     * Called from WC hook.
     */
    public static function confirm_from_order(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $booking_id = $order->get_meta('_sb_booking_id');
        if (!$booking_id) {
            return;
        }

        $repo = new BookingRepository();
        if ($repo->find($booking_id)) {
            $repo->update_status($booking_id, 'in_review');
            $booking = $repo->find($booking_id);
        }
    }

    /**
     * WC Checkout: Save booking meta to order line items (backup to order meta)
     */
    public static function save_booking_meta_to_order_line_item($item, $cart_item_key, $values, $order)
    {
        if (isset($values['sb_booking_id'])) {
            $item->add_meta_data('sb_booking_id', $values['sb_booking_id']);
            error_log('SpaceBooking WC: Saved sb_booking_id=' . $values['sb_booking_id'] . ' to line item');
        }
        if (isset($values['sb_price_breakdown_enriched'])) {
            $item->add_meta_data('sb_price_breakdown_enriched', $values['sb_price_breakdown_enriched']);
        }
    }

    /**
     * Hook registration helper
     */
    public static function register_hooks()
    {
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'save_booking_meta_to_order_line_item'], 10, 4);
    }
}
