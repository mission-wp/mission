<?php
/**
 * REST endpoint for resolved donation form settings.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Blocks\DonationFormSettings;
use MissionDP\Rest\RestModule;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * DonationFormSettings endpoint class.
 */
class DonationFormSettingsEndpoint {

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/donation-form-settings',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
	}

	/**
	 * Check permission.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Return resolved donation form settings (plugin defaults + currency).
	 *
	 * @param WP_REST_Request $request Request object (unused, required by REST API).
	 *
	 * @return WP_REST_Response
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP REST API callback signature.
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( DonationFormSettings::resolve( [] ), 200 );
	}
}
