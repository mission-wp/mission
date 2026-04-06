<?php
/**
 * REST endpoint for Stripe Connect OAuth flow.
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
 * Stripe Connect endpoint class.
 */
class StripeConnectEndpoint {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.missionwp.com';

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
			'/stripe/connect',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'connect' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'setup_code' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'site_id'    => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/stripe/disconnect',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'disconnect' ],
				'permission_callback' => [ $this, 'check_permission' ],
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
	 * Exchange setup_code for site_token via the Mission API.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function connect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$setup_code = $request->get_param( 'setup_code' );
		$site_id    = $request->get_param( 'site_id' );

		$response = wp_remote_post(
			self::API_BASE . '/connect/finalize',
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'setup_code' => $setup_code,
						'site_id'    => $site_id,
					]
				),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mission_connect_failed',
				__( 'Could not reach the Mission API.', 'mission' ),
				[ 'status' => 502 ]
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['site_token'] ) ) {
			return new WP_Error(
				'mission_connect_failed',
				$body['error'] ?? __( 'Stripe connection failed.', 'mission' ),
				[ 'status' => $code ?: 500 ]
			);
		}

		$settings = [
			'stripe_site_id'           => $site_id,
			'stripe_site_token'        => $body['site_token'],
			'stripe_account_id'        => $body['account_id'] ?? $body['stripe_account_id'] ?? '',
			'stripe_display_name'      => $body['display_name'] ?? '',
			'stripe_connection_status' => 'connected',
			'stripe_charges_enabled'   => (bool) $request->get_param( 'charges_enabled' ),
			'test_mode'                => false,
		];

		if ( ! empty( $body['default_currency'] ) ) {
			$settings['currency'] = strtoupper( $body['default_currency'] );
		}

		$this->settings->update( $settings );

		// Register webhook URL with the Mission API.
		$webhook_error = $this->register_webhook_url( $body['site_token'] );

		if ( $webhook_error ) {
			// Roll back — connection is useless without webhooks.
			$this->settings->update(
				[
					'stripe_site_id'           => '',
					'stripe_site_token'        => '',
					'stripe_account_id'        => '',
					'stripe_display_name'      => '',
					'stripe_connection_status' => 'disconnected',
					'stripe_charges_enabled'   => false,
				]
			);

			return $webhook_error;
		}

		$updated = $this->settings->get_all();
		unset( $updated['stripe_site_token'] );

		return new WP_REST_Response( $updated, 200 );
	}

	/**
	 * Register this site's webhook URL with the Mission API.
	 *
	 * @param string $site_token Site authentication token.
	 * @return WP_Error|null Error on failure, null on success.
	 */
	private function register_webhook_url( string $site_token ): ?WP_Error {
		$webhook_url = rest_url( RestModule::NAMESPACE . '/webhooks/stripe' );

		$response = wp_remote_post(
			self::API_BASE . '/register-webhook',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $site_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( [ 'webhook_url' => $webhook_url ] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'webhook_registration_failed',
				__( 'Stripe connected but webhook registration failed. Please try reconnecting.', 'mission' ),
				[ 'status' => 502 ]
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['webhook_secret'] ) ) {
			return new WP_Error(
				'webhook_registration_failed',
				__( 'Stripe connected but webhook registration failed. Please try reconnecting.', 'mission' ),
				[ 'status' => 502 ]
			);
		}

		$this->settings->update( [ 'stripe_webhook_secret' => $body['webhook_secret'] ] );

		return null;
	}

	/**
	 * Disconnect Stripe and clear connection settings.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function disconnect(): WP_REST_Response|WP_Error {
		$site_token = $this->settings->get( 'stripe_site_token' );

		if ( $site_token ) {
			wp_remote_post(
				self::API_BASE . '/disconnect',
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $site_token,
						'Content-Type'  => 'application/json',
					],
					'timeout' => 15,
				]
			);
		}

		$updated = $this->settings->update(
			[
				'stripe_site_id'           => '',
				'stripe_site_token'        => '',
				'stripe_account_id'        => '',
				'stripe_display_name'      => '',
				'stripe_connection_status' => 'disconnected',
				'stripe_charges_enabled'   => false,
				'stripe_webhook_secret'    => '',
			]
		);

		unset( $updated['stripe_site_token'] );

		return new WP_REST_Response( $updated, 200 );
	}
}
