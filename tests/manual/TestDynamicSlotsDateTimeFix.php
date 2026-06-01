<?php

/**
 * Test for DateTime namespace fix in HasDynamicSlots.
 *
 * This test verifies that the DateTime class is properly referenced as \DateTime
 * (global namespace) rather than SpaceBooking\Services\Traits\DateTime.
 *
 * Run: php tests/TestDynamicSlotsDateTimeFix.php
 */
require_once __DIR__ . '/bootstrap.php';

echo "=== DateTime Namespace Fix Test ===\n\n";

// Test 1: Verify the trait file has the correct \DateTime reference
$trait_file = __DIR__ . '/../../includes/Services/Traits/HasDynamicSlots.php';
$content = file_get_contents($trait_file);

$has_correct_reference = strpos($content, 'new \DateTime($date)') !== false;
$has_incorrect_reference = strpos($content, 'new DateTime($date)') !== false;

echo "TEST 1: Check trait uses \DateTime (global namespace)\n";
if ($has_correct_reference && !$has_incorrect_reference) {
    echo "  PASS: Uses \DateTime correctly\n";
} else if ($has_incorrect_reference) {
    echo "  FAIL: Still uses DateTime without backslash (will cause 'Class not found' error)\n";
    echo "  FIX: Replace 'new DateTime' with 'new \DateTime'\n";
} else {
    echo "  FAIL: DateTime reference not found\n";
}

// Test 2: Verify HasOverlapDetection is called as instance method, not static
$has_static_call = strpos($content, 'HasOverlapDetection::overlaps(') !== false;
$has_instance_call = strpos($content, '$this->overlaps(') !== false;

echo "\nTEST 2: Check overlap detection uses instance method\n";
if ($has_instance_call && !$has_static_call) {
    echo "  PASS: Uses \$this->overlaps() correctly\n";
} else if ($has_static_call) {
    echo "  FAIL: Uses static call HasOverlapDetection::overlaps()\n";
    echo "  FIX: Replace with \$this->overlaps()\n";
} else {
    echo "  PASS: Overlap check not found or uses different pattern\n";
}

// Test 3: Verify DateTime works with global namespace
echo "\nTEST 3: Verify global \DateTime works\n";
try {
    $test_date = '2026-05-09';
    $dt = new \DateTime($test_date);
    $day_of_week = (int) $dt->format('w');

    echo "  DateTime('$test_date')->format('w') = $day_of_week\n";

    if ($day_of_week >= 0 && $day_of_week <= 6) {
        echo "  PASS: Global \DateTime works correctly\n";
    } else {
        echo "  WARN: Unexpected day of week\n";
    }
} catch (Error $e) {
    echo '  FAIL: Error occurred: ' . $e->getMessage() . "\n";
}

echo "\n=== SUMMARY ===\n";
if ($has_correct_reference && !$has_incorrect_reference && $has_instance_call) {
    echo "All checks PASSED - DateTime namespace fix is correct\n";
} else {
    echo "Some checks FAILED - review the output above\n";
}

echo "\nDone.\n";
