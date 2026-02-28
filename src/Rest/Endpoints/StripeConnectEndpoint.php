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
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'connect' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'setup_code' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'site_id'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/stripe/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => array( $this, 'check_permission' ),
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
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'setup_code' => $setup_code,
						'site_id'    => $site_id,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mission_connect_failed',
				__( 'Could not reach the Mission API.', 'mission' ),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['site_token'] ) ) {
			return new WP_Error(
				'mission_connect_failed',
				$body['error'] ?? __( 'Stripe connection failed.', 'mission' ),
				array( 'status' => $code ?: 500 )
			);
		}

		$updated = $this->settings->update(
			array(
				'stripe_site_id'           => $site_id,
				'stripe_site_token'        => $body['site_token'],
				'stripe_account_id'        => $body['stripe_account_id'] ?? '',
				'stripe_display_name'      => $body['display_name'] ?? '',
				'stripe_connection_status' => 'connected',
			)
		);

		unset( $updated['stripe_site_token'] );

		return new WP_REST_Response( $updated, 200 );
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
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $site_token,
						'Content-Type'  => 'application/json',
					),
					'timeout' => 15,
				)
			);
		}

		$updated = $this->settings->update(
			array(
				'stripe_site_id'           => '',
				'stripe_site_token'        => '',
				'stripe_account_id'        => '',
				'stripe_display_name'      => '',
				'stripe_connection_status' => 'disconnected',
			)
		);

		unset( $updated['stripe_site_token'] );

		return new WP_REST_Response( $updated, 200 );
	}
}
