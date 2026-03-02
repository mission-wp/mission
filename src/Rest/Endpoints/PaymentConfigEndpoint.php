<?php
/**
 * REST endpoint for public payment configuration.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Rest\RestModule;
use Mission\Settings\SettingsService;
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
		private readonly SettingsService $settings,
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
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return public payment configuration.
	 *
	 * @return WP_REST_Response
	 */
	public function handle(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'connected_account_id' => $this->settings->get( 'stripe_account_id', '' ),
			),
			200
		);
	}
}
