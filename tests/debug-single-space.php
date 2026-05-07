<?php

/**
 * Debug test - mimics what the frontend does when toggling spaces
 *
 * Steps in console logs:
 * 1. Select 223 first - works (4 slots)
 * 2. Switch to 10 - works (3 slots)
 * 3. Switch to 224 - empty!
 */
require_once __DIR__ . '/bootstrap.php';

$date = '2026-05-10';

echo "=== Simulating Frontend Space Selection Flow ===\n\n";

$repo = new SpaceBooking\Services\BookingRepository();
$avail = new SpaceBooking\Services\AvailabilityService($repo);

// Step 1: Select space 223
echo "--- Step 1: Select Space 223 ---\n";
$locked = [223];
$is_multi = count($locked) > 1;
$primary = $locked[0];
echo 'lockedResourceIds: [223], isMultiSpace: ' . ($is_multi ? 'true' : 'false') . ", primary: $primary\n";

$result = $avail->get_slots($primary, $date, 60);
echo 'Slots: ' . count($result['slots']) . "\n";

// Step 2: Switch to space 10 (previously "Switch to 10" in logs)
echo "\n--- Step 2: Select Space 10 (lock becomes [10]) ---\n";
$locked = [10];
$is_multi = count($locked) > 1;
$primary = $locked[0];
echo 'lockedResourceIds: [10], isMultiSpace: ' . ($is_multi ? 'true' : 'false') . ", primary: $primary\n";

$result = $avail->get_slots($primary, $date, 60);
echo 'Slots: ' . count($result['slots']) . "\n";

// Step 3: Switch to space 224 - THIS IS WHERE IT BREAKS
echo "\n--- Step 3: Select Space 224 (lock becomes [224]) ---\n";
$locked = [224];
$is_multi = count($locked) > 1;
$primary = $locked[0];
echo 'lockedResourceIds: [224], isMultiSpace: ' . ($is_multi ? 'true' : 'false') . ", primary: $primary\n";

$result = $avail->get_slots($primary, $date, 60);
echo 'Slots: ' . count($result['slots']) . "\n";

// Now test what happens in MULTI-space mode
echo "\n=== TEST: Multi-space mode with [224, 10] ===\n";
$result_multi = $avail->get_intersection_slots([224, 10], $date, 60);
echo 'Slots: ' . count($result_multi['slots']) . "\n";
echo 'Blockers: ' . count($result_multi['blockers']) . "\n";

echo "\n=== TEST: Multi-space mode with [224, 223] ===\n";
$result_multi2 = $avail->get_intersection_slots([224, 223], $date, 60);
echo 'Slots: ' . count($result_multi2['slots']) . "\n";
echo 'Blockers: ' . count($result_multi2['blockers']) . "\n";

if (!empty($result_multi2['blockers'])) {
    foreach ($result_multi2['blockers'] as $b) {
        echo "  Blocker: {$b['title']} - {$b['reason']}\n";
    }
}

echo "\n=== END ===\n";
