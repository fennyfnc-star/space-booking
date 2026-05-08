<?php

/**
 * Test for verifying the DateTime fix in HasDynamicSlots trait.
 *
 * This test verifies that resolve_hours() correctly uses \DateTime
 * (global namespace) instead of trying to find a non-existent
 * SpaceBooking\Services\Traits\DateTime class.
 *
 * Run via: php tests/TestDynamicSlotsDateTimeFix.php
 */
require_once __DIR__ . '/bootstrap.php';

use SpaceBooking\Services\AvailabilityService;

// Test 1: Create AvailabilityService which uses the HasDynamicSlots trait
echo "=== Test: DateTime fix in HasDynamicSlots ===\n\n";

$avail = new AvailabilityService();

// Test resolve_hours for a space with default/global hours
$space_id = 223;  // Use existing space
$date = '2026-05-23';  // A Saturday

echo "Testing resolve_hours($space_id, $date)...\n";

try {
    $result = $avail->resolve_hours($space_id, $date);
    echo 'Result: open=' . ($result[0] ?? 'null') . ', close=' . ($result[1] ?? 'null') . "\n";

    if ($result[0] !== null && $result[1] !== null) {
        echo "✅ PASS: resolve_hours returned valid hours\n";
        exit(0);
    } else {
        echo "❌ FAIL: resolve_hours returned null values\n";
        exit(1);
    }
} catch (Error $e) {
    echo '❌ FAIL: Error caught: ' . $e->getMessage() . "\n";
    echo '   Class: ' . $e->getTrace()[0]['class'] ?? 'N/A' . "\n";
    echo '   File: ' . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}
