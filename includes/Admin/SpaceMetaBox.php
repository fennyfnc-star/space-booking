<?php declare(strict_types=1);

namespace SpaceBooking\Admin;

/**
 * Meta box for the sb_space CPT: hourly rate, hours, day overrides, capacity.
 */
final class SpaceMetaBox
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add']);
        add_action('save_post_sb_space', [$this, 'save'], 10, 2);
    }

    public function add(): void
    {
        add_meta_box(
            'sb-space-settings',
            __('Space Settings', 'space-booking'),
            [$this, 'render'],
            'sb_space',
            'normal',
            'high'
        );
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field('sb_space_save', 'sb_space_nonce');

        $rate = get_post_meta($post->ID, '_sb_hourly_rate', true);
        $min_dur = get_post_meta($post->ID, '_sb_min_duration', true) ?: 1;
        $max_dur = get_post_meta($post->ID, '_sb_max_duration', true) ?: 8;
        $default_dur = get_post_meta($post->ID, '_sb_default_duration', true) ?: '';
        $capacity = get_post_meta($post->ID, '_sb_capacity', true);
        $overrides = get_post_meta($post->ID, '_sb_day_overrides', true);

        // Get taxonomy terms for space type
        $space_type_terms = get_terms([
            'taxonomy' => 'sb_space_type',
            'hide_empty' => false,
        ]);
        $current_space_type = wp_get_post_terms($post->ID, 'sb_space_type');
        $current_type_slug = !empty($current_space_type) ? $current_space_type[0]->slug : '';

        if (!is_array($overrides)) {
            $overrides = [];
        }

        $days = [
            0 => __('Sunday', 'space-booking'),
            1 => __('Monday', 'space-booking'),
            2 => __('Tuesday', 'space-booking'),
            3 => __('Wednesday', 'space-booking'),
            4 => __('Thursday', 'space-booking'),
            5 => __('Friday', 'space-booking'),
            6 => __('Saturday', 'space-booking'),
        ];
        ?>
<style>
.sb-meta-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.sb-meta-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
}

.sb-meta-field input {
    width: 100%;
}

.sb-deps-select {
    width: 100%;
    height: 120px;
    min-height: 120px;
}



.sb-day-row {
    display: grid;
    grid-template-columns: 100px 1fr 1fr 80px;
    gap: 8px;
    align-items: center;
    padding: 6px 0;
    border-bottom: 1px solid #eee;
}

.sb-override-row {
    display: grid;
    grid-template-columns: 1fr auto 30px;
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
    white-space: nowrap;
    margin: 0;
}

.sb-time-price-group {
    display: flex;
    gap: 12px;
    justify-content: space-between;
    flex: 1;
}

.sb-time-price-group input[type="time"] {
    flex: 0 0 120px;
}

.sb-time-price-group input[type="number"] {
    flex: 1;
    min-width: 80px;
}
</style>
<div class="sb-meta-grid">
    <div class="sb-meta-field">
        <?php $symbol = \SpaceBooking\Services\CurrencyService::get_symbol(); ?>
        <label for="sb_hourly_rate"><?php printf(esc_html__('Hourly Rate (%s)', 'space-booking'), $symbol); ?></label>
        <input type="number" id="sb_hourly_rate" name="sb_hourly_rate" step="0.01" min="0"
            value="<?php echo esc_attr($rate); ?>">

    </div>
    <div class="sb-meta-field">
        <label for="sb_space_type"><?php esc_html_e('Space Type', 'space-booking'); ?></label>
        <select id="sb_space_type" name="sb_space_type">
            <option value=""><?php esc_html_e('-- Select Type --', 'space-booking'); ?></option>
            <?php foreach ($space_type_terms as $term): ?>
            <option value="<?php echo esc_attr($term->slug); ?>" <?php selected($current_type_slug, $term->slug); ?>>
                <?php echo esc_html($term->name); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="sb-meta-field">
        <label for="sb_default_duration"><?php esc_html_e('Default Duration (hours)', 'space-booking'); ?></label>
        <input type="number" id="sb_default_duration" name="sb_default_duration" min="1" max="24"
            value="<?php echo esc_attr($default_dur); ?>">
        <p class="description"><?php esc_html_e('Suggested default booking duration.', 'space-booking'); ?></p>
    </div>
    <div class="sb-meta-field">
        <label for="sb_capacity"><?php esc_html_e('Capacity (guests)', 'space-booking'); ?></label>
        <input type="number" id="sb_capacity" name="sb_capacity" min="0" value="<?php echo esc_attr($capacity); ?>">
    </div>
    <div class="sb-meta-field">
        <label for="sb_min_duration"><?php esc_html_e('Min Duration (hours)', 'space-booking'); ?></label>
        <input type="number" id="sb_min_duration" name="sb_min_duration" min="1" max="24"
            value="<?php echo esc_attr($min_dur); ?>">
    </div>
    <div class="sb-meta-field">
        <label for="sb_max_duration"><?php esc_html_e('Max Duration (hours)', 'space-booking'); ?></label>
        <input type="number" id="sb_max_duration" name="sb_max_duration" min="1" max="24"
            value="<?php echo esc_attr($max_dur); ?>">
    </div>
    <div class="sb-meta-field">
        <label for="sb_buffer_pre"><?php esc_html_e('Pre-Event Buffer (minutes)', 'space-booking'); ?></label>
        <input type="number" id="sb_buffer_pre" name="sb_buffer_pre" min="0"
            value="<?php echo esc_attr(get_post_meta($post->ID, '_sb_buffer_pre_minutes', true) ?: ''); ?>">
        <p class="description"><?php esc_html_e('Overrides global. 0 = use global.', 'space-booking'); ?></p>
    </div>
    <div class="sb-meta-field">
        <label for="sb_buffer_post"><?php esc_html_e('Post-Event Buffer (minutes)', 'space-booking'); ?></label>
        <input type="number" id="sb_buffer_post" name="sb_buffer_post" min="0"
            value="<?php echo esc_attr(get_post_meta($post->ID, '_sb_buffer_post_minutes', true) ?: ''); ?>">
        <p class="description"><?php esc_html_e('Overrides global. 0 = use global.', 'space-booking'); ?></p>
    </div>

    <!-- NEW: Resource Dependencies -->
    <div class="sb-meta-field">
        <label
            for="sb_resource_dependencies"><?php esc_html_e('Required Resources (Dependencies)', 'space-booking'); ?></label>
        <select id="sb_resource_dependencies" name="sb_resource_dependencies[]" multiple class="sb-deps-select">
            <?php
            $spaces = get_posts([
                'post_type' => 'sb_space',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'exclude' => [$post->ID],
                'orderby' => 'title',
                'order' => 'ASC'
            ]);
            $current_deps = get_post_meta($post->ID, '_sb_resource_dependencies', true) ?: [];
            foreach ($spaces as $space):
                ?>
            <option value="<?php echo esc_attr($space->ID); ?>" <?php selected(in_array($space->ID, $current_deps)); ?>>
                <?php echo esc_html($space->post_title); ?> (ID: <?php echo $space->ID; ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Hold Ctrl/Cmd to select multiple. These are child resources this space requires (e.g. Package → Rooms). No self/circular deps allowed.', 'space-booking'); ?>
        </p>
    </div>
</div>

<h4><?php esc_html_e('Day-specific Hour Overrides', 'space-booking'); ?></h4>

<p class="description">
    <?php esc_html_e('Leave blank to use global hours. Check "Closed" to mark as unavailable.', 'space-booking'); ?></p>

<div class="sb-day-row" style="font-weight:600">
    <span><?php esc_html_e('Day', 'space-booking'); ?></span>
    <span><?php esc_html_e('Open', 'space-booking'); ?></span>
    <span><?php esc_html_e('Close', 'space-booking'); ?></span>
    <span><?php esc_html_e('Closed', 'space-booking'); ?></span>
</div>

<?php
        foreach ($days as $num => $name):
            $ov = $overrides[$num] ?? [];
            $open = $ov['open'] ?? '';
            $close = $ov['close'] ?? '';
            $closed = !empty($ov['closed']);
            ?>
<div class="sb-day-row">
    <span><?php echo esc_html($name); ?></span>
    <input type="time" name="sb_day_overrides[<?php echo esc_attr($num); ?>][open]"
        value="<?php echo esc_attr($open); ?>">
    <input type="time" name="sb_day_overrides[<?php echo esc_attr($num); ?>][close]"
        value="<?php echo esc_attr($close); ?>">
    <input type="checkbox" name="sb_day_overrides[<?php echo esc_attr($num); ?>][closed]" value="1"
        <?php checked($closed); ?>>
</div>
<?php endforeach; ?>

<div>
    <h4><?php esc_html_e('Price Overrides (specific dates/times)', 'space-booking'); ?></h4>
    <p class="description">
        <?php esc_html_e('Set custom hourly rates for specific date/time ranges. Overlaps split pro-rata.', 'space-booking'); ?>
    </p>

    <div id="sb-price-overrides">
        <?php
        $price_overrides = get_post_meta($post->ID, '_sb_price_overrides', true);
        if (!is_array($price_overrides))
            $price_overrides = [];
        foreach ($price_overrides as $i => $ov):
            ?>

        <div class="sb-override-row">
            <div class="sb-days-group">
                <?php foreach ([0 => __('Sun'), 1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat')] as $day_num => $day_name): ?>
                <label><input type="checkbox" name="sb_price_overrides[<?php echo $i; ?>][days][]"
                        value="<?php echo $day_num; ?>"
                        <?php checked(in_array($day_num, $ov['days'] ?? [])); ?>><?php echo $day_name; ?></label>
                <?php endforeach; ?>
            </div>
            <div class="sb-time-price-group">
                <input type="time" name="sb_price_overrides[<?php echo $i; ?>][start_time]"
                    value="<?php echo esc_attr($ov['start_time'] ?? ''); ?>" required>
                <input type="time" name="sb_price_overrides[<?php echo $i; ?>][end_time]"
                    value="<?php echo esc_attr($ov['end_time'] ?? ''); ?>" required>
                <input type="number" name="sb_price_overrides[<?php echo $i; ?>][hourly_rate]" step="0.01" min="0"
                    value="<?php echo esc_attr($ov['hourly_rate'] ?? ''); ?>" required>
            </div>
            <button type="button" class="button-link sb-remove-override" style="color:#d63638;">×</button>
        </div>

        <?php endforeach; ?>
    </div>
    <button type="button" id="sb-add-override"
        class="button"><?php esc_html_e('Add Price Override', 'space-booking'); ?></button>

    <!-- FIXED SLOTS REPEATER -->
    <h4><?php esc_html_e('Fixed Time Slots', 'space-booking'); ?></h4>
    <p class="description">
        <?php esc_html_e('Define exact time slots for this space. Blank buffers use space/global defaults. Capacity is per slot.', 'space-booking'); ?>
    </p>

    <div id="sb-fixed-slots-container">
        <div class="sb-slot-header"
            style="display: grid; grid-template-columns: 80px 100px 100px 80px 120px 80px auto; gap: 12px; align-items: center; padding: 8px 0; font-weight: 600; border-bottom: 2px solid #ddd; margin-bottom: 8px;">
            <span>Pre Buf</span>
            <span>Start</span>
            <span>End</span>
            <span>Post Buf</span>
            <span>Price</span>
            <span>Capacity</span>
            <span></span>
        </div>
        <div id="sb-fixed-slots">
            <?php
            $fixed_slots = get_post_meta($post->ID, '_sb_fixed_slots', true);
            if (!is_array($fixed_slots))
                $fixed_slots = [];
            foreach ($fixed_slots as $i => $slot):
                ?>
            <div class="sb-slot-row"
                style="display: grid; grid-template-columns: 80px 100px 100px 80px 120px 80px auto; gap: 12px; align-items: center; padding: 8px 0; border-bottom: 1px solid #ddd;">
                <input type="number" name="sb_fixed_slots[<?php echo $i; ?>][pre_buffer]" min="0" placeholder="0"
                    value="<?php echo esc_attr($slot['pre_buffer'] ?? ''); ?>" style="width:70px;">
                <input type="time" name="sb_fixed_slots[<?php echo $i; ?>][start_time]" required
                    value="<?php echo esc_attr($slot['start_time'] ?? ''); ?>">
                <input type="time" name="sb_fixed_slots[<?php echo $i; ?>][end_time]" required
                    value="<?php echo esc_attr($slot['end_time'] ?? ''); ?>">
                <input type="number" name="sb_fixed_slots[<?php echo $i; ?>][post_buffer]" min="0" placeholder="0"
                    value="<?php echo esc_attr($slot['post_buffer'] ?? ''); ?>" style="width:70px;">
                <input type="number" name="sb_fixed_slots[<?php echo $i; ?>][override_price]" step="0.01" min="0"
                    placeholder="" value="<?php echo esc_attr($slot['override_price'] ?? ''); ?>" style="width:100px;">
                <input type="number" name="sb_fixed_slots[<?php echo $i; ?>][capacity]" min="1"
                    value="<?php echo esc_attr($slot['capacity'] ?? 1); ?>" style="width:70px;">
                <button type="button" class="button-link sb-remove-slot" style="color:#d63638;width:10px;">×</button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <button type="button" id="sb-add-fixed-slot"
        class="button"><?php esc_html_e('Add Fixed Slot', 'space-booking'); ?></button>

    <!-- SPECIAL DATE OVERRIDES REPEATER -->
    <h4><?php esc_html_e('Special Date Overrides', 'space-booking'); ?></h4>
    <p class="description">
        <?php esc_html_e('Override slots for specific dates (holidays, events). Higher priority than fixed slots.', 'space-booking'); ?>
    </p>

    <div id="sb-date-overrides-container">
        <div class="sb-date-header"
            style="display: grid; grid-template-columns: 140px 120px 40px auto; gap: 12px; align-items: center; padding: 8px 0; font-weight: 600; border-bottom: 2px solid #ddd; margin-bottom: 8px;">
            <span>Date</span>
            <span>Status</span>
            <span></span>
            <span>Slots</span>
        </div>
        <div id="sb-date-overrides">
            <?php
            $date_overrides = get_post_meta($post->ID, '_sb_date_overrides', true);
            if (!is_array($date_overrides))
                $date_overrides = [];
            foreach ($date_overrides as $target_date => $override):
                ?>
            <div class="sb-date-row" data-date="<?php echo esc_attr($target_date); ?>">
                <input type="date" name="sb_date_overrides[<?php echo esc_attr($target_date); ?>][date]"
                    value="<?php echo esc_attr($target_date); ?>" required style="width:130px;">
                <select name="sb_date_overrides[<?php echo esc_attr($target_date); ?>][status]" style="width:110px;">
                    <option value="custom" <?php selected($override['status'] ?? 'custom', 'custom'); ?>>Custom Slots
                    </option>
                    <option value="closed" <?php selected($override['status'] ?? '', 'closed'); ?>>Closed</option>
                </select>
                <button type="button" class="button-link sb-copy-default"
                    data-date="<?php echo esc_attr($target_date); ?>" style="font-size:12px;">Copy Default</button>
                <button type="button" class="button-link sb-remove-date" style="color:#d63638;">×</button>
                <div class="sb-date-slots" style="margin-left: 20px; margin-top: 8px;">
                    <?php if (isset($override['slots']) && is_array($override['slots'])): ?>
                    <?php foreach ($override['slots'] as $i => $slot): ?>
                    <div class="sb-date-slot-row sb-slot-row" data-slot-i="<?php echo $i; ?>"
                        style="display: grid; grid-template-columns: 80px 100px 100px 80px 120px 80px auto; gap: 12px; align-items: center; padding: 8px 0; border-bottom: 1px solid #ddd;">
                        <input type="number"
                            name="sb_date_overrides[<?php echo esc_attr($target_date); ?>][slots][<?php echo $i; ?>][pre_buffer]"
                            min="0" placeholder="0" value="<?php echo esc_attr($slot['pre_buffer'] ?? ''); ?>"
                            style="width:70px;">
                        <input type="time"
                            name="sb_date_overrides[<?php echo esc_attr($target_date); ?>][slots][<?php echo $i; ?>][start_time]"
                            required value="<?php echo esc_attr($slot['start_time'] ?? ''); ?>">
                        <input type="time"
                            name="sb_date_overrides[<?php echo esc_attr($target_date); ?>][slots][<?php echo $i; ?>][end_time]"
                            required value="<?php echo esc_attr($slot['end_time'] ?? ''); ?>">
                        <input type="number"
                            name="sb_date_overrides[<?php echo esc_attr($target_date); ?>][slots][<?php echo $i; ?>][post_buffer]"
                            min="0" placeholder="0" value="<?php echo esc_attr($slot['post_buffer'] ?? ''); ?>"
                            style="width:70px;">
                        <input type="number"
                            name="sb_date_overrides[<?php echo esc_attr($target_date); ?>][slots][<?php echo $i; ?>][override_price]"
                            step="0.01" min="0" placeholder=""
                            value="<?php echo esc_attr($slot['override_price'] ?? ''); ?>" style="width:100px;">
                        <input type="number"
                            name="sb_date_overrides[<?php echo esc_attr($target_date); ?>][slots][<?php echo $i; ?>][capacity]"
                            min="1" value="<?php echo esc_attr($slot['capacity'] ?? 1); ?>" style="width:70px;">
                        <button type="button" class="button-link sb-remove-date-slot" style="color:#d63638;">×</button>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <button type="button" id="sb-add-date-override"
        class="button"><?php esc_html_e('Add Date Override', 'space-booking'); ?></button>
</div>

<script>
jQuery(document).ready(function($) {
    let overrideIndex = <?php echo count($price_overrides); ?>;
    const dayLabels = <?php
        $day_short = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
        echo json_encode($day_short);
        ?>;
    $('#sb-add-override').click(function() {

        let checkboxes = '';
        for (let d = 0; d < 7; d++) {
            checkboxes +=
                '<label><input type="checkbox" name="sb_price_overrides[' +
                overrideIndex + '][days][]" value="' + d + '">' + dayLabels[d] + '</label>';
        }
        const row =
            '<div class="sb-override-row">' +
            '<div class="sb-days-group">' + checkboxes + '</div>' +
            '<div class="sb-time-price-group">' +
            '<input type="time" name="sb_price_overrides[' + overrideIndex +
            '][start_time]" required>' +
            '<input type="time" name="sb_price_overrides[' + overrideIndex + '][end_time]" required>' +
            '<input type="number" name="sb_price_overrides[' + overrideIndex +
            '][hourly_rate]" step="0.01" min="0" required>' +
            '</div>' +
            '<button type="button" class="button-link sb-remove-override" style="color:#d63638;">×</button>' +
            '</div>';

        $('#sb-price-overrides').append(row);
        overrideIndex++;
    });
    $(document).on('click', '.sb-remove-override', function() {
        $(this).closest('.sb-override-row').remove();
    });

    // Fixed slots JS
    let fixedSlotIndex = <?php echo count($fixed_slots); ?>;
    let dateOverrideIndex = 0;
    const fixedSlotsTemplate = $('#sb-fixed-slots .sb-slot-row').length ? $(
        '#sb-fixed-slots .sb-slot-row:first').prop('outerHTML').replace(/sb_fixed_slots/g,
        'sb_date_overrides[PLACEHOLDER_DATE][slots]').replace(/\[\d+\]/g, '[PLACEHOLDER_I]') : '';

    $('#sb-add-date-override').click(function() {
        const dateStr = `override_${dateOverrideIndex}`;
        const row = `
            <div class="sb-date-row" data-date="${dateStr}">
                <input type="date" name="sb_date_overrides[${dateStr}][date]" required style="width:130px;">
                <select name="sb_date_overrides[${dateStr}][status]" style="width:110px;">
                    <option value="custom">Custom Slots</option>
                    <option value="closed">Closed</option>
                </select>
                <button type="button" class="button-link sb-copy-default" data-date="${dateStr}" style="font-size:12px;">Copy Default</button>
                <button type="button" class="button-link sb-remove-date" style="color:#d63638;">×</button>
                <div class="sb-date-slots" style="margin-left: 20px; margin-top: 8px;"></div>
            </div>`;
        $('#sb-date-overrides').append(row);
        dateOverrideIndex++;
    });

    $(document).on('click', '.sb-remove-date', function() {
        $(this).closest('.sb-date-row').remove();
    });

    $(document).on('click', '.sb-copy-default', function() {
        const dateKey = $(this).data('date');
        const slotsContainer = $(`[data-date="${dateKey}"] .sb-date-slots`);
        $('#sb-fixed-slots .sb-slot-row').clone().each(function() {
            const cloned = $(this).clone();
            cloned.find('input[name]').each(function() {
                let name = $(this).attr('name');
                name = name.replace('sb_fixed_slots',
                    `sb_date_overrides[${dateKey}][slots]`);
                $(this).attr('name', name);
            });
            cloned.removeClass('sb-slot-row').addClass('sb-date-slot-row sb-slot-row');
            cloned.find('.sb-remove-slot').addClass('sb-remove-date-slot').removeClass(
                'sb-remove-slot');
            slotsContainer.append(cloned);
        });
    });

    $(document).on('click', '.sb-remove-date-slot', function() {
        $(this).closest('.sb-date-slot-row').remove();
    });
    $('#sb-add-fixed-slot').click(function() {
        const row = `
            <div class="sb-slot-row" style="display: grid; grid-template-columns: 80px 100px 100px 80px 120px 80px auto; gap: 12px; align-items: center; padding: 8px 0; border-bottom: 1px solid #ddd;">
                <input type="number" name="sb_fixed_slots[${fixedSlotIndex}][pre_buffer]" min="0" placeholder="0" style="width:70px;">
                <input type="time" name="sb_fixed_slots[${fixedSlotIndex}][start_time]" required>
                <input type="time" name="sb_fixed_slots[${fixedSlotIndex}][end_time]" required>
                <input type="number" name="sb_fixed_slots[${fixedSlotIndex}][post_buffer]" min="0" placeholder="0" style="width:70px;">
                <input type="number" name="sb_fixed_slots[${fixedSlotIndex}][override_price]" step="0.01" min="0" placeholder="" style="width:100px;">
                <input type="number" name="sb_fixed_slots[${fixedSlotIndex}][capacity]" min="1" value="1" style="width:70px;">
                <button type="button" class="button-link sb-remove-slot" style="color:#d63638;">×</button>
            </div>`;
        $('#sb-fixed-slots').append(row);
        fixedSlotIndex++;
    });
    $(document).on('click', '.sb-remove-slot', function() {
        $(this).closest('.sb-slot-row').remove();
    });
});
</script>
<?php
    }

    public function validate_dependencies(int $post_id, array $raw_deps): array
    {
        $errors = [];

        // Self check
        $deps = array_map('intval', $raw_deps);
        $deps = array_filter($deps);
        if (in_array($post_id, $deps)) {
            $errors[] = __('Space cannot depend on itself.', 'space-booking');
            return $errors;
        }

        // Cycle check DFS
        $space_map = [];
        $all_spaces = get_posts([
            'post_type' => 'sb_space',
            'posts_per_page' => -1,
            'post_status' => ['publish', 'draft'],
        ]);
        foreach ($all_spaces as $s)
            $space_map[$s->ID] = $s;

        $visited = [];
        $rec_stack = [];
        foreach ($deps as $child_id) {
            if (isset($space_map[$child_id]) && $this->dfs_cycle($post_id, $child_id, $space_map, $visited, $rec_stack)) {
                $errors[] = sprintf(__('Circular dependency via Space #%d.', 'space-booking'), $child_id);
                break;
            }
        }

        return $errors;
    }

    private function dfs_cycle(int $source_id, int $target_id, array $space_map, array &$visited, array &$rec_stack): bool
    {
        if (!isset($space_map[$target_id]))
            return false;

        $deps = get_post_meta($target_id, '_sb_resource_dependencies', true) ?: [];
        $deps = array_map('intval', (array) $deps);

        if (!isset($visited[$target_id])) {
            $visited[$target_id] = 1;  // visiting
            $rec_stack[$target_id] = true;

            foreach ($deps as $next_id) {
                if ($next_id == $source_id)
                    return true;  // direct back edge
                if (isset($space_map[$next_id])) {
                    if (!isset($visited[$next_id])) {
                        if ($this->dfs_cycle($target_id, $next_id, $space_map, $visited, $rec_stack)) {
                            return true;
                        }
                    } elseif ($rec_stack[$next_id]) {
                        return true;
                    }
                }
            }

            $rec_stack[$target_id] = false;
            $visited[$target_id] = 2;  // visited
        }

        return false;
    }

    public function save(int $post_id, \WP_Post $post): void
    {
        if (
            !isset($_POST['sb_space_nonce']) ||
            !wp_verify_nonce(sanitize_key($_POST['sb_space_nonce']), 'sb_space_save') ||
            defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ||
            !current_user_can('edit_post', $post_id)
        ) {
            return;
        }

        update_post_meta($post_id, '_sb_hourly_rate', (float) ($_POST['sb_hourly_rate'] ?? 0));
        update_post_meta($post_id, '_sb_min_duration', (int) ($_POST['sb_min_duration'] ?? 1));
        update_post_meta($post_id, '_sb_max_duration', (int) ($_POST['sb_max_duration'] ?? 8));
        update_post_meta($post_id, '_sb_default_duration', (int) ($_POST['sb_default_duration'] ?? 0));
        update_post_meta($post_id, '_sb_capacity', (int) ($_POST['sb_capacity'] ?? 0));
        update_post_meta($post_id, '_sb_buffer_pre_minutes', (int) ($_POST['sb_buffer_pre'] ?? 0));
        update_post_meta($post_id, '_sb_buffer_post_minutes', (int) ($_POST['sb_buffer_post'] ?? 0));

        // Save space type taxonomy
        $space_type = sanitize_text_field($_POST['sb_space_type'] ?? '');
        if (!empty($space_type)) {
            wp_set_post_terms($post_id, $space_type, 'sb_space_type');
        } else {
            wp_set_post_terms($post_id, [], 'sb_space_type');
        }

        // NEW Resource Dependencies validation/save
        $raw_deps = $_POST['sb_resource_dependencies'] ?? [];
        $dep_errors = $this->validate_dependencies($post_id, $raw_deps);
        if (!empty($dep_errors)) {
            foreach ($dep_errors as $err) {
                add_settings_error('sb_space', 'deps_invalid', $err, 'error');
            }
            return;
        }
        $deps = array_unique(array_map('intval', $raw_deps));
        $deps = array_filter($deps);
        update_post_meta($post_id, '_sb_resource_dependencies', $deps);

        // Day overrides
        $raw_overrides = $_POST['sb_day_overrides'] ?? [];
        $clean = [];

        for ($i = 0; $i <= 6; $i++) {
            $ov = $raw_overrides[$i] ?? [];
            if (!empty($ov['closed'])) {
                $clean[$i] = ['closed' => true];
            } elseif (!empty($ov['open']) || !empty($ov['close'])) {
                $clean[$i] = [
                    'open' => sanitize_text_field($ov['open'] ?? ''),
                    'close' => sanitize_text_field($ov['close'] ?? ''),
                ];
            }
        }

        update_post_meta($post_id, '_sb_day_overrides', $clean);

        // Price overrides - validate no overlaps per day
        $raw_price_ov = $_POST['sb_price_overrides'] ?? [];
        $clean_price_ov = [];
        $day_slots = [];  // [day_num => [[start_min, end_min]]]

        foreach ($raw_price_ov as $ov) {
            $days = array_map('intval', $ov['days'] ?? []);
            $start_time = sanitize_text_field($ov['start_time'] ?? '');
            $end_time = sanitize_text_field($ov['end_time'] ?? '');
            $hourly_rate = (float) ($ov['hourly_rate'] ?? 0);

            if (empty($days) || empty($start_time) || empty($end_time) || $hourly_rate <= 0)
                continue;

            $start_min = (int) substr($start_time, 0, 2) * 60 + (int) substr($start_time, 3, 2);
            $end_min = (int) substr($end_time, 0, 2) * 60 + (int) substr($end_time, 3, 2);

            if ($start_min >= $end_min) {
                add_settings_error('sb_space', 'invalid_time', 'Start time must be before end time.', 'error');
                return;
            }

            // Check overlaps per day
            foreach ($days as $day) {
                if (!isset($day_slots[$day]))
                    $day_slots[$day] = [];
                foreach ($day_slots[$day] as $existing) {
                    if (!($end_min <= $existing[0] || $start_min >= $existing[1])) {
                        $days_labels = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
                        add_settings_error('sb_space', 'overlapping_schedule', 'Overlapping price schedules on ' . $days_labels[$day] . '. Fix before saving.', 'error');
                        return;
                    }
                }
                $day_slots[$day][] = [$start_min, $end_min];
            }

            $clean_price_ov[] = [
                'days' => $days,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'hourly_rate' => $hourly_rate
            ];
        }
        update_post_meta($post_id, '_sb_price_overrides', $clean_price_ov);

        // FIXED SLOTS - validate no overlaps, add capacity
        $raw_fixed_slots = $_POST['sb_fixed_slots'] ?? [];
        $clean_fixed_slots = [];
        $slot_footprints = [];  // for overlap check

        foreach ($raw_fixed_slots as $raw_slot) {
            $pre_buf = !empty($raw_slot['pre_buffer']) ? (int) $raw_slot['pre_buffer'] : null;
            $post_buf = !empty($raw_slot['post_buffer']) ? (int) $raw_slot['post_buffer'] : null;
            $start_time = sanitize_text_field($raw_slot['start_time'] ?? '');
            $end_time = sanitize_text_field($raw_slot['end_time'] ?? '');
            $override_price = !empty($raw_slot['override_price']) ? (float) $raw_slot['override_price'] : null;
            $capacity = max(1, (int) ($raw_slot['capacity'] ?? 1));

            if (empty($start_time) || empty($end_time))
                continue;

            $start_min = self::time_to_minutes($start_time);
            $end_min = self::time_to_minutes($end_time);

            if ($start_min >= $end_min) {
                add_settings_error('sb_space', 'invalid_slot_time', 'Slot start must be before end.', 'error');
                return;
            }

            // Check footprint overlap with existing slots
            foreach ($clean_fixed_slots as $existing) {
                $exist_start = self::time_to_minutes($existing['start_time']);
                $exist_end = self::time_to_minutes($existing['end_time']);
                if ($start_min < $exist_end && $end_min > $exist_start) {
                    add_settings_error('sb_space', 'slot_overlap', 'Fixed slots cannot overlap.', 'error');
                    return;
                }
            }

            $clean_fixed_slots[] = [
                'slot_id' => 'slot_' . uniqid(),
                'start_time' => $start_time,
                'end_time' => $end_time,
                'pre_buffer' => $pre_buf,
                'post_buffer' => $post_buf,
                'override_price' => $override_price,
                'capacity' => $capacity
            ];
        }

        update_post_meta($post_id, '_sb_fixed_slots', $clean_fixed_slots);

        // DATE OVERRIDES - validate dates, slots, warn on existing bookings
        $raw_date_overrides = $_POST['sb_date_overrides'] ?? [];
        $overrides = [];
        $repo = new \SpaceBooking\Services\BookingRepository();

        foreach ($raw_date_overrides as $submitted_key => $data) {
            $date_input = sanitize_text_field($data['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_input)) {
                add_settings_error('sb_space', 'invalid_date', "Invalid date format for {$date_input}. Use YYYY-MM-DD.", 'error');
                continue;
            }

            $status = sanitize_text_field($data['status'] ?? 'custom');
            $target_date = $date_input;

            // Check existing bookings
            $booked_intervals = $repo->get_confirmed_intervals($post_id, $target_date);
            $booking_count = count($booked_intervals);
            if ($booking_count > 0) {
                $warning = "Date {$target_date} has {$booking_count} existing booking(s). ";
                if ($status === 'closed') {
                    $warning .= 'These will not be cancelled automatically.';
                } else {
                    $warning .= 'Custom slots may conflict with them.';
                }
                add_settings_error('sb_space', 'existing_bookings', $warning, 'warning');
            }

            if ($status === 'closed') {
                $overrides[$target_date] = ['status' => 'closed', 'slots' => []];
                continue;
            }

            // Validate custom slots like fixed slots
            $raw_slots = $data['slots'] ?? [];
            $clean_date_slots = [];
            $slot_footprints = [];

            foreach ($raw_slots as $raw_slot) {
                $pre_buf = !empty($raw_slot['pre_buffer']) ? (int) $raw_slot['pre_buffer'] : null;
                $post_buf = !empty($raw_slot['post_buffer']) ? (int) $raw_slot['post_buffer'] : null;
                $start_time = sanitize_text_field($raw_slot['start_time'] ?? '');
                $end_time = sanitize_text_field($raw_slot['end_time'] ?? '');
                $override_price = !empty($raw_slot['override_price']) ? (float) $raw_slot['override_price'] : null;
                $capacity = max(1, (int) ($raw_slot['capacity'] ?? 1));

                if (empty($start_time) || empty($end_time))
                    continue;

                $start_min = self::time_to_minutes($start_time);
                $end_min = self::time_to_minutes($end_time);

                if ($start_min >= $end_min) {
                    add_settings_error('sb_space', 'invalid_date_slot_time', 'Date slot start must be before end.', 'error');
                    continue 2;  // skip this date
                }

                // Overlap within this date's slots
                foreach ($clean_date_slots as $existing) {
                    $exist_start = self::time_to_minutes($existing['start_time']);
                    $exist_end = self::time_to_minutes($existing['end_time']);
                    if ($start_min < $exist_end && $end_min > $exist_start) {
                        add_settings_error('sb_space', 'date_slot_overlap', 'Date override slots cannot overlap.', 'error');
                        continue 2;
                    }
                }

                $clean_date_slots[] = [
                    'slot_id' => 'date_' . uniqid(),  // unique prefix
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'pre_buffer' => $pre_buf,
                    'post_buffer' => $post_buf,
                    'override_price' => $override_price,
                    'capacity' => $capacity
                ];
            }

            $overrides[$target_date] = ['status' => 'custom', 'slots' => $clean_date_slots];
        }

        update_post_meta($post_id, '_sb_date_overrides', $overrides);
    }

    private static function time_to_minutes(string $time): int
    {
        [$h, $m] = explode(':', $time);
        return (int) $h * 60 + (int) $m;
    }
}
