<?php
/**
 * REST endpoint for public payment configuration.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Rest\RestModule;
use MissionDP\Settings\SettingsService;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * PaymentConfig endpoint class.
 */
class PaymentConfigEndpoint {

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service.
	 */
	public function __construct(
		private SettingsService $settings,
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/donations/payment-config',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle' ],
				// Public by design: returns only non-sensitive config the donation form needs.
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Return public payment configuration.
	 *
	 * @return WP_REST_Response
	 */
	public function handle(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'connected_account_id' => $this->settings->get( 'stripe_account_id', '' ),
			],
			200
		);
	}
}
