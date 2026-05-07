<?php

/**
 * Test REST API endpoint for availability
 * Makes actual HTTP requests to test what the frontend receives
 */
require_once __DIR__ . '/bootstrap.php';

// Get the REST base URL
$base = get_option('sb_rest_url', '');
// Fallback - try to construct from site_url
if (empty($base)) {
    $site_url = get_site_url();
    $base = $site_url . '/wp-json/space-booking/v1';
}

$date = '2026-05-10';

echo "=== Testing REST API Availability Endpoints ===\n";
echo "Base URL: $base\n\n";

// Test single space endpoint
$spaces = [
    223 => 'Covered Secret Garden',
    10 => 'Main Cafe',
    224 => 'Uncovered Lawn Space'
];

foreach ($spaces as $space_id => $title) {
    $url = "$base/availability?space_id=$space_id&date=$date";
    echo "--- Testing /availability?space_id=$space_id ---\n";

    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        echo 'ERROR: ' . $response->get_error_message() . "\n";
        continue;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    echo "HTTP Code: $code\n";
    echo 'Slots returned: ' . count($data['slots'] ?? []) . "\n";

    if (!empty($data['slots'])) {
        foreach ($data['slots'] as $slot) {
            $status = !empty($slot['available']) ? 'AVAIL' : 'BOOKED';
            echo "  {$slot['start']} - {$slot['end']}: $status\n";
        }
    }

    if (!empty($data['message'])) {
        echo "Message: {$data['message']}\n";
    }

    echo "\n";
}

echo "=== END ===\n";
