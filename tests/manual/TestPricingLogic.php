<?php

/**
 * Module 1: Atomic Pricing & Temporal Stacking Tests
 *
 * Tests:
 * 1. Base Rate: 2-hour booking for Space A ($50/hr) = $100
 * 2. Weekend Modifier: 10% surcharge → $110
 * 3. Multi-Space Totals: Space A ($50/hr) + Space B ($30/hr) for 2hr = $160 + modifiers
 *
 * Run: http://your-site/wp-content/plugins/space-booking/tests/manual/TestPricingLogic.php
 */

// Bootstrap WordPress
define('ABSPATH', 'C:/xampp/htdocs/kukoolala/');
define('WP_DEBUG', true);
require_once ABSPATH . 'wp-load.php';

require_once dirname(__FILE__) . '/../../space-booking.php';
require_once dirname(__FILE__) . '/../../includes/Plugin.php';

\spaceBooking\Plugin::instance()->boot();

global $wpdb;
$repo = new \SpaceBooking\Services\BookingRepository();
$pricing = new \SpaceBooking\Services\PricingService();

echo "=== Module 1: Atomic Pricing & Temporal Stacking ===\n\n";

// Use tomorrow's date
$test_date = date('Y-m-d', strtotime('+1 day'));

/**
 * Helper: Create test space with hourly rate
 */
function create_test_space(string $title, float $hourly_rate): int
{
    $post_id = wp_insert_post([
        'post_title' => $title,
        'post_type' => 'sb_space',
        'post_status' => 'publish',
    ]);
    update_post_meta($post_id, '_sb_hourly_rate', $hourly_rate);
    return $post_id;
}

/**
 * Helper: Create weekend pricing rule (10% surcharge)
 */
function create_weekend_modifier(float $percentage = 10): int
{
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'sb_pricing_rules', [
        'space_id' => null,  // Global
        'rule_type' => 'weekend',
        'modifier' => 'percent',
        'value' => $percentage,
        'is_active' => 1,
        'priority' => 20,
    ]);
    return $wpdb->insert_id;
}

/**
 * Cleanup helper
 */
function cleanup_test_space(int $space_id): void
{
    wp_delete_post($space_id, true);
}

function cleanup_pricing_rules(): void
{
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->prefix}sb_pricing_rules WHERE rule_type = 'weekend'");
}

// ============================================================
// TEST 1: Base Rate - 2hr × $50/hr = $100
// ============================================================
echo "=== TEST 1: Base Rate (2hr × \$50/hr = \$100) ===\n";

$space_a = create_test_space('Test Space A', 50.0);
echo "Created Space A (ID: $space_a) with rate: \$50/hr\n";

$result = $pricing->calculate(
    $space_a,
    $test_date,
    '10:00',
    '12:00',
    [],
    null,
    null
);

$base_price = $result['base_price'];
$total_price = $result['total_price'];

echo "Result: base_price=$base_price, total_price=$total_price\n";

$test_1_pass = abs($base_price - 100) < 0.01 && abs($total_price - 100) < 0.01;
echo 'TEST 1 RESULT: ' . ($test_1_pass ? 'PASS' : 'FAIL') . "\n\n";

cleanup_test_space($space_a);

// ============================================================
// TEST 2: Weekend Modifier - 10% surcharge
// ============================================================
echo "=== TEST 2: Weekend Modifier (10% surcharge) ===\n";

// Create test space again
$space_a = create_test_space('Test Space A', 50.0);

// Create weekend modifier (10%)
$modifier_id = create_weekend_modifier(10);
echo "Created weekend modifier: 10%\n";

// Determine if test date is weekend
$day_of_week = (int) date('w', strtotime($test_date));
$is_weekend = in_array($day_of_week, [0, 6], true);  // Sunday=0, Saturday=6

if ($is_weekend) {
    echo "Test date $test_date is a weekend day (will apply modifier)\n";
} else {
    echo "Test date $test_date is NOT a weekend day - skipping modifier test\n";
    // Use a known weekend date
    // Find next Saturday
    $saturday = strtotime('next Saturday');
    $test_date = date('Y-m-d', $saturday);
    echo "Using Saturday: $test_date\n";
}

$result = $pricing->calculate(
    $space_a,
    $test_date,
    '10:00',
    '12:00',
    [],
    null,
    null
);

$base_price = $result['base_price'];
$modifier_price = $result['total_price'] - $base_price;
$total_price = $result['total_price'];

echo "Result: base_price=$base_price, modifier_price=$modifier_price, total_price=$total_price\n";

// If weekend, expect 10% of 100 = $10, so total should be $110
$expected_total = $base_price * 1.1;
$test_2_pass = $is_weekend ? abs($total_price - $expected_total) < 0.01 : ($modifier_price < 0.01);

echo 'TEST 2 RESULT: ' . ($test_2_pass ? 'PASS' : 'FAIL') . "\n\n";

cleanup_test_space($space_a);
cleanup_pricing_rules();

// ============================================================
// TEST 3: Multi-Space Totals
// ============================================================
echo "=== TEST 3: Multi-Space Totals (\$50/hr + \$30/hr for 2hr = \$160) ===\n";

$space_a = create_test_space('Test Space A', 50.0);
$space_b = create_test_space('Test Space B', 30.0);

echo "Created Space A (ID: $space_a) with rate: \$50/hr\n";
echo "Created Space B (ID: $space_b) with rate: \$30/hr\n";

// Test multi-space pricing via item_ids
$result = $pricing->calculate(
    $space_a,  // primary space
    $test_date,
    '10:00',
    '12:00',
    [],
    [$space_a, $space_b],  // item_ids for multi-space
    null
);

$base_price = $result['base_price'];
$total_price = $result['total_price'];

echo "Result: base_price=$base_price, total_price=$total_price\n";
echo "Breakdown:\n";
foreach ($result['items'] as $item) {
    echo "  - {$item['title']}: \${$item['subtotal']}\n";
}

// Expected: (50×2) + (30×2) = 100 + 60 = $160
$expected = 160.0;
$test_3_pass = abs($base_price - $expected) < 0.01 && abs($total_price - $expected) < 0.01;

echo 'TEST 3 RESULT: ' . ($test_3_pass ? 'PASS' : 'FAIL') . "\n\n";

cleanup_test_space($space_a);
cleanup_test_space($space_b);

// ============================================================
// SUMMARY
// ============================================================
echo "=== SUMMARY ===\n";
echo 'Test 1 (Base Rate): ' . ($test_1_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 2 (Weekend Modifier): ' . ($test_2_pass ? 'PASS' : 'FAIL') . "\n";
echo 'Test 3 (Multi-Space Totals): ' . ($test_3_pass ? 'PASS' : 'FAIL') . "\n";

$all_pass = $test_1_pass && $test_2_pass && $test_3_pass;
echo "\nOVERALL: " . ($all_pass ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED') . "\n";
