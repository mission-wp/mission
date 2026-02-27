<?php
/**
 * REST endpoint for plugin settings.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Rest\RestModule;
use Mission\Settings\SettingsService;
use Mission\Settings\StripeConnectionVerifier;
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
	 * @param SettingsService          $settings Settings service.
	 * @param StripeConnectionVerifier $stripe   Stripe connection verifier.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly StripeConnectionVerifier $stripe,
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
	 * GET handler — returns all settings with secret key masked.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		$all = $this->settings->get_all();

		$all['stripe_secret_key'] = $this->mask_key( $all['stripe_secret_key'] );

		return new WP_REST_Response( $all, 200 );
	}

	/**
	 * POST handler — partial update with validation and Stripe verification.
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

			// Skip masked secret key (user didn't change it).
			if ( 'stripe_secret_key' === $key && $this->is_masked( $value ) ) {
				continue;
			}

			$values[ $key ] = $this->sanitize_value( $key, $value );
		}

		// Verify Stripe connection if secret key changed.
		$stripe_key_changed = isset( $values['stripe_secret_key'] ) && '' !== $values['stripe_secret_key'];

		if ( $stripe_key_changed ) {
			$result = $this->stripe->verify( $values['stripe_secret_key'] );

			if ( $result['connected'] ) {
				$values['stripe_account_id']        = $result['account_id'];
				$values['stripe_connection_status'] = 'connected';
			} else {
				$values['stripe_account_id']        = '';
				$values['stripe_connection_status'] = 'error';
			}
		}

		// If secret key was explicitly cleared, reset connection.
		if ( array_key_exists( 'stripe_secret_key', $values ) && '' === $values['stripe_secret_key'] ) {
			$values['stripe_account_id']        = '';
			$values['stripe_connection_status'] = 'disconnected';
		}

		$updated = $this->settings->update( $values );

		$updated['stripe_secret_key'] = $this->mask_key( $updated['stripe_secret_key'] );

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
			'currency'               => strtoupper( sanitize_text_field( $value ) ),
			'tip_enabled'            => (bool) $value,
			'tip_default_percentage' => max( 0, min( 100, (int) $value ) ),
			'email_from_name'        => sanitize_text_field( $value ),
			'email_from_address'     => sanitize_email( $value ),
			'stripe_publishable_key',
			'stripe_secret_key'      => sanitize_text_field( $value ),
			default                  => sanitize_text_field( $value ),
		};
	}

	/**
	 * Mask a secret key, showing only the last 4 characters.
	 *
	 * @param string $key The key to mask.
	 * @return string Masked key or empty string.
	 */
	private function mask_key( string $key ): string {
		if ( strlen( $key ) <= 4 ) {
			return '' === $key ? '' : '••••';
		}

		return str_repeat( '•', strlen( $key ) - 4 ) . substr( $key, -4 );
	}

	/**
	 * Check if a value looks like a masked key.
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 */
	private function is_masked( mixed $value ): bool {
		return is_string( $value ) && str_contains( $value, '•' );
	}
}
