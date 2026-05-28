<?php declare(strict_types=1);

namespace SpaceBooking\Controllers;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /space-booking/v1/spaces
 * GET /space-booking/v1/spaces/{id}
 * GET /space-booking/v1/packages
 */
final class SpaceController extends WP_REST_Controller
{
	protected $namespace = 'space-booking/v1';
	protected $rest_base = 'spaces';

	public function register_routes(): void
	{
		register_rest_route($this->namespace, '/' . $this->rest_base, [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'get_spaces'],
				'permission_callback' => '__return_true',
				'args' => [],
			],
		]);

		register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'get_space'],
				'permission_callback' => '__return_true',
				'args' => [
					'id' => [
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'absint',
					],
				],
			],
		]);

		register_rest_route($this->namespace, '/packages', [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'get_packages'],
				'permission_callback' => '__return_true',
			],
		]);
	}

	// ── Handlers ─────────────────────────────────────────────────────────────

	public function get_spaces(WP_REST_Request $request): WP_REST_Response
	{
		$posts = get_posts([
			'post_type' => 'sb_space',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		]);

		$spaces = array_map([$this, 'format_space'], $posts);

		return rest_ensure_response($spaces);
	}

	public function get_space(WP_REST_Request $request): WP_REST_Response
	{
		$id = $request->get_param('id');
		$post = get_post($id);

		if (!$post || $post->post_type !== 'sb_space' || $post->post_status !== 'publish') {
			return new WP_REST_Response(['message' => 'Space not found.'], 404);
		}

		return rest_ensure_response($this->format_space($post));
	}

	public function get_packages(WP_REST_Request $request): WP_REST_Response
	{
		$posts = get_posts([
			'post_type' => 'sb_package',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		]);

		$packages = array_map([$this, 'format_package'], $posts);

		return rest_ensure_response($packages);
	}

	// ── Formatters ───────────────────────────────────────────────────────────

	private function format_space(\WP_Post $post): array
	{
		$meta = get_post_meta($post->ID);
		$day_overrides = get_post_meta($post->ID, '_sb_day_overrides', true);

		return [
			'id' => $post->ID,
			'title' => $post->post_title,
			'description' => wp_kses_post($post->post_content),
			'excerpt' => $post->post_excerpt,
			'thumbnail' => get_the_post_thumbnail_url($post->ID, 'large') ?: null,
			'hourly_rate' => (float) ($meta['_sb_hourly_rate'][0] ?? 0),
			'min_duration' => (int) ($meta['_sb_min_duration'][0] ?? 1),
			'max_duration' => (int) ($meta['_sb_max_duration'][0] ?? 8),
			'capacity' => (int) ($meta['_sb_capacity'][0] ?? 0),
			'day_overrides' => is_array($day_overrides) ? $day_overrides : [],
			'price_overrides' => $this->get_price_overrides($post->ID),
			'gallery' => $this->get_gallery($post->ID),
		];
	}

	private function format_package(\WP_Post $post): array
	{
		$space_id = (int) get_post_meta($post->ID, '_sb_package_space_id', true);
		$extra_ids = get_post_meta($post->ID, '_sb_package_extra_ids', true);

		return [
			'id' => $post->ID,
			'title' => $post->post_title,
			'description' => wp_kses_post($post->post_content),
			'thumbnail' => get_the_post_thumbnail_url($post->ID, 'large') ?: null,
			'price' => (float) get_post_meta($post->ID, '_sb_package_price', true),
			'duration' => (int) get_post_meta($post->ID, '_sb_package_duration', true),
			'space_id' => $space_id,
			'space_name' => $space_id ? get_the_title($space_id) : null,
			'extra_ids' => is_array($extra_ids) ? $extra_ids : [],
			'space_ids' => $space_id ? [$space_id] : [],
		];
	}

	private function get_gallery(int $post_id): array
	{
		$ids = get_post_meta($post_id, '_sb_gallery_ids', true);

		if (!is_array($ids) || empty($ids)) {
			return [];
		}

		$gallery = [];
		foreach ($ids as $attachment_id) {
			$src = wp_get_attachment_image_url((int) $attachment_id, 'large');
			if ($src) {
				$gallery[] = $src;
			}
		}

		return $gallery;
	}

	private function get_price_overrides(int $post_id): ?array
	{
		$overrides = get_post_meta($post_id, '_sb_price_overrides', true);
		if (!is_array($overrides)) {
			return null;
		}
		return array_map(function ($ov) {
			return [
				'days' => $ov['days'] ?? [],
				'start_time' => $ov['start_time'] ?? '',
				'end_time' => $ov['end_time'] ?? '',
				'hourly_rate' => (float) ($ov['hourly_rate'] ?? 0),
			];
		}, $overrides);
	}
}