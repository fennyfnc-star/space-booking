<?php
/** Debug test to trace the AvailabilityService blocking detection issue */
require_once __DIR__ . '/bootstrap.php';

echo "=== DEBUG: AvailabilityService Blocking Detection ===\n\n";

global $wpdb;
$repo = new \SpaceBooking\Services\BookingRepository();
$avail = new \SpaceBooking\Services\AvailabilityService($repo);

$date = '2026-05-16';
$slot = ['start' => '18:30:00', 'end' => '20:30:00'];
$space_id = 223;

echo "=== STEP 1: Create test booking ===\n";
$order_id = 999;

// Clean up first
$wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $order_id], ['%d']);

// Create booking
$repo->create_booking_row([
    'space_id' => $space_id,
    'order_id' => $order_id,
    'booking_date' => $date,
    'start_time' => $slot['start'],
    'end_time' => $slot['end'],
    'status' => 'in_review'
]);
echo "Created booking for space $space_id at $slot[start] to $slot[end]\n";

echo "\n=== STEP 2: Direct Repository call ===\n";
$blocking_from_repo = $repo->get_blocking_intervals([$space_id], $date);
echo 'Repository get_blocking_intervals returned ' . count($blocking_from_repo) . " blocking intervals:\n";
foreach ($blocking_from_repo as $b) {
    echo "  - {$b['start']} to {$b['end']}\n";
}

echo "\n=== STEP 3: AvailabilityService get_slots call ===\n";
$slots_result = $avail->get_slots([$space_id], $date, 60);
$slots = $slots_result['slots'] ?? [];
echo 'AvailabilityService get_slots returned ' . count($slots) . " slots\n";

// Find 18:30 slot
foreach ($slots as $s) {
    if ($s['start'] === '18:30') {
        echo '  18:30 slot: available=' . ($s['available'] ? 'true' : 'false') . "\n";
    }
}

echo "\n=== STEP 4: Check get_fixed_slots_single directly ===\n";
// Use reflection to call private method
$reflection = new ReflectionClass($avail);
$method = $reflection->getMethod('get_fixed_slots_single');
$method->setAccessible(true);
$fixed_slots = $method->invoke($avail, $space_id, $date);
echo 'get_fixed_slots_single returned ' . count($fixed_slots) . " slots\n";
foreach ($fixed_slots as $s) {
    echo "  {$s['start']}-{$s['end']}: available=" . ($s['available'] ? 'true' : 'false') . "\n";
}

echo "\n=== STEP 5: Check what get_blocking_intervals returns inside AvailabilityService context ===\n";
// Test the internal call
$internal_blocking = $repo->get_blocking_intervals([$space_id], $date);
echo 'Internal blocking: ' . count($internal_blocking) . " intervals\n";
foreach ($internal_blocking as $b) {
    echo "  - {$b['start']} to {$b['end']}\n";
}

// Cleanup
$wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $order_id], ['%d']);
echo "\n=== CLEANUP ===\nDeleted test booking\n";

echo "\n=== ANALYSIS ===\n";
if (count($blocking_from_repo) > 0 && count($fixed_slots) > 0) {
    foreach ($fixed_slots as $s) {
        if ($s['start'] === '18:30' && $s['available']) {
            echo "BUG CONFIRMED: Repository found blocking but AvailabilityService shows available=true!\n";
        }
    }
}
