<?php
declare(strict_types=1);

namespace SpaceBooking\CPT;

/**
 * Registers the `sb_space` Custom Post Type.
 */
final class SpaceCPT {

	public const POST_TYPE = 'sb_space';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	public function register_post_type(): void {
		register_post_type( self::POST_TYPE, [
			'label'               => __( 'Spaces', 'space-booking' ),
			'labels'              => $this->labels(),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => false,   // Shown under our custom admin menu
			'show_in_rest'        => true,
			'capability_type'     => 'post',
			'has_archive'         => false,
			'rewrite'             => false,
			'supports'            => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
			'menu_icon'           => 'dashicons-building',
		] );
	}

	private function labels(): array {
		return [
			'name'               => __( 'Spaces',             'space-booking' ),
			'singular_name'      => __( 'Space',              'space-booking' ),
			'add_new'            => __( 'Add New Space',       'space-booking' ),
			'add_new_item'       => __( 'Add New Space',       'space-booking' ),
			'edit_item'          => __( 'Edit Space',          'space-booking' ),
			'view_item'          => __( 'View Space',          'space-booking' ),
			'search_items'       => __( 'Search Spaces',       'space-booking' ),
			'not_found'          => __( 'No spaces found.',    'space-booking' ),
			'not_found_in_trash' => __( 'No spaces in trash.', 'space-booking' ),
		];
	}
}
