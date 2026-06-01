<?php

/**
 * Test full booking flow with new schema (no lead/shadow)
 * Tests:
 * 1. Create booking with multiple spaces
 * 2. Link spaces to booking via sb_booking_spaces
 * 3. Link extras to booking via sb_booking_extras
 * 4. Link packages to booking via sb_booking_packages
 * 5. Query availability - spaces should block correctly
 * 6. Retrieve booking with all linked data
 */
require_once __DIR__ . '/bootstrap.php';

global $wpdb;

echo "=== Testing New Booking Flow ===\n\n";

// Setup - ensure schema exists
echo "1. Setting up schema...\n";
require_once dirname(__DIR__, 2) . '/includes/Migrations/CreateNewSchema.php';
$schema = new \SpaceBooking\Migrations\CreateNewSchema();
$schema->run();
echo "   Schema created/verified.\n\n";

// Test data
$test_date = date('Y-m-d', strtotime('+2 days'));
$test_start = '10:00';
$test_end = '12:00';

// Create test spaces if they don't exist
$space_ids = [];
for ($i = 1; $i <= 3; $i++) {
    $post_id = wp_insert_post([
        'post_type' => 'sb_space',
        'post_title' => 'Test Space ' . $i,
        'post_status' => 'publish',
    ]);
    if ($post_id) {
        update_post_meta($post_id, '_sb_space_price', 50.0 * $i);
        $space_ids[] = $post_id;
        echo "   Created space ID: $post_id\n";
    }
}

if (count($space_ids) < 2) {
    echo "ERROR: Need at least 2 spaces for testing. Exiting.\n";
    exit(1);
}

echo "\n2. Creating booking with multiple spaces...\n";
$repo = new \SpaceBooking\Services\BookingRepository();

// Create main booking
$booking_id = $repo->create([
    'space_id' => $space_ids[0],  // Primary space
    'booking_date' => $test_date,
    'start_time' => $test_start,
    'end_time' => $test_end,
    'customer_name' => 'Test Customer',
    'customer_email' => 'test@example.com',
    'customer_phone' => '1234567890',
    'base_price' => 100.0,
    'total_price' => 100.0,
]);

echo "   Created main booking ID: $booking_id\n";

// Now link additional spaces using the new structure
foreach ($space_ids as $idx => $space_id) {
    if ($idx === 0)
        continue;  // Skip first (already in main)

    $repo->link_space($booking_id, $space_id, $test_start, $test_end);
    echo "   Linked space $space_id to booking $booking_id\n";
}

echo "\n3. Testing linked spaces retrieval...\n";
$linked_spaces = $repo->get_linked_spaces($booking_id);
echo '   Found ' . count($linked_spaces) . " linked spaces:\n";
foreach ($linked_spaces as $ls) {
    echo "   - Space ID: {$ls['space_id']}, Time: {$ls['start_time']} - {$ls['end_time']}\n";
}

echo "\n4. Testing availability blocking with new schema...\n";
// This should block all linked spaces
$blocking = $repo->get_blocking_intervals($space_ids, $test_date);
echo '   Found ' . count($blocking) . " blocking intervals\n";
foreach ($blocking as $b) {
    echo "   - {$b['start']} - {$b['end']}\n";
}

echo "\n5. Testing extras linking...\n";
// Create a test extra
$extra_id = wp_insert_post([
    'post_type' => 'sb_extra',
    'post_title' => 'Test Extra',
    'post_status' => 'publish',
]);
update_post_meta($extra_id, '_sb_extra_price', 25.0);

if ($extra_id) {
    $repo->link_extra($booking_id, $extra_id, $test_start, $test_end, 2, 25.0);
    echo "   Linked extra $extra_id (qty: 2) to booking $booking_id\n";

    $extras = $repo->get_extras($booking_id);
    echo '   Retrieved ' . count($extras) . " extras for booking:\n";
    foreach ($extras as $e) {
        echo "   - Extra: {$e['title']}, Qty: {$e['quantity']}, Price: {$e['unit_price']}\n";
    }
}

echo "\n6. Testing package linking...\n";
// Create a test package
$package_id = wp_insert_post([
    'post_type' => 'sb_package',
    'post_title' => 'Test Package',
    'post_status' => 'publish',
]);
update_post_meta($package_id, '_sb_package_price', 75.0);

if ($package_id) {
    $repo->link_package($booking_id, $package_id, $space_ids[0]);
    echo "   Linked package $package_id (space: {$space_ids[0]}) to booking $booking_id\n";

    $packages = $repo->get_linked_packages($booking_id);
    echo '   Retrieved ' . count($packages) . " packages:\n";
    foreach ($packages as $p) {
        echo "   - Package: {$p['package_id']}, Space: {$p['space_id']}\n";
    }
}

echo "\n7. Testing enriched booking retrieval...\n";
$enriched = $repo->findEnriched($booking_id);
if ($enriched) {
    echo "   Booking ID: {$enriched['id']}\n";
    echo "   Status: {$enriched['status']}\n";
    echo "   Primary Space: {$enriched['space_id']}\n";
    echo '   Selected items: ' . count($enriched['_selected_items'] ?? []) . "\n";
    echo '   Extras: ' . count($enriched['extras'] ?? []) . "\n";
}

echo "\n=== Test Complete ===\n";

// Cleanup (optional - comment out to inspect data)
echo "\nCleaning up test data...\n";
foreach ($space_ids as $sid) {
    wp_delete_post($sid, true);
}
if ($extra_id)
    wp_delete_post($extra_id, true);
if ($package_id)
    wp_delete_post($package_id, true);
$repo->delete($booking_id);
echo "Done.\n";
