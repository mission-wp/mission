<?php
/**
 * REST endpoint for Stripe Connect OAuth flow.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Rest\Args;
use MissionDP\Rest\RestErrors;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\AdminPermissionTrait;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe Connect endpoint class.
 */
class StripeConnectEndpoint {

	use AdminPermissionTrait;

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
			'/stripe/connect',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'connect' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'setup_code' => Args::string( [ 'required' => true ] ),
					'site_id'    => Args::string( [ 'required' => true ] ),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/stripe/disconnect',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'disconnect' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'account_id' => Args::string(
						[
							'required' => false,
							'default'  => '',
						]
					),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/stripe/accounts/(?P<account_id>[a-zA-Z0-9_]+)/default',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'make_default' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'account_id' => Args::string( [ 'required' => true ] ),
				],
			]
		);
	}

	/**
	 * Message returned when the capability check fails.
	 *
	 * @return string
	 */
	protected function permission_denied_message(): string {
		return __( 'You do not have permission to manage settings.', 'mission-donation-platform' );
	}

	/**
	 * Exchange setup_code for site_token via the Mission API and add the
	 * resulting account to the connected-accounts list.
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
				'missiondp_connect_failed',
				__( 'Could not reach the Mission API.', 'mission-donation-platform' ),
				[ 'status' => 502 ]
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['site_token'] ) ) {
			return new WP_Error(
				'missiondp_connect_failed',
				$body['error'] ?? __( 'Stripe connection failed.', 'mission-donation-platform' ),
				[ 'status' => $code ?: 500 ]
			);
		}

		$site_token = $body['site_token'];
		$account_id = $body['account_id'] ?? $body['stripe_account_id'] ?? '';

		$webhook_result = $this->fetch_webhook_secret( $site_token );

		if ( $webhook_result instanceof WP_Error ) {
			return $webhook_result;
		}

		$account = [
			'site_id'           => $site_id,
			'site_token'        => $site_token,
			'account_id'        => $account_id,
			'display_name'      => $body['display_name'] ?? '',
			'connection_status' => 'connected',
			'charges_enabled'   => (bool) $request->get_param( 'charges_enabled' ),
			'webhook_secret'    => $webhook_result,
			'connected_at'      => gmdate( 'c' ),
		];

		$this->settings->add_stripe_account( $account );

		// Only switch out of test mode on the first successful connection.
		$updates = [ 'test_mode' => false ];

		if ( ! empty( $body['default_currency'] ) && 1 === count( $this->settings->get_stripe_accounts() ) ) {
			$updates['currency'] = strtoupper( $body['default_currency'] );
		}

		$this->settings->update( $updates );

		return new WP_REST_Response( $this->build_response_payload(), 200 );
	}

	/**
	 * Register this site's webhook URL with the Mission API and return the secret.
	 *
	 * @param string $site_token Site authentication token for the account.
	 * @return string|WP_Error Webhook secret on success, WP_Error on failure.
	 */
	private function fetch_webhook_secret( string $site_token ): string|WP_Error {
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
				__( 'Stripe connected but webhook registration failed. Please try reconnecting.', 'mission-donation-platform' ),
				[ 'status' => 502 ]
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['webhook_secret'] ) ) {
			return new WP_Error(
				'webhook_registration_failed',
				__( 'Stripe connected but webhook registration failed. Please try reconnecting.', 'mission-donation-platform' ),
				[ 'status' => 502 ]
			);
		}

		return (string) $body['webhook_secret'];
	}

	/**
	 * Disconnect a Stripe account by ID. When no ID is given, disconnects all
	 * accounts (legacy behavior, used by the old single-account UI).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function disconnect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$account_id = (string) $request->get_param( 'account_id' );

		if ( '' === $account_id ) {
			// Backward-compat: no account_id means disconnect everything.
			foreach ( $this->settings->get_stripe_accounts() as $account ) {
				$this->notify_api_disconnect( (string) ( $account['site_token'] ?? '' ) );
			}

			$this->settings->clear_stripe_accounts();

			return new WP_REST_Response( $this->build_response_payload(), 200 );
		}

		$account = $this->settings->get_stripe_account_by_id( $account_id );

		if ( ! $account ) {
			return RestErrors::account_not_found();
		}

		$this->notify_api_disconnect( (string) ( $account['site_token'] ?? '' ) );
		$this->settings->remove_stripe_account( $account_id );

		return new WP_REST_Response( $this->build_response_payload(), 200 );
	}

	/**
	 * Mark an account as the default.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function make_default( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$account_id = (string) $request->get_param( 'account_id' );

		if ( ! $this->settings->set_default_stripe_account( $account_id ) ) {
			return RestErrors::account_not_found();
		}

		return new WP_REST_Response( $this->build_response_payload(), 200 );
	}

	/**
	 * Fire-and-forget notification to the Mission API that the account is gone.
	 *
	 * @param string $site_token Site authentication token.
	 * @return void
	 */
	private function notify_api_disconnect( string $site_token ): void {
		if ( '' === $site_token ) {
			return;
		}

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

	/**
	 * Build a settings response payload safe for the admin UI — strips tokens
	 * and webhook secrets from any embedded account data.
	 *
	 * @return array<string, mixed>
	 */
	private function build_response_payload(): array {
		$all = $this->settings->get_all();

		// Replace the raw accounts (with tokens) with the public/sanitized list.
		$all['stripe_accounts'] = $this->settings->get_stripe_accounts_public();

		// Strip legacy token from response.
		unset( $all['stripe_site_token'] );
		unset( $all['stripe_webhook_secret'] );

		// Derive top-level connection flags from the default account so the
		// rest of the settings UI keeps working without a full rewrite.
		$default = $this->settings->get_default_stripe_account();
		if ( $default ) {
			$all['stripe_connection_status'] = $default['connection_status'] ?? 'connected';
			$all['stripe_account_id']        = $default['account_id'] ?? '';
			$all['stripe_display_name']      = $default['display_name'] ?? '';
			$all['stripe_charges_enabled']   = ! empty( $default['charges_enabled'] );
		} else {
			$all['stripe_connection_status'] = 'disconnected';
			$all['stripe_account_id']        = '';
			$all['stripe_display_name']      = '';
			$all['stripe_charges_enabled']   = false;
		}

		return $all;
	}
}
