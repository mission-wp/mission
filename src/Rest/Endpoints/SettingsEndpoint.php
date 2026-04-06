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
	private const ALLOWED_VALUES = [
		'stripe_connection_status' => [ 'connected', 'disconnected', 'error' ],
	];

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
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
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
				[ 'status' => 403 ]
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

		$this->inject_page_keys( $all );

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
		$values   = [];

		// Handle the dashboard page ID separately — it's a standalone option.
		if ( array_key_exists( 'donor_portal_page_id', $params ) ) {
			$page_id = absint( $params['donor_portal_page_id'] );

			if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
				update_option( 'mission_dashboard_page_id', $page_id );
			}
		}

		// Remove virtual/read-only keys before processing.
		unset( $params['donor_portal_page_id'], $params['donor_portal_page_title'] );

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

		$this->inject_page_keys( $updated );

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
			'currency'            => strtoupper( sanitize_text_field( $value ) ),
			'primary_color'       => sanitize_hex_color( $value ) ?: '#2fa36b',
			'show_powered_by',
			'test_mode',
			'onboarding_completed',
			'donor_portal_enabled',
			'delete_data_on_uninstall' => (bool) $value,
			'stripe_fee_percent'  => min( 5.0, max( 0.5, round( (float) $value, 2 ) ) ),
			'stripe_fee_fixed'    => min( 100, max( 0, (int) $value ) ),
			'email_from_name'     => sanitize_text_field( $value ),
			'email_from_address'  => sanitize_email( $value ),
			'email_reply_to'      => sanitize_email( $value ),
			'portal_features'     => $this->sanitize_portal_features( $value ),
			'emails'              => $this->sanitize_emails( $value ),
			default               => sanitize_text_field( $value ),
		};
	}

	/**
	 * Sanitize the nested emails setting.
	 *
	 * @param mixed $value Raw emails array.
	 * @return array<string, array{enabled: bool, subject: string}>
	 */
	private function sanitize_emails( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$defaults  = $this->settings->get_defaults();
		$known     = array_keys( $defaults['emails'] ?? [] );
		$sanitized = [];

		foreach ( $value as $type => $config ) {
			if ( ! in_array( $type, $known, true ) || ! is_array( $config ) ) {
				continue;
			}

			$sanitized[ $type ] = [];

			if ( array_key_exists( 'enabled', $config ) ) {
				$sanitized[ $type ]['enabled'] = (bool) $config['enabled'];
			}

			if ( array_key_exists( 'subject', $config ) ) {
				$sanitized[ $type ]['subject'] = sanitize_text_field( $config['subject'] );
			}

			if ( array_key_exists( 'body', $config ) ) {
				$sanitized[ $type ]['body'] = wp_kses_post( $config['body'] );
			}

			if ( array_key_exists( 'recipients', $config ) && is_array( $config['recipients'] ) ) {
				$sanitized[ $type ]['recipients'] = array_values(
					array_filter(
						array_map( 'sanitize_email', $config['recipients'] ),
						fn( string $email ) => '' !== $email
					)
				);
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize the nested portal features setting.
	 *
	 * @param mixed $value Raw portal features array.
	 * @return array<string, bool>
	 */
	private function sanitize_portal_features( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$defaults  = $this->settings->get_defaults();
		$known     = array_keys( $defaults['portal_features'] ?? [] );
		$sanitized = [];

		foreach ( $value as $feature => $enabled ) {
			if ( in_array( $feature, $known, true ) ) {
				$sanitized[ $feature ] = (bool) $enabled;
			}
		}

		return $sanitized;
	}

	/**
	 * Inject virtual donor portal page keys into a settings array.
	 *
	 * The page ID is stored as a standalone option, but the admin UI
	 * expects it alongside the other settings for convenience.
	 *
	 * @param array<string, mixed> $settings Settings array (modified by reference).
	 * @return void
	 */
	private function inject_page_keys( array &$settings ): void {
		$page_id = (int) get_option( 'mission_dashboard_page_id', 0 );

		$settings['donor_portal_page_id']    = $page_id;
		$settings['donor_portal_page_title'] = $page_id ? get_the_title( $page_id ) : '';
	}
}
