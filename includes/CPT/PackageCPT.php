<?php
declare(strict_types=1);

namespace SpaceBooking\CPT;

/**
 * Registers the `sb_package` Custom Post Type (Bundled deals).
 */
final class PackageCPT {

	public const POST_TYPE = 'sb_package';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	public function register_post_type(): void {
		register_post_type( self::POST_TYPE, [
			'label'           => __( 'Packages', 'space-booking' ),
			'labels'          => $this->labels(),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'show_in_rest'    => true,
			'capability_type' => 'post',
			'has_archive'     => false,
			'rewrite'         => false,
			'supports'        => [ 'title', 'editor', 'thumbnail' ],
			'menu_icon'       => 'dashicons-gifts',
		] );
	}

	private function labels(): array {
		return [
			'name'               => __( 'Packages',             'space-booking' ),
			'singular_name'      => __( 'Package',              'space-booking' ),
			'add_new'            => __( 'Add New Package',       'space-booking' ),
			'add_new_item'       => __( 'Add New Package',       'space-booking' ),
			'edit_item'          => __( 'Edit Package',          'space-booking' ),
			'search_items'       => __( 'Search Packages',       'space-booking' ),
			'not_found'          => __( 'No packages found.',    'space-booking' ),
			'not_found_in_trash' => __( 'No packages in trash.', 'space-booking' ),
		];
	}
}
