<?php

/**
 * Admin Bookings Page Template
 * Tabs: Calendar Grid | List View
 */
defined('ABSPATH') || exit;

/**
 * Convert 24-hour time to 12-hour format with AM/PM
 */
function sb_format_time_12hour(string $time): string
{
    if (empty($time) || strlen($time) < 5)
        return $time;
    [$hourStr, $minuteStr] = explode(':', substr($time, 0, 5));
    $hour = (int) $hourStr;
    $minutes = $minuteStr;
    $period = $hour >= 12 ? 'PM' : 'AM';
    $hour = $hour % 12;
    if ($hour === 0)
        $hour = 12;
    return sprintf('%d:%s %s', $hour, $minutes, $period);
}

// ── Filters Form ─────────────────────────────────────────────────────────
?>
<style>
.sb-admin-bookings {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto;
}

.sb-tabs {
    display: flex;
    border-bottom: 1px solid #c3c4c7;
    margin: 0 0 20px;
}

.sb-tab-btn {
    background: none;
    border: none;
    padding: 12px 24px;
    cursor: pointer;
    font-size: 14px;
    color: #50575e;
    border-bottom: 2px solid transparent;
}

.sb-tab-btn.active {
    color: #1d2327;
    border-bottom-color: #2271b1;
}

.sb-tab-content {
    display: none;
}

.sb-tab-content.active {
    display: block;
}

.sb-calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 12px;
}

.sb-day-cell {
    border: 1px solid #e0e3e6;
    border-radius: 6px;
    padding: 12px;
    background: #fff;
}

.sb-day-header {
    font-weight: 600;
    margin: 0 0 8px;
    color: #1d2327;
}

.sb-booking {
    background: #f6f7f7;
    border-radius: 4px;
    padding: 6px 8px;
    margin: 4px 0;
    font-size: 13px;
}

.sb-status {
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
}

.sb-status--confirmed {
    background: #d4edda;
    color: #155724;
}

.sb-status--pending {
    background: #fff3cd;
    color: #856404;
}

.sb-status--in_review {
    background: #cce5ff;
    color: #004085;
}

.sb-status--trashed {
    background: #f8d7da;
    color: #842029;
}

.sb-month-section {
    margin-bottom: 40px;
}

.sb-month-section h3 {
    margin: 0 0 20px;
    color: #1d2327;
    border-bottom: 2px solid #2271b1;
    padding-bottom: 8px;
}

.sb-filters {
    background: #fff;
    border: 1px solid #c3c4c7;
    padding: 16px;
    border-radius: 6px;
    margin-bottom: 24px;
}

.sb-filters select,
.sb-filters input {
    margin-right: 12px;
    padding: 6px 10px;
    border: 1px solid #8c8f94;
    border-radius: 3px;
}

@media (max-width: 782px) {
    .sb-calendar-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
</style>

<div class="sb-admin-bookings">
    <h1><?php esc_html_e('Tools', 'space-booking'); ?></h1>


    <div class="sb-tabs">
        <button class="sb-tab-btn active" data-tab="bookings"><?php esc_html_e('Bookings', 'space-booking'); ?></button>
    </div>

    <div id="bookings-tab" class="sb-tab-content active">

        <form method="get" class="sb-filters">
            <input type="hidden" name="page" value="space-booking-bookings">
            <select name="status">
                <option value=""><?php esc_html_e('All Status', 'space-booking'); ?></option>
                <option value="confirmed" <?php selected($_GET['status'] ?? '', 'confirmed'); ?>>Confirmed</option>
                <option value="in_review" <?php selected($_GET['status'] ?? '', 'in_review'); ?>>In Review</option>
                <option value="pending" <?php selected($_GET['status'] ?? '', 'pending'); ?>>Pending</option>
                <option value="trashed" <?php selected($_GET['status'] ?? '', 'trashed'); ?>>Trashed</option>
            </select>
            <select name="space_id">
                <option value=""><?php esc_html_e('All Spaces', 'space-booking'); ?></option>
                <?php
                $spaces = get_posts(['post_type' => 'sb_space', 'posts_per_page' => -1, 'post_status' => 'publish']);
                foreach ($spaces as $space) {
                    echo '<option value="' . $space->ID . '"' . selected($_GET['space_id'] ?? '', $space->ID, false) . '>' . esc_html($space->post_title) . '</option>';
                }
                ?>
            </select>
            <input type="date" name="date_from" value="<?php echo esc_attr($_GET['date_from'] ?? ''); ?>"
                placeholder="From">
            <input type="date" name="date_to" value="<?php echo esc_attr($_GET['date_to'] ?? ''); ?>" placeholder="To">
            <button type="submit" class="button"><?php esc_html_e('Filter', 'space-booking'); ?></button>
            <a href="<?php echo esc_url(remove_query_arg(['status', 'space_id', 'date_from', 'date_to'])); ?>"
                class="button button-secondary"><?php esc_html_e('Clear Filters', 'space-booking'); ?></a>
        </form>

        <div class="sb-calendar-grouped">

            <?php
            global $wpdb;
            $where = ['1=1'];
            $params = [];
            $status_input = sanitize_text_field($_GET['status'] ?? '');
            if ($status_input) {
                $where[] = 'b.status = %s';
                $params[] = $status_input;
            } else {
                $where[] = "b.status != 'trashed'";
            }
            $space_id_input = $_GET['space_id'] ?? '';
            $space_id = absint($space_id_input);
            if ($space_id) {
                $where[] = 'b.space_id = %d';
                $params[] = $space_id;
            }
            $date_from_input = sanitize_text_field($_GET['date_from'] ?? '');
            if ($date_from_input) {
                $date_from = date('Y-m-d', strtotime($date_from_input));
                if ($date_from) {
                    $where[] = 'b.booking_date >= %s';
                    $params[] = $date_from;
                }
            }
            $date_to_input = sanitize_text_field($_GET['date_to'] ?? '');
            if ($date_to_input) {
                $date_to = date('Y-m-d', strtotime($date_to_input));
                if ($date_to) {
                    $where[] = 'b.booking_date <= %s';
                    $params[] = $date_to;
                }
            }
            if (empty($date_from_input)) {
                $where[] = 'b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 36 MONTH)';
            }
            if (empty($date_to_input)) {
                $where[] = 'b.booking_date <= DATE_ADD(CURDATE(), INTERVAL 36 MONTH)';
            }
            if ($status_input && $status_input !== 'pending') {
                $where[] = '(b.status != "pending" OR b.expired_at > NOW())';
            }
            $bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT b.*, p.post_title AS space_name FROM {$wpdb->prefix}sb_bookings b 
             LEFT JOIN {$wpdb->posts} p ON p.ID = b.space_id 
             WHERE " . implode(' AND ', $where) . ' 
             ORDER BY b.booking_date, b.start_time',
                ...$params
            ), ARRAY_A) ?: [];

            $grouped = [];
            foreach ($bookings as $b) {
                $date = new DateTime($b['booking_date']);
                $month_key = $date->format('Y-m');
                $month_name = $date->format('F Y');
                $day = $date->format('j');
                $grouped[$month_key]['name'] = $month_name;
                $grouped[$month_key]['days'][$b['booking_date']]['day'] = $date->format('M j');
                $grouped[$month_key]['days'][$b['booking_date']]['bookings'][] = $b;
            }

            if (empty($grouped)) {
                echo '<p>' . esc_html__('No bookings match your filters.', 'space-booking') . '</p>';
            } else {
                foreach ($grouped as $month_data) {
                    echo '<section class="sb-month-section">';
                    echo '<h3>' . esc_html($month_data['name']) . '</h3>';
                    echo '<div class="sb-calendar-grid">';
                    $days_in_month = array_keys($month_data['days']);
                    sort($days_in_month);
                    foreach ($days_in_month as $date) {
                        $day_data = $month_data['days'][$date];
                        echo '<div class="sb-day-cell">';
                        echo '<div class="sb-day-header">' . esc_html($day_data['day']) . '</div>';
                        if (!empty($day_data['bookings'])) {
                            foreach ($day_data['bookings'] as $b) {
                                $time = sb_format_time_12hour($b['start_time']) . ' - ' . sb_format_time_12hour($b['end_time']);
                                $edit_url = admin_url('admin.php?page=space-booking-bookings&edit=' . $b['id']);
                                echo '<a href="' . esc_url($edit_url) . '" class="sb-booking" style="text-decoration:none; color:inherit; display:block;">';
                                echo esc_html($time . ' - ' . $b['customer_name']);
                                ?>
            <?php
            $marketing = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->prefix}sb_booking_meta WHERE booking_id = %d AND meta_key = '_sb_marketing_source'", $b['id']));
            if ($marketing):
                ?>
            <br><small style="opacity:0.7;">📈 <?php echo esc_html($marketing); ?></small>
            <?php endif; ?>
            <?php
                                echo ' <span class="sb-status sb-status--' . esc_attr($b['status']) . '">' . str_replace('_', ' ', esc_html(ucfirst($b['status']))) . '</span>';
                                echo '</a>';
                            }
                        }
                        echo '</div>';  // Close sb-day-cell
                    }
                    echo '</div>';  // Close sb-calendar-grid
                }
                echo '</section>';
            }
            ?>
        </div> <!-- Close sb-calendar-grouped -->
    </div> <!-- Close tab-content -->
</div>
</div>



<script>
jQuery(document).ready(function($) {
    // No JS needed for bookings-only page

    // Tab switching
    $('.sb-tab-btn').on('click', function() {
        $('.sb-tab-btn').removeClass('active');
        $('.sb-tab-content').removeClass('active');
        $(this).addClass('active');
        $('#' + $(this).data('tab') + '-tab').addClass('active');
    });

    // Add field
    $('#sb-add-field').on('click', function() {
        const newFieldHtml = `
                <div class="sb-field-row" data-index="${fieldIndex}">
                    <div class="sb-field-col"><label>Key <span class="required">*</span></label><input type="text" name="fields[${fieldIndex}][key]" required maxlength="50" /></div>
                    <div class="sb-field-col"><label>Label <span class="required">*</span></label><input type="text" name="fields[${fieldIndex}][label]" required maxlength="100" /></div>
                    <div class="sb-field-col"><label>Type <span class="required">*</span></label>
                        <select name="fields[${fieldIndex}][type]" required>
                            <option value="text">Text</option><option value="email">Email</option><option value="tel">Phone</option>
                            <option value="textarea">Textarea</option><option value="checkbox">Checkbox</option>
                            <option value="radio">Radio</option><option value="select">Dropdown</option>
                        </select>
                    </div>
                    <div class="sb-field-col"><label><input type="checkbox" name="fields[${fieldIndex}][required]" /> Required</label></div>
                    <div class="sb-field-col"><label>Placeholder</label><input type="text" name="fields[${fieldIndex}][placeholder]" maxlength="100" /></div>
                    <div class="sb-field-col"><label>Default</label><input type="text" name="fields[${fieldIndex}][default]" /></div>
                    <div class="sb-field-col sb-options-col"><label>Options (JSON)</label><textarea name="fields[${fieldIndex}][options]" rows="2" placeholder='["Opt1","Opt2"]'></textarea><small>Radio/Select only</small></div>
                    <div class="sb-field-actions"><button type="button" class="button-link sb-remove-field">×</button><div class="sb-drag-handle">⋮⋮</div></div>
                </div>`;
        $('#sb-fields-repeater').append(newFieldHtml);
        fieldIndex++;
    });

    // Remove field
    $(document).on('click', '.sb-remove-field', function() {
        $(this).closest('.sb-field-row').remove();
    });

    // Save fields
    $('#sb-save-fields').on('click', function() {
        const fieldsData = [];
        $('#sb-fields-repeater .sb-field-row').each(function() {
            const row = $(this);
            const field = {
                key: row.find('[name*="[key]"]').val(),
                label: row.find('[name*="[label]"]').val(),
                type: row.find('[name*="[type]"]').val(),
                required: row.find('[name*="[required]"]').is(':checked'),
                placeholder: row.find('[name*="[placeholder]"]').val(),
                default: row.find('[name*="[default]"]').val(),
                options: row.find('[name*="[options]"]').val()
            };
            fieldsData.push(field);
        });

        if (fieldsData.length === 0) {
            $('#sb-fields-status').html('<span class="error">At least one field required</span>');
            return;
        }

        $.post(ajaxurl, {
            action: 'sb_save_customer_fields',
            fields: JSON.stringify(fieldsData),
            _wpnonce: nonce
        }, function(res) {
            if (res.success) {
                $('#sb-fields-status').html('<span style="color:green">✓ ' + res.data
                    .message + '</span>');
                updatePreview(fieldsData);
            } else {
                $('#sb-fields-status').html('<span class="error">✗ ' + res.data +
                    '</span>');
            }
        });
    });

    function updatePreview(fields) {
        let preview = '';
        fields.forEach(function(field) {
            preview += '<div class="sb-field-preview"><label>' + field.label + (field.required ?
                ' *' : '') + '</label>';
            if (field.type === 'textarea') {
                preview += '<textarea placeholder="' + (field.placeholder || '') +
                    '" style="width:300px;height:60px"></textarea>';
            } else if (field.type === 'checkbox') {
                preview += '<input type="checkbox">';
            } else if (field.type === 'radio' || field.type === 'select') {
                preview += field.type === 'radio' ? '<label><input type="radio"> Opt1</label>' :
                    '<select><option>Opt1</option></select>';
            } else {
                preview += '<input type="' + field.type + '" placeholder="' + (field.placeholder ||
                    '') + '" style="width:300px" />';
            }
            preview += '</div>';
        });
        $('#sb-fields-preview').html(preview);
    }

    // Initial preview
    <?php if (!empty($fields)): ?>
    updatePreview(<?php echo json_encode($fields); ?>);
    <?php endif; ?>
});
</script>

<!-- No customizer styles needed -->
<style>
#sb-customize-fields label {
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 4px;
    display: block;
}

#sb-customize-fields input,
#sb-customize-fields select,
#sb-customize-fields textarea {
    width: 100%;
    padding: 6px;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-size: 13px;
}

#sb-customize-fields .sb-field-col {
    display: flex;
    flex-direction: column;
}

#sb-customize-fields .sb-field-actions {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
}

#sb-customize-fields .sb-drag-handle {
    cursor: grab;
    font-size: 20px;
    user-select: none;
}

#sb-customize-fields .sb-drag-handle:active {
    cursor: grabbing;
}

#sb-customize-fields .required {
    color: #d63638;
}

#sb-customize-fields .sb-options-col small {
    font-size: 11px;
    color: #666;
}

.sb-field-preview {
    margin-bottom: 15px;
}

.sb-field-preview label {
    font-weight: 600;
}

.sb-field-preview input,
.sb-field-preview textarea {
    border: 1px solid #ccc;
    padding: 8px;
    border-radius: 4px;
}

#sb-fields-status .error {
    color: #d63638;
}

@media (max-width: 1200px) {
    #sb-customize-fields .sb-field-row {
        grid-template-columns: 1fr 1fr 1fr 1fr;
    }

    #sb-customize-fields .sb-options-col {
        grid-column: 1 / -1;
    }

    #sb-customize-fields .sb-field-actions {
        grid-column: -1;
        justify-self: end;
    }
}
</style>

<?php
?>
