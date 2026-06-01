<?php

/**
 * Debug test for availability issue with space 224
 *
 * This test verifies that space 224 returns availability slots
 * Run via: php tests/debug-availability-224.php
 */
require_once __DIR__ . '/bootstrap.php';

// Test each space individually
$space_ids = [223, 10, 224];
$date = '2026-05-10';

echo "=== DEBUG: Availability Check for Spaces 223, 10, 224 ===\n";
echo "Date: $date\n\n";

$repo = new SpaceBooking\Services\BookingRepository();
$avail = new SpaceBooking\Services\AvailabilityService($repo);

foreach ($space_ids as $space_id) {
    echo "--- Checking Space ID: $space_id ---\n";

    // Get space title
    $space_title = get_the_title($space_id);
    echo "Space Title: $space_title\n";

    // Check if space exists
    $post = get_post($space_id);
    echo 'Post exists: ' . ($post ? 'YES' : 'NO') . "\n";
    if ($post) {
        echo 'Post type: ' . $post->post_type . "\n";
    }

    // Check fixed slots defined
    $fixed_slots = get_post_meta($space_id, '_sb_fixed_slots', true);
    echo 'Fixed slots meta: ' . (is_array($fixed_slots) ? json_encode($fixed_slots) : 'empty/not set') . "\n";

    // Check day overrides
    $day_overrides = get_post_meta($space_id, '_sb_day_overrides', true);
    echo 'Day overrides: ' . (is_array($day_overrides) ? json_encode($day_overrides) : 'empty/not set') . "\n";

    // Check global hours
    $global_open = get_option('sb_global_open_time', '09:00');
    $global_close = get_option('sb_global_close_time', '22:00');
    echo "Global hours: $global_open - $global_close\n";

    // Get hours from service
    [$open, $close] = $avail->resolve_hours($space_id, $date);
    echo "Resolved hours (space specific): $open - $close\n";

    // Get effective hours (with buffers)
    [$eff_open, $eff_close] = $avail->resolve_effective_hours($space_id, $date);
    echo "Effective hours (with buffers): $eff_open - $eff_close\n";

    // Check has_fixed_slots
    $has_fixed = $avail->has_fixed_slots_defined($space_id);
    echo 'Has fixed slots defined: ' . ($has_fixed ? 'YES' : 'NO') . "\n";

    // Get slots
    $result = $avail->get_slots($space_id, $date, 60);
    $slots = $result['slots'] ?? [];
    echo 'Slots returned: ' . count($slots) . "\n";

    if (!empty($slots)) {
        echo "Slot details:\n";
        foreach ($slots as $slot) {
            echo "  - {$slot['start']} - {$slot['end']}: " . ($slot['available'] ? 'AVAILABLE' : 'BLOCKED') . "\n";
        }
    } else {
        echo "WARNING: No slots returned!\n";
    }

    // Check blocking intervals
    $blockers = $repo->get_blocking_intervals([$space_id], $date);
    echo 'Blocking intervals: ' . count($blockers) . "\n";
    foreach ($blockers as $b) {
        echo "  - {$b['start']} - {$b['end']}\n";
    }

    echo "\n";
}

echo "=== END DEBUG ===\n";
