<?php declare(strict_types=1);

namespace SpaceBooking\Controllers;

use SpaceBooking\Services\PricingService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST endpoint: POST /space-booking/v1/pricing
 * Live price calculation for frontend preview (no persistence).
 */
final class PricingController extends WP_REST_Controller
{
	protected $namespace = 'space-booking/v1';
	protected $rest_base = 'pricing';

	private PricingService $pricing;

	public function __construct()
	{
		$this->pricing = new PricingService();
	}

	public function register_routes(): void
	{
		register_rest_route($this->namespace, '/' . $this->rest_base, [
			[
				'methods' => [WP_REST_Server::CREATABLE, WP_REST_Server::READABLE],
				'callback' => [$this, 'calculate_pricing'],
				'permission_callback' => '__return_true',
				'args' => $this->get_args(),
			],
		]);
	}

	public function calculate_pricing(WP_REST_Request $request): WP_REST_Response
	{
		$item_ids = array_map('absint', (array) $request->get_param('item_ids'));
		$space_id = (int) $request->get_param('space_id') ?: $item_ids[0] ?? 0;
		$package_ids = array_map('absint', (array) ($request->get_param('package_ids') ?? []));
		if (empty($package_ids) && $request->get_param('package_id')) {
			$package_ids = [(int) $request->get_param('package_id')];
		}
		$date = (string) $request->get_param('date');
		$start_time = (string) $request->get_param('start_time');
		$end_time = (string) $request->get_param('end_time');
		$extras = (array) ($request->get_param('extras') ?? []);
		$package_question_answers = (array) ($request->get_param('package_question_answers') ?? []);

		error_log('SB_DEBUG_PRICING: FULL REQUEST PARAMS: ' . json_encode($request->get_params()));
		error_log('SB_DEBUG_PRICING: Parsed item_ids=' . json_encode($item_ids) . ", space_id=$space_id, package_ids=" . json_encode($package_ids) . ", date=$date, time=$start_time-$end_time");
		error_log('SB_DEBUG_PRICING: Extras count: ' . count($extras));

		// Guard: when NO package_id, space must exist and be valid
		if (empty($package_ids)) {
			$post = get_post($space_id);
			if (!$post) {
				error_log("SB_DEBUG_PRICING: No post for space $space_id");
				return new WP_REST_Response(['message' => 'Invalid space ID.'], 422);
			}
			error_log('SB_DEBUG_PRICING: space_id=' . $space_id . ' post_type=' . $post->post_type . ', status=' . $post->post_status);
			if ($post->post_type !== 'sb_space' || $post->post_status !== 'publish') {
				error_log("SB_DEBUG_PRICING: Invalid space $space_id type=" . $post->post_type . ' status=' . $post->post_status);
				return new WP_REST_Response(['message' => 'Invalid space.'], 422);
			}
		} else {
			// Package selected: verify all packages exist
			foreach ($package_ids as $package_id) {
				$post = get_post($package_id);
				if (!$post || $post->post_type !== 'sb_package' || $post->post_status !== 'publish') {
					error_log("SB_DEBUG_PRICING: Invalid package_id $package_id");
					return new WP_REST_Response(['message' => 'Invalid package.'], 422);
				}
			}
			// Use the first package's primary space for any rule lookups that still
			// rely on the deprecated leading space parameter.
			if (!$space_id && !empty($package_ids)) {
				$pkg_space_id = (int) get_post_meta($package_ids[0], '_sb_package_space_id', true);
				if ($pkg_space_id) {
					$space_id = $pkg_space_id;
				}
			}
		}

		$price = $this->pricing->calculate(
			$space_id,
			$date,
			$start_time,
			$end_time,
			$extras,
			$item_ids,
			$package_ids,
			$package_question_answers,
			$request->get_param('slot_id')
		);

		error_log('SB_DEBUG_PRICING: Calculated total: ' . $price['total_price'] . ', breakdown count: ' . count($price['breakdown']));

		return new WP_REST_Response($price, 200);
	}

	private function get_args(): array
	{
		return [
			'space_id' => ['required' => false, 'sanitize_callback' => 'absint'],
			'item_ids' => ['type' => 'array', 'sanitize_callback' => function ($input) {
				return array_map('absint', (array) $input);
			}],
			'package_id' => ['required' => false, 'sanitize_callback' => 'absint'],
			'package_ids' => ['required' => false, 'type' => 'array', 'sanitize_callback' => function ($input) {
				return array_map('absint', (array) $input);
			}],
			'date' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
			'start_time' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
			'end_time' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
			'extras' => ['required' => false, 'default' => []],
			'package_question_answers' => ['required' => false, 'default' => []],
		];
	}
}
