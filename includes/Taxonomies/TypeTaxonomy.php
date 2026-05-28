<?php declare(strict_types=1);

namespace SpaceBooking\Taxonomies;

/**
 * Registers type taxonomies for Spaces, Packages, and Extras.
 * This allows categorizing items (e.g., Meeting Room, Event Hall for spaces).
 */
final class TypeTaxonomy
{
    public function register(): void
    {
        add_action('init', [$this, 'register_taxonomies'], 20);
    }

    public function register_taxonomies(): void
    {
        $this->register_space_type();
        $this->register_package_type();
        $this->register_extra_type();
    }

    private function register_space_type(): void
    {
        register_taxonomy('sb_space_type', 'sb_space', [
            'label' => __('Space Types', 'space-booking'),
            'labels' => [
                'name' => __('Space Types', 'space-booking'),
                'singular_name' => __('Space Type', 'space-booking'),
                'add_new_item' => __('Add New Space Type', 'space-booking'),
                'new_item_name' => __('New Space Type', 'space-booking'),
            ],
            'hierarchical' => false,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'rewrite' => false,
            'capabilities' => [
                'manage_terms' => 'edit_posts',
                'edit_terms' => 'edit_posts',
                'delete_terms' => 'edit_posts',
                'assign_terms' => 'edit_posts',
            ],
        ]);

        // Register built-in terms by default
        $this->add_space_type_terms();
    }

    private function register_package_type(): void
    {
        register_taxonomy('sb_package_type', 'sb_package', [
            'label' => __('Package Types', 'space-booking'),
            'labels' => [
                'name' => __('Package Types', 'space-booking'),
                'singular_name' => __('Package Type', 'space-booking'),
                'add_new_item' => __('Add New Package Type', 'space-booking'),
                'new_item_name' => __('New Package Type', 'space-booking'),
            ],
            'hierarchical' => false,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'rewrite' => false,
            'capabilities' => [
                'manage_terms' => 'edit_posts',
                'edit_terms' => 'edit_posts',
                'delete_terms' => 'edit_posts',
                'assign_terms' => 'edit_posts',
            ],
        ]);

        $this->add_package_type_terms();
    }

    private function register_extra_type(): void
    {
        register_taxonomy('sb_extra_type', 'sb_extra', [
            'label' => __('Extra Types', 'space-booking'),
            'labels' => [
                'name' => __('Extra Types', 'space-booking'),
                'singular_name' => __('Extra Type', 'space-booking'),
                'add_new_item' => __('Add New Extra Type', 'space-booking'),
                'new_item_name' => __('New Extra Type', 'space-booking'),
            ],
            'hierarchical' => false,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'rewrite' => false,
            'capabilities' => [
                'manage_terms' => 'edit_posts',
                'edit_terms' => 'edit_posts',
                'delete_terms' => 'edit_posts',
                'assign_terms' => 'edit_posts',
            ],
        ]);

        $this->add_extra_type_terms();
    }

    private function add_space_type_terms(): void
    {
        $terms = [
            'meeting_room' => __('Meeting Room', 'space-booking'),
            'event_hall' => __('Event Hall', 'space-booking'),
            'studio' => __('Studio', 'space-booking'),
            'office' => __('Office', 'space-booking'),
            'lounge' => __('Lounge', 'space-booking'),
            'outdoor' => __('Outdoor', 'space-booking'),
            'other' => __('Other', 'space-booking'),
        ];

        $this->insert_terms('sb_space_type', $terms);
    }

    private function add_package_type_terms(): void
    {
        $terms = [
            'half_day' => __('Half Day', 'space-booking'),
            'full_day' => __('Full Day', 'space-booking'),
            'overnight' => __('Overnight', 'space-booking'),
            'hourly' => __('Hourly', 'space-booking'),
            'membership' => __('Membership', 'space-booking'),
            'other' => __('Other', 'space-booking'),
        ];

        $this->insert_terms('sb_package_type', $terms);
    }

    private function add_extra_type_terms(): void
    {
        $terms = [
            'equipment' => __('Equipment', 'space-booking'),
            'service' => __('Service', 'space-booking'),
            'addon' => __('Add-on', 'space-booking'),
            'food' => __('Food & Beverage', 'space-booking'),
            'other' => __('Other', 'space-booking'),
        ];

        $this->insert_terms('sb_extra_type', $terms);
    }

    private function insert_terms(string $taxonomy, array $terms): void
    {
        foreach ($terms as $slug => $name) {
            if (!term_exists($slug, $taxonomy)) {
                wp_insert_term($name, $taxonomy, [
                    'slug' => $slug,
                ]);
            }
        }
    }
}
