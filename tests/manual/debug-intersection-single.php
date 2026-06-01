<?php
/** Test get_intersection_slots with single element array (matches frontend code path) */
require_once __DIR__ . '/bootstrap.php';

$date = '2026-05-10';

echo "=== Testing get_intersection_slots with [224] ===\n\n";

$repo = new SpaceBooking\Services\BookingRepository();
$avail = new SpaceBooking\Services\AvailabilityService($repo);

// This is what happens in frontend when isMultiSpace=false but activeSpaceIds=[224]
// The code calls fetchAvailability(spaceId, selectedDate) NOT get_intersection_slots
// But let's test get_intersection_slots to see what it returns

$result = $avail->get_intersection_slots([224], $date, 60);

echo 'is_intersection: ' . ($result['is_intersection'] ? 'YES' : 'NO') . "\n";
echo 'slots: ' . count($result['slots']) . "\n";
echo 'blockers: ' . count($result['blockers']) . "\n\n";

// But actually in the frontend, isMultiSpace is false, so it calls get_slots directly
// Let's test that path

echo "=== Testing get_slots (direct) for 224 = front-end code path ===\n";
$result_direct = $avail->get_slots(224, $date, 60);
echo 'slots: ' . count($result_direct['slots']) . "\n";

foreach ($result_direct['slots'] as $slot) {
    echo "  {$slot['start']} - {$slot['end']}: " . ($slot['available'] ? 'AVAIL' : 'BLOCKED') . "\n";
}

echo "\n=== END ===\n";
