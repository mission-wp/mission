<?php
/**
 * REST module - handles custom REST API endpoints.
 *
 * @package Mission
 */

namespace Mission\Rest;

use Mission\Rest\Endpoints\SettingsEndpoint;
use Mission\Settings\SettingsService;
use Mission\Settings\StripeConnectionVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * REST module class.
 */
class RestModule {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'mission/v1';

	/**
	 * Initialize the REST module.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$settings_service = new SettingsService();
		$stripe_verifier  = new StripeConnectionVerifier();

		( new SettingsEndpoint( $settings_service, $stripe_verifier ) )->register();
	}
}
