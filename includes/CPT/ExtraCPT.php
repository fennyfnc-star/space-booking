<?php
declare(strict_types=1);

namespace SpaceBooking\CPT;

/**
 * Registers the `sb_extra` Custom Post Type (Shared Assets / Add-ons).
 */
final class ExtraCPT {

	public const POST_TYPE = 'sb_extra';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	public function register_post_type(): void {
		register_post_type( self::POST_TYPE, [
			'label'           => __( 'Extras', 'space-booking' ),
			'labels'          => $this->labels(),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'show_in_rest'    => true,
			'capability_type' => 'post',
			'has_archive'     => false,
			'rewrite'         => false,
			'supports'        => [ 'title', 'editor', 'thumbnail' ],
			'menu_icon'       => 'dashicons-portfolio',
		] );
	}

	private function labels(): array {
		return [
			'name'               => __( 'Extras',              'space-booking' ),
			'singular_name'      => __( 'Extra',               'space-booking' ),
			'add_new'            => __( 'Add New Extra',        'space-booking' ),
			'add_new_item'       => __( 'Add New Extra',        'space-booking' ),
			'edit_item'          => __( 'Edit Extra',           'space-booking' ),
			'search_items'       => __( 'Search Extras',        'space-booking' ),
			'not_found'          => __( 'No extras found.',     'space-booking' ),
			'not_found_in_trash' => __( 'No extras in trash.',  'space-booking' ),
		];
	}
}
