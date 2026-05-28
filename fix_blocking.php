<?php
/** Fix get_blocking_intervals with time normalization */
$file = __DIR__ . '/includes/Services/BookingRepository.php';
$content = file_get_contents($file);

// Find and replace the return statement in get_blocking_intervals
$old = "return \$wpdb->get_results(\$query, ARRAY_A) ?: [];\n\t}";
$new = "\$results = \$wpdb->get_results(\$query, ARRAY_A) ?: [];\n\n\t\t// FIX: Normalize time strings for consistent matching\n\t\tforeach (\$results as &\$row) {\n\t\t\t\$row['start'] = date('H:i', strtotime(\$row['start']));\n\t\t\t\$row['end'] = date('H:i', strtotime(\$row['end']));\n\t\t}\n\n\t\treturn \$results;\n\t}";

if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    file_put_contents($file, $content);
    echo "Fixed get_blocking_intervals\n";
} else {
    echo "Pattern not found - checking...\n";
    // Show what's there
    $start = strpos($content, 'public function get_blocking_intervals');
    if ($start !== false) {
        $snippet = substr($content, $start, 500);
        echo substr($snippet, 0, 300);
    }
}