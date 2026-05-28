<?php declare(strict_types=1);

namespace SpaceBooking\Admin;

/**
 * Meta box for the sb_extra CPT: price and inventory quantity.
 */
final class ExtraMetaBox
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add']);
        add_action('save_post_sb_extra', [$this, 'save'], 10, 2);
    }

    public function add(): void
    {
        add_meta_box(
            'sb-extra-settings',
            __('Extra Settings', 'space-booking'),
            [$this, 'render'],
            'sb_extra',
            'normal',
            'high'
        );
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field('sb_extra_save', 'sb_extra_nonce');

        $price = get_post_meta($post->ID, '_sb_extra_price', true);
        $inventory = get_post_meta($post->ID, '_sb_inventory', true) ?: 1;

        // Package ownership (multiple packages allowed)
        $package_ids = get_post_meta($post->ID, '_sb_package_ids', true);
        if (!is_array($package_ids)) {
            $package_ids = [];
        }
        $packages = get_posts(['post_type' => 'sb_package', 'posts_per_page' => -1]);

        // Allowed spaces (only when NOT owned by a package)
        $allowed = get_post_meta($post->ID, '_sb_allowed_spaces', true);
        $spaces = get_posts(['post_type' => 'sb_space', 'posts_per_page' => -1]);
        ?>
<style>
.sb-override-row {
    display: grid;
    grid-template-columns: 200px 1fr auto 30px;
    gap: 12px;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #ddd;
}

.sb-days-group {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.sb-days-group label {
    font-size: 12px;
    margin: 0;
}

.sb-time-group {
    display: flex;
    align-items: center;
    gap: 8px;
}
</style>

<table class="form-table" role="presentation">
    <tr>
        <?php $symbol = \SpaceBooking\Services\CurrencyService::get_symbol(); ?>
        <th><label for="sb_extra_price"><?php printf(esc_html__('Price (%s)', 'space-booking'), $symbol); ?></label>
        </th>
        <td><input type="number" id="sb_extra_price" name="sb_extra_price" step="0.01" min="0"
                value="<?php echo esc_attr($price); ?>" class="regular-text"></td>

    </tr>
    <tr>
        <th><label for="sb_inventory"><?php esc_html_e('Inventory Qty', 'space-booking'); ?></label></th>
        <td>
            <input type="number" id="sb_inventory" name="sb_inventory" min="1"
                value="<?php echo esc_attr($inventory); ?>" class="small-text">
            <p class="description"><?php esc_html_e('Max bookable units per time slot.', 'space-booking'); ?></p>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Assigned Packages', 'space-booking'); ?></label></th>
        <td>
            <?php if (empty($packages)): ?>
            <p class="description"><?php esc_html_e('No packages available.', 'space-booking'); ?></p>
            <?php else: ?>
            <?php foreach ($packages as $pkg): ?>
            <label style="display:block;margin-bottom:4px">
                <input type="checkbox" name="sb_package_ids[]" value="<?php echo esc_attr($pkg->ID); ?>"
                    <?php checked(in_array($pkg->ID, array_map('intval', $package_ids)), true); ?>>
                <?php echo esc_html($pkg->post_title); ?>
            </label>
            <?php endforeach; ?>
            <p class="description">
                <?php esc_html_e('If assigned to one or more packages, this extra will be included in those packages and cannot be added separately to spaces.', 'space-booking'); ?></p>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Allowed Spaces', 'space-booking'); ?></label></th>
        <td>
            <div id="sb-allowed-spaces-container">
                <?php if (!empty($package_ids)): ?>
                <p class="description" style="color:#d63638;">
                    <?php esc_html_e('This extra is assigned to package(s) and cannot be used with individual spaces.', 'space-booking'); ?>
                </p>
                <?php else: ?>
                <?php foreach ($spaces as $space): ?>
                <label style="display:block;margin-bottom:4px">
                    <input type="checkbox" name="sb_allowed_spaces[]" value="<?php echo esc_attr($space->ID); ?>"
                        <?php checked(is_array($allowed) && in_array($space->ID, $allowed, false)); ?>>
                    <?php echo esc_html($space->post_title); ?>
                </label>
                <?php endforeach; ?>
                <p class="description">
                    <?php esc_html_e('Leave all unchecked to allow in all spaces.', 'space-booking'); ?></p>
                <?php endif; ?>
            </div>
        </td>
    </tr>
</table>

<h4><?php esc_html_e('Per-Space Availability Overrides', 'space-booking'); ?></h4>
<p class="description">
    <?php esc_html_e('Override availability for specific spaces/days/times. Check "Closed" or set time window.', 'space-booking'); ?>
</p>
<div id="sb-avail-overrides">
    <?php
    $avail_overrides = get_post_meta($post->ID, '_sb_space_avail_overrides', true);
    if (!is_array($avail_overrides))
        $avail_overrides = [];
    foreach ($avail_overrides as $i => $ov):
        $space_obj = get_post($ov['space_id']);
        ?>
    <div class="sb-override-row">
        <select name="sb_avail_overrides[<?php echo $i; ?>][space_id]" required>
            <option value="">Select Space</option>
            <?php foreach ($spaces as $s): ?>
            <option value="<?php echo $s->ID; ?>" <?php selected($ov['space_id'], $s->ID); ?>>
                <?php echo esc_html($s->post_title); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="sb-days-group">
            <?php foreach ([0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'] as $d => $label): ?>
            <label><input type="checkbox" name="sb_avail_overrides[<?php echo $i; ?>][days][]" value="<?php echo $d; ?>"
                    <?php echo in_array($d, $ov['days'] ?? []) ? 'checked' : ''; ?>><?php echo $label; ?></label>
            <?php endforeach; ?>
        </div>
        <div class="sb-time-group">
            <input type="time" name="sb_avail_overrides[<?php echo $i; ?>][start_time]"
                value="<?php echo esc_attr($ov['start_time'] ?? ''); ?>">
            <input type="time" name="sb_avail_overrides[<?php echo $i; ?>][end_time]"
                value="<?php echo esc_attr($ov['end_time'] ?? ''); ?>">
            <input type="checkbox" name="sb_avail_overrides[<?php echo $i; ?>][closed]" value="1"
                <?php checked($ov['closed'] ?? false); ?>>
        </div>
        <button type="button" class="button-link sb-remove-override" style="color:#d63638;">×</button>
    </div>
    <?php endforeach; ?>
</div>
<button type="button" id="sb-add-avail-override"
    class="button"><?php esc_html_e('Add Override', 'space-booking'); ?></button>

<script>
jQuery(document).ready(function($) {
    let overrideIndex = <?php echo count($avail_overrides ?? []); ?>;
    const dayLabels =
        <?php echo json_encode([0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat']); ?>;
    const spacesOptions = '<?php
        $opts = '';
        foreach ($spaces as $s)
            $opts .= '<option value="' . $s->ID . '">' . addslashes($s->post_title) . '</option>';
        echo $opts;
        ?>';

    $('#sb-add-avail-override').click(function() {
        let checkboxes = '';
        for (let d = 0; d < 7; d++) {
            checkboxes += '<label><input type="checkbox" name="sb_avail_overrides[' + overrideIndex +
                '][days][]" value="' + d + '">' + dayLabels[d] + '</label>';
        }
        const row = '<div class="sb-override-row">' +
            '<select name="sb_avail_overrides[' + overrideIndex +
            '][space_id]" required><option value="">Select Space</option>' + spacesOptions +
            '</select>' +
            '<div class="sb-days-group">' + checkboxes + '</div>' +
            '<div class="sb-time-group">' +
            '<input type="time" name="sb_avail_overrides[' + overrideIndex + '][start_time]">' +
            '<input type="time" name="sb_avail_overrides[' + overrideIndex + '][end_time]">' +
            '<input type="checkbox" name="sb_avail_overrides[' + overrideIndex +
            '][closed]" value="1"> Closed</div>' +
            '<button type="button" class="button-link sb-remove-override" style="color:#d63638;">×</button></div>';
        $('#sb-avail-overrides').append(row);
        overrideIndex++;
    });

    $(document).on('click', '.sb-remove-override', function() {
        $(this).closest('.sb-override-row').remove();
    });
});
</script>
<?php
    }

    public function save(int $post_id, \WP_Post $post): void
    {
        if (!isset($_POST['sb_extra_nonce']) ||
                !wp_verify_nonce(sanitize_key($_POST['sb_extra_nonce']), 'sb_extra_save') ||
                defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ||
                !current_user_can('edit_post', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_sb_extra_price', (float) ($_POST['sb_extra_price'] ?? 0));
        update_post_meta($post_id, '_sb_inventory', (int) ($_POST['sb_inventory'] ?? 1));

        // Package ownership - multiple packages allowed
        $package_ids = array_map('absint', (array) ($_POST['sb_package_ids'] ?? []));
        update_post_meta($post_id, '_sb_package_ids', $package_ids);

        // If assigned to packages, clear allowed spaces (cannot be both)
        if (!empty($package_ids)) {
            update_post_meta($post_id, '_sb_allowed_spaces', []);
        } else {
            // Only save allowed spaces if NOT assigned to any package
            $allowed = array_map('absint', (array) ($_POST['sb_allowed_spaces'] ?? []));
            update_post_meta($post_id, '_sb_allowed_spaces', $allowed);
        }

        // Space availability overrides
        $raw_overrides = $_POST['sb_avail_overrides'] ?? [];
        $clean_overrides = [];
        $space_day_slots = [];  // [space-day] => [[start_min, end_min]]

        foreach ($raw_overrides as $ov) {
            $space_id = absint($ov['space_id'] ?? 0);
            $days = array_map('absint', $ov['days'] ?? []);
            $start_time = sanitize_text_field($ov['start_time'] ?? '');
            $end_time = sanitize_text_field($ov['end_time'] ?? '');
            $closed = !empty($ov['closed']);

            if ($space_id < 1 || empty($days))
                continue;

            if ($closed) {
                $clean_overrides[] = [
                    'space_id' => $space_id,
                    'days' => $days,
                    'closed' => true
                ];
                continue;
            }

            if (empty($start_time) || empty($end_time))
                continue;

            $start_min = $this->time_to_minutes($start_time);
            $end_min = $this->time_to_minutes($end_time);
            if ($start_min >= $end_min) {
                add_settings_error('sb_extra', 'invalid_time', __('Invalid time range.', 'space-booking'), 'error');
                return;
            }

            // Overlap check
            foreach ($days as $day) {
                $key = $space_id . '-' . $day;
                if (!isset($space_day_slots[$key]))
                    $space_day_slots[$key] = [];
                foreach ($space_day_slots[$key] as $slot) {
                    if (!($end_min <= $slot[0] || $start_min >= $slot[1])) {
                        add_settings_error('sb_extra', 'overlap', __('Overlapping windows for space/day.', 'space-booking'), 'error');
                        return;
                    }
                }
                $space_day_slots[$key][] = [$start_min, $end_min];
            }

            $clean_overrides[] = [
                'space_id' => $space_id,
                'days' => $days,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'closed' => false
            ];
        }
        update_post_meta($post_id, '_sb_space_avail_overrides', $clean_overrides);
    }

    private function time_to_minutes(string $time): int
    {
        [$h, $m] = explode(':', $time);
        return (int) $h * 60 + (int) $m;
    }
}