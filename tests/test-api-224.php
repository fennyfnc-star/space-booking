<?php
/** Test script to verify space 224 availability works with the multi-space endpoint */
require_once __DIR__ . '/bootstrap.php';

// Simulate REST API request for space 224
$space_ids = [224];
$date = '2026-05-10';

echo "=== TEST: Space 224 Multi-Availability API ===\n";
echo 'Space IDs: ' . implode(', ', $space_ids) . "\n";
echo "Date: $date\n\n";

// Load the controller
$controller = new SpaceBooking\Controllers\AvailabilityController();
$request = new WP_REST_Request('GET', '/space-booking/v1/availability/multi');
$request->set_param('space_ids', $space_ids);
$request->set_param('date', $date);

$response = $controller->get_multi_availability($request);
$data = $response->get_data();

echo "Response:\n";
echo '  - is_multi: ' . ($data['is_multi'] ? 'true' : 'false') . "\n";
echo '  - slots count: ' . count($data['slots']) . "\n";
echo '  - has_fixed_slots: ' . ($data['has_fixed_slots'] ? 'true' : 'false') . "\n";

if (!empty($data['slots'])) {
    echo "\nAvailable Slots:\n";
    foreach ($data['slots'] as $slot) {
        $status = $slot['available'] ? 'Available' : 'Booked';
        echo "  - {$slot['start']} - {$slot['end']}: $status\n";
    }
} else {
    echo "\nNo slots returned!\n";
    if (!empty($data['blockers'])) {
        echo "Blockers:\n";
        foreach ($data['blockers'] as $blocker) {
            echo "  - {$blocker['title']}: {$blocker['reason']}\n";
        }
    }
}

echo "\n=== TEST COMPLETE ===\n";

// Exit with appropriate code
if (!empty($data['slots'])) {
    echo "✅ PASS: Space 224 returns slots correctly\n";
    exit(0);
} else {
    echo "❌ FAIL: Space 224 returns no slots\n";
    exit(1);
}
