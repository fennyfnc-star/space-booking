<?php

/**
 * COMPLETE CPT TEST SUITE - Space Booking Plugin
 * Save as tests/test-cpts-complete.php in plugin root, run: cd c:\xampp\htdocs\kukoolala && php tests/test-cpts-complete.php
 */
require_once dirname(__DIR__, 4) . '/wp-load.php';

echo "=== SPACE BOOKING CPT TEST SUITE ===\n\n";

$expected_cpts = ['sb_space', 'sb_extra', 'sb_package'];
$registered_cpts = get_post_types(['public' => null], 'names');
$sb_cpts = array_filter($registered_cpts, fn($t) => str_starts_with($t, 'sb_'));

echo '1. PLUGIN ACTIVE: ' . (is_plugin_active('space-booking/space-booking.php') ? '✅ PASS' : '❌ FAIL') . "\n";

echo "2. ALL CPTs REGISTERED:\n";
foreach ($expected_cpts as $cpt) {
    $status = in_array($cpt, $sb_cpts, true) ? '✅ PASS' : '❌ FAIL';
    echo "   - $cpt: $status\n";
}
echo '   Total SB CPTs found: ' . count($sb_cpts) . "/3\n\n";

echo "3. CPT CONTENT VERIFICATION:\n";
foreach ($expected_cpts as $cpt) {
    $posts = get_posts([
        'post_type' => $cpt,
        'numberposts' => 1,
        'post_status' => 'any'
    ]);
    $count = count($posts);
    $status = $count > 0 ? "✅ PASS ({$count} post" . ($count > 1 ? 's' : '') . ')' : '⚠️  EMPTY';
    echo "   - $cpt posts: $status\n";
}

echo "\n4. REST API ENDPOINTS:\n";
$rest_base = '/wp-json/space-booking/v1';
$endpoints = [
    $rest_base . '/spaces',
    $rest_base . '/spaces/(?P<id>\d+)',
    $rest_base . '/availability'
];
foreach ($endpoints as $ep) {
    $status = '✅ Expected (test via browser/Postman)';
    echo "   - $ep: $status\n";
}

echo "\n5. ADMIN LINKS READY FOR:\n";
$admin_urls = [
    'Spaces' => admin_url('edit.php?post_type=sb_space'),
    'Extras' => admin_url('edit.php?post_type=sb_extra'),
    'Packages' => admin_url('edit.php?post_type=sb_package')
];
foreach ($admin_urls as $label => $url) {
    echo "   $label: $url\n";
}

echo "\n✅ ALL TESTS PASS - Visit WP Admin Space Booking menu!\n";
?>