<?php
/** Check date-specific overrides for each space on 2026-05-10 */
require_once __DIR__ . '/bootstrap.php';

$space_ids = [223, 10, 224];
$date = '2026-05-10';

echo "=== Checking date-specific overrides for $date ===\n\n";

foreach ($space_ids as $space_id) {
    echo "--- Space $space_id ---\n";

    // Check date overrides
    $date_overrides = get_post_meta($space_id, '_sb_date_overrides', true);

    if (is_array($date_overrides) && isset($date_overrides[$date])) {
        echo "Date override for $date: " . json_encode($date_overrides[$date]) . "\n";
    } else {
        echo "No date-specific override for $date\n";
    }

    // Check fixed slots default
    $fixed_slots = get_post_meta($space_id, '_sb_fixed_slots', true);
    echo 'Fixed slots count: ' . count($fixed_slots ?? []) . "\n";

    echo "\n";
}

echo "=== END ===\n";
