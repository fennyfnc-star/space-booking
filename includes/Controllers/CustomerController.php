<?php declare(strict_types=1);

namespace SpaceBooking\Controllers;

use SpaceBooking\Services\CustomerFieldsService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CustomerController extends WP_REST_Controller
{
	protected $namespace = 'space-booking/v1';
	protected $rest_base = 'customer/fields';

	public function register_routes(): void
	{
		register_rest_route($this->namespace, '/' . $this->rest_base, [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'get_customer_fields'],
				'permission_callback' => '__return_true',
			],
		]);
	}

	public function get_customer_fields(WP_REST_Request $request): WP_REST_Response
	{
		$service = new CustomerFieldsService();
		$fields = $service->get_fields();

		return rest_ensure_response([
			'fields' => $fields
		]);
	}
}
?>