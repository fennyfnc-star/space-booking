<?php
/**
 * Check existing bookings that might be blocking space 224
 */

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

$date = '2026-05-10';
$space_ids = [223, 10, 224];

echo "=== Checking existing bookings for $date ===\n\n";

foreach ($space_ids as $space_id) {
    $title = get_the_title($space_id);
    echo "--- Space $space_id: $title ---\n";
    
    $bookings = $wpdb->get_results($wpdb->prepare(
        "SELECT id, space_id, start_time, end_time, status, customer_name 
         FROM {$wpdb->prefix}sb_bookings 
         WHERE space_id = %d AND booking_date = %s 
         ORDER BY start_time",
        $space_id, $date
    ), ARRAY_A);
    
    if (empty($bookings)) {
        echo "No bookings\n";
    } else {
        foreach ($bookings as $b) {
            echo "  ID {$b['id']}: {$b['start_time']} - {$b['end_time']} [{$b['status']}] ({$b['customer_name']})\n";
        }
    }
    echo "\n";
}

echo "=== END ===\n";