<?php declare(strict_types=1);

namespace SpaceBooking\Admin;

/**
 * Meta box for the sb_package CPT: flat price, linked space, duration, extras.
 */
final class PackageMetaBox
{
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
        $space_ids = get_post_meta($post->ID, '_sb_package_space_ids', true);
        if (!is_array($space_ids)) {
            // For backwards compatibility, fall back to old single field
            $single_space_id = get_post_meta($post->ID, '_sb_package_space_id', true);
            $space_ids = $single_space_id ? [(int)$single_space_id] : [];
        }
        $duration = get_post_meta($post->ID, '_sb_package_duration', true);
        $extra_ids = get_post_meta($post->ID, '_sb_package_extra_ids', true);
        if (!is_array($extra_ids))
            $extra_ids = [];

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
        <th><label><?php esc_html_e('Included Spaces (Multi)', 'space-booking'); ?></label></th>
        <td>
            <select id="sb_package_space_ids" name="sb_package_space_ids[]" multiple size="6"
                style="width:100%;min-height:100px">
                <?php foreach ($spaces as $space): ?>
                <option value="<?php echo esc_attr($space->ID); ?>"
                    <?php selected(in_array($space->ID, array_map('intval', $space_ids))); ?>>
                    <?php echo esc_html($space->post_title); ?> (ID: <?php echo $space->ID; ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <p class="description">Hold Ctrl/Cmd to select multiple spaces this package includes. Used for conflict
                resolution.</p>
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
        $space_ids = array_map('absint', (array) ($_POST['sb_package_space_ids'] ?? []));
        update_post_meta($post_id, '_sb_package_space_ids', $space_ids);
        // For backwards compatibility - set single space ID if only one space selected
        update_post_meta($post_id, '_sb_package_space_id', !empty($space_ids) ? $space_ids[0] : 0);
        update_post_meta($post_id, '_sb_package_duration', (int) ($_POST['sb_package_duration'] ?? 0));

        $extra_ids = array_map('absint', (array) ($_POST['sb_package_extra_ids'] ?? []));
        update_post_meta($post_id, '_sb_package_extra_ids', $extra_ids);
    }
}
