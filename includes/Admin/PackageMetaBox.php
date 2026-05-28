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
        $space_id = get_post_meta($post->ID, '_sb_package_space_id', true);
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
}
