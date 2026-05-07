<?php

/**
 * Debug test for multi-space availability issue
 * Tests the get_intersection_slots logic which is used for multi-space
 */
require_once __DIR__ . '/bootstrap.php';

$space_ids = [223, 10, 224];
$date = '2026-05-10';

echo "=== DEBUG: Multi-Space Availability Check ===\n";
echo "Date: $date\n\n";

$repo = new SpaceBooking\Services\BookingRepository();
$avail = new SpaceBooking\Services\AvailabilityService($repo);

// Test each space individually first
echo "--- Individual Space Checks ---\n";
foreach ($space_ids as $space_id) {
    $result = $avail->get_slots($space_id, $date, 60);
    $slots = $result['slots'] ?? [];
    echo "Space $space_id: " . count($slots) . " slots\n";
}

echo "\n--- Multi-Space Intersection Check (using get_intersection_slots) ---\n";

// This is the method the multi-space endpoint uses
$result = $avail->get_intersection_slots($space_ids, $date, 60);

echo 'Result keys: ' . implode(', ', array_keys($result)) . "\n";
echo 'is_intersection: ' . ($result['is_intersection'] ? 'YES' : 'NO') . "\n";
echo 'Slots returned: ' . count($result['slots']) . "\n";
echo 'Blockers count: ' . count($result['blockers']) . "\n";

if (!empty($result['blockers'])) {
    echo "Blockers:\n";
    foreach ($result['blockers'] as $blocker) {
        echo "  - ID: {$blocker['id']}, Title: {$blocker['title']}, Reason: {$blocker['reason']}\n";
    }
}

if (!empty($result['slots'])) {
    echo "Slots:\n";
    foreach ($result['slots'] as $slot) {
        echo "  - {$slot['start']} - {$slot['end']}: " . ($slot['available'] ? 'AVAILABLE' : 'BLOCKED') . "\n";
    }
}

// Test with just 224
echo "\n--- Single space 224 using intersection method ---\n";
$result_224 = $avail->get_intersection_slots([224], $date, 60);
echo 'Slots for 224 alone: ' . count($result_224['slots']) . "\n";

// Test with [223, 224]
echo "\n--- Pair [223, 224] intersection ---\n";
$result_pair = $avail->get_intersection_slots([223, 224], $date, 60);
echo 'is_intersection: ' . ($result_pair['is_intersection'] ? 'YES' : 'NO') . "\n";
echo 'Slots: ' . count($result_pair['slots']) . "\n";
if (!empty($result_pair['blockers'])) {
    echo "Blockers:\n";
    foreach ($result_pair['blockers'] as $blocker) {
        echo "  - {$blocker['title']}: {$blocker['reason']}\n";
    }
}

echo "\n=== END ===\n";
