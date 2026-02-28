<?php
/**
 * REST endpoint for plugin settings.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Rest\RestModule;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Settings endpoint class.
 */
class SettingsEndpoint {

	/**
	 * Settings that accept only specific values.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private const ALLOWED_VALUES = array(
		'stripe_connection_status' => array( 'connected', 'disconnected', 'error' ),
	);

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
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Permission check — requires manage_options.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage settings.', 'mission' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * GET handler — returns all settings (excludes site token).
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		$all = $this->settings->get_all();

		unset( $all['stripe_site_token'] );

		return new WP_REST_Response( $all, 200 );
	}

	/**
	 * POST handler — partial update with validation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params();
		$defaults = $this->settings->get_defaults();
		$values   = array();

		foreach ( $params as $key => $value ) {
			// Only accept known setting keys.
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}

			// Never allow site token to be set via general settings endpoint.
			if ( 'stripe_site_token' === $key ) {
				continue;
			}

			$values[ $key ] = $this->sanitize_value( $key, $value );
		}

		$updated = $this->settings->update( $values );

		unset( $updated['stripe_site_token'] );

		return new WP_REST_Response( $updated, 200 );
	}

	/**
	 * Sanitize a setting value based on its key.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_value( string $key, mixed $value ): mixed {
		// Enforce allowed values.
		if ( isset( self::ALLOWED_VALUES[ $key ] ) ) {
			return in_array( $value, self::ALLOWED_VALUES[ $key ], true ) ? $value : '';
		}

		return match ( $key ) {
			'currency'           => strtoupper( sanitize_text_field( $value ) ),
			'email_from_name'    => sanitize_text_field( $value ),
			'email_from_address'     => sanitize_email( $value ),
			default                  => sanitize_text_field( $value ),
		};
	}
}
