<?php declare(strict_types=1);

namespace SpaceBooking\Admin;

/**
 * Meta box for the sb_package CPT: flat price, linked space, duration, extras.
 */
final class PackageMetaBox
{
    private const ALLOWED_ANSWER_TYPES = ['text', 'textarea', 'number', 'radio', 'checkbox', 'select'];

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add']);
        add_action('save_post_sb_package', [$this, 'save'], 10, 2);
    }

    public function add(): void
    {
        add_meta_box(
            'sb-package-settings',
            __('Package Settings', 'space-booking'),
            [$this, 'render'],
            'sb_package',
            'normal',
            'high'
        );
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field('sb_package_save', 'sb_package_nonce');

        $price = get_post_meta($post->ID, '_sb_package_price', true);
        $space_id = get_post_meta($post->ID, '_sb_package_space_id', true);
        $duration = get_post_meta($post->ID, '_sb_package_duration', true);
        $extra_ids = get_post_meta($post->ID, '_sb_package_extra_ids', true);
        $theme_meta_fields = get_post_meta($post->ID, '_sb_package_theme_meta_fields', true);
        if (!is_array($extra_ids))
            $extra_ids = [];
        if (!is_array($theme_meta_fields)) {
            $theme_meta_fields = [];
        }

        $spaces = get_posts(['post_type' => 'sb_space', 'posts_per_page' => -1]);
        $extras = get_posts(['post_type' => 'sb_extra', 'posts_per_page' => -1]);
?>
<table class="form-table" role="presentation">
    <tr>
        <?php $symbol = \SpaceBooking\Services\CurrencyService::get_symbol(); ?>
        <th><label
                for="sb_package_price"><?php printf(esc_html__('Flat Price (%s)', 'space-booking'), $symbol); ?></label>
        </th>
        <td><input type="number" id="sb_package_price" name="sb_package_price" step="0.01" min="0"
                value="<?php echo esc_attr($price); ?>" class="regular-text"></td>

    </tr>
    <tr>
        <th><label for="sb_package_space_id"><?php esc_html_e('Included Space', 'space-booking'); ?></label></th>
        <td>
            <select id="sb_package_space_id" name="sb_package_space_id" style="width:100%;max-width:300px">
                <option value="">— Select a space —</option>
                <?php foreach ($spaces as $space): ?>
                <option value="<?php echo esc_attr($space->ID); ?>"
                    <?php selected($space_id, $space->ID); ?>>
                    <?php echo esc_html($space->post_title); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <p class="description">The space included in this package.</p>
        </td>
    </tr>
    <tr>
        <th><label for="sb_package_duration"><?php esc_html_e('Duration (hours)', 'space-booking'); ?></label></th>
        <td><input type="number" id="sb_package_duration" name="sb_package_duration" min="1" max="24"
                value="<?php echo esc_attr($duration); ?>" class="small-text"></td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Included Extras', 'space-booking'); ?></label></th>
        <td>
            <?php foreach ($extras as $extra): ?>
            <label style="display:block;margin-bottom:4px">
                <input type="checkbox" name="sb_package_extra_ids[]" value="<?php echo esc_attr($extra->ID); ?>"
                    <?php checked(in_array($extra->ID, array_map('intval', $extra_ids), true)); ?>>
                <?php echo esc_html($extra->post_title); ?>
                <small style="color:#666">
                    (<?php echo \SpaceBooking\Services\CurrencyService::format((float) get_post_meta($extra->ID, '_sb_extra_price', true)); ?>)

                </small>
            </label>
            <?php endforeach; ?>
        </td>
    </tr>
    <tr>
        <th>
            <label><?php esc_html_e('Package Questions', 'space-booking'); ?></label>
        </th>
        <td>
            <p class="description" style="margin-bottom:12px;">
                <?php esc_html_e('Define dynamic questions for this package. Supports text, textarea, number, radio, checkbox, and select.', 'space-booking'); ?>
            </p>
            <div id="sb-package-theme-meta-builder" data-next-index="<?php echo esc_attr((string) count($theme_meta_fields)); ?>">
                <?php foreach ($theme_meta_fields as $index => $field): ?>
                    <?php
                    $field = is_array($field) ? $field : [];
                    $label = sanitize_text_field((string) ($field['label'] ?? ''));
                    $key = sanitize_key((string) ($field['key'] ?? ''));
                    $type = sanitize_text_field((string) ($field['type'] ?? 'text'));
                    $required = !empty($field['required']);
                    $allow_others = !empty($field['allow_others']);
                    $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
                    ?>
                    <div class="sb-package-field-row" style="border:1px solid #dcdcde;border-radius:6px;padding:12px;margin-bottom:12px;background:#fff;">
                        <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                            <p style="margin:0;flex:2;min-width:220px;">
                                <label><strong><?php esc_html_e('Question Label', 'space-booking'); ?></strong></label><br>
                                <input type="text" name="sb_package_theme_meta_fields[<?php echo esc_attr((string) $index); ?>][label]" value="<?php echo esc_attr($label); ?>" class="regular-text" placeholder="e.g. Theme preference">
                            </p>
                            <p style="margin:0;flex:1;min-width:180px;">
                                <label><strong><?php esc_html_e('Field Key', 'space-booking'); ?></strong></label><br>
                                <input type="text" name="sb_package_theme_meta_fields[<?php echo esc_attr((string) $index); ?>][key]" value="<?php echo esc_attr($key); ?>" class="regular-text" placeholder="e.g. theme_preference">
                            </p>
                            <p style="margin:0;min-width:170px;">
                                <label><strong><?php esc_html_e('Answer Type', 'space-booking'); ?></strong></label><br>
                                <select class="sb-answer-type" name="sb_package_theme_meta_fields[<?php echo esc_attr((string) $index); ?>][type]">
                                    <?php foreach (self::ALLOWED_ANSWER_TYPES as $answer_type): ?>
                                        <option value="<?php echo esc_attr($answer_type); ?>" <?php selected($type, $answer_type); ?>>
                                            <?php echo esc_html(ucfirst($answer_type)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                        </div>
                        <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-top:10px;">
                            <label>
                                <input type="checkbox" name="sb_package_theme_meta_fields[<?php echo esc_attr((string) $index); ?>][required]" value="1" <?php checked($required); ?>>
                                <?php esc_html_e('Required', 'space-booking'); ?>
                            </label>
                            <label class="sb-allow-others-wrap" style="<?php echo in_array($type, ['radio', 'checkbox', 'select'], true) ? '' : 'display:none;'; ?>">
                                <input type="checkbox" name="sb_package_theme_meta_fields[<?php echo esc_attr((string) $index); ?>][allow_others]" value="1" <?php checked($allow_others); ?>>
                                <?php esc_html_e('Allow "Others" option', 'space-booking'); ?>
                            </label>
                            <button type="button" class="button-link-delete sb-remove-theme-field"><?php esc_html_e('Remove', 'space-booking'); ?></button>
                        </div>
                        <p class="sb-options-wrap" style="margin-top:10px;<?php echo in_array($type, ['radio', 'checkbox', 'select'], true) ? '' : 'display:none;'; ?>">
                            <label><strong><?php esc_html_e('Options (one per line)', 'space-booking'); ?></strong></label><br>
                            <textarea name="sb_package_theme_meta_fields[<?php echo esc_attr((string) $index); ?>][options]" rows="4" class="large-text"><?php echo esc_textarea(implode("\n", array_map('sanitize_text_field', $options))); ?></textarea>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
            <p>
                <button type="button" class="button" id="sb-add-theme-meta-field"><?php esc_html_e('Add Question', 'space-booking'); ?></button>
            </p>

            <script>
            (function() {
                const builder = document.getElementById('sb-package-theme-meta-builder');
                const addBtn = document.getElementById('sb-add-theme-meta-field');
                if (!builder || !addBtn) return;

                let nextIndex = parseInt(builder.dataset.nextIndex || '0', 10);
                const choiceTypes = ['radio', 'checkbox', 'select'];

                const rowTemplate = (index) => `
                    <div class="sb-package-field-row" style="border:1px solid #dcdcde;border-radius:6px;padding:12px;margin-bottom:12px;background:#fff;">
                        <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                            <p style="margin:0;flex:2;min-width:220px;">
                                <label><strong>Question Label</strong></label><br>
                                <input type="text" name="sb_package_theme_meta_fields[${index}][label]" class="regular-text" placeholder="e.g. Theme preference">
                            </p>
                            <p style="margin:0;flex:1;min-width:180px;">
                                <label><strong>Field Key</strong></label><br>
                                <input type="text" name="sb_package_theme_meta_fields[${index}][key]" class="regular-text" placeholder="e.g. theme_preference">
                            </p>
                            <p style="margin:0;min-width:170px;">
                                <label><strong>Answer Type</strong></label><br>
                                <select class="sb-answer-type" name="sb_package_theme_meta_fields[${index}][type]">
                                    <option value="text">Text</option>
                                    <option value="textarea">Textarea</option>
                                    <option value="number">Number</option>
                                    <option value="radio">Radio</option>
                                    <option value="checkbox">Checkbox</option>
                                    <option value="select">Select</option>
                                </select>
                            </p>
                        </div>
                        <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-top:10px;">
                            <label>
                                <input type="checkbox" name="sb_package_theme_meta_fields[${index}][required]" value="1">
                                Required
                            </label>
                            <label class="sb-allow-others-wrap" style="display:none;">
                                <input type="checkbox" name="sb_package_theme_meta_fields[${index}][allow_others]" value="1">
                                Allow "Others" option
                            </label>
                            <button type="button" class="button-link-delete sb-remove-theme-field">Remove</button>
                        </div>
                        <p class="sb-options-wrap" style="margin-top:10px;display:none;">
                            <label><strong>Options (one per line)</strong></label><br>
                            <textarea name="sb_package_theme_meta_fields[${index}][options]" rows="4" class="large-text"></textarea>
                        </p>
                    </div>
                `;

                const syncVisibility = (row) => {
                    const typeEl = row.querySelector('.sb-answer-type');
                    const optionsWrap = row.querySelector('.sb-options-wrap');
                    const allowOthersWrap = row.querySelector('.sb-allow-others-wrap');
                    if (!typeEl || !optionsWrap || !allowOthersWrap) return;
                    const showChoice = choiceTypes.includes(typeEl.value);
                    optionsWrap.style.display = showChoice ? '' : 'none';
                    allowOthersWrap.style.display = showChoice ? '' : 'none';
                    if (!showChoice) {
                        const checkbox = allowOthersWrap.querySelector('input[type="checkbox"]');
                        if (checkbox) checkbox.checked = false;
                    }
                };

                addBtn.addEventListener('click', () => {
                    builder.insertAdjacentHTML('beforeend', rowTemplate(nextIndex++));
                });

                builder.addEventListener('click', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement)) return;
                    if (target.classList.contains('sb-remove-theme-field')) {
                        const row = target.closest('.sb-package-field-row');
                        if (row) row.remove();
                    }
                });

                builder.addEventListener('change', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement)) return;
                    const row = target.closest('.sb-package-field-row');
                    if (!row) return;
                    if (target.classList.contains('sb-answer-type')) {
                        syncVisibility(row);
                    }
                });

                builder.querySelectorAll('.sb-package-field-row').forEach((row) => syncVisibility(row));
            })();
            </script>
        </td>
    </tr>
</table>
<?php
    }

    public function save(int $post_id, \WP_Post $post): void
    {
        if (!isset($_POST['sb_package_nonce']) ||
                !wp_verify_nonce(sanitize_key($_POST['sb_package_nonce']), 'sb_package_save') ||
                defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ||
                !current_user_can('edit_post', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_sb_package_price', (float) ($_POST['sb_package_price'] ?? 0));
        $space_id = absint($_POST['sb_package_space_id'] ?? 0);
        update_post_meta($post_id, '_sb_package_space_id', $space_id);
        update_post_meta($post_id, '_sb_package_duration', (int) ($_POST['sb_package_duration'] ?? 0));

        $extra_ids = array_map('absint', (array) ($_POST['sb_package_extra_ids'] ?? []));
        update_post_meta($post_id, '_sb_package_extra_ids', $extra_ids);
        $raw_theme_fields = isset($_POST['sb_package_theme_meta_fields']) && is_array($_POST['sb_package_theme_meta_fields'])
            ? $_POST['sb_package_theme_meta_fields']
            : [];
        $theme_fields = $this->sanitize_theme_meta_fields($raw_theme_fields);
        update_post_meta($post_id, '_sb_package_theme_meta_fields', $theme_fields);

        // Set _sb_package_ids on extras that are now part of this package
        // and clear it from extras that were removed
        $all_extras = get_posts([
            'post_type' => 'sb_extra',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($all_extras as $extra_id) {
            $current_package_ids = get_post_meta($extra_id, '_sb_package_ids', true);
            if (!is_array($current_package_ids)) {
                $current_package_ids = [];
            }

            if (in_array($extra_id, $extra_ids)) {
                // This extra is included in the package - add package_id if not already there
                if (!in_array($post_id, $current_package_ids)) {
                    $current_package_ids[] = $post_id;
                    update_post_meta($extra_id, '_sb_package_ids', $current_package_ids);
                }
            } else {
                // This extra was previously owned by this package - remove it
                if (in_array($post_id, $current_package_ids)) {
                    $current_package_ids = array_values(array_diff($current_package_ids, [$post_id]));
                    update_post_meta($extra_id, '_sb_package_ids', $current_package_ids);
                }
            }
        }
    }

    private function sanitize_theme_meta_fields(array $raw_fields): array
    {
        $sanitized = [];
        $used_keys = [];

        foreach ($raw_fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = sanitize_text_field((string) ($field['label'] ?? ''));
            $key = sanitize_key((string) ($field['key'] ?? ''));
            $type = sanitize_text_field((string) ($field['type'] ?? 'text'));
            $required = !empty($field['required']);
            $allow_others = !empty($field['allow_others']);

            if ($label === '' || $key === '' || !in_array($type, self::ALLOWED_ANSWER_TYPES, true)) {
                continue;
            }

            if (in_array($key, $used_keys, true)) {
                continue;
            }
            $used_keys[] = $key;

            $options = [];
            if (in_array($type, ['radio', 'checkbox', 'select'], true)) {
                $raw_options = explode("\n", (string) ($field['options'] ?? ''));
                foreach ($raw_options as $option) {
                    $option = sanitize_text_field(trim($option));
                    if ($option !== '') {
                        $options[] = $option;
                    }
                }
                if (empty($options)) {
                    continue;
                }
            } else {
                $allow_others = false;
            }

            $normalized = [
                'label' => $label,
                'key' => $key,
                'type' => $type,
                'required' => $required,
                'allow_others' => $allow_others,
            ];

            if (!empty($options)) {
                $normalized['options'] = $options;
            }

            $sanitized[] = $normalized;
        }

        return array_values($sanitized);
    }
}
