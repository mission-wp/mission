<?php
/**
 * REST endpoint for creating a Stripe PaymentIntent via the Mission API.
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
 * CreatePaymentIntent endpoint class.
 */
class CreatePaymentIntentEndpoint {

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
			'/donations/create-payment-intent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'donation_amount' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'tip_amount'      => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					),
			)
		);
	}

	/**
	 * Create a PaymentIntent via the Mission API.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$donation_amount = $request->get_param( 'donation_amount' );
		$tip_amount      = $request->get_param( 'tip_amount' );

		// Mission absorbs the Stripe fee on its own tip so the nonprofit never
		// pays higher fees because of our tip. Shift that cost from the tip to
		// the donation side — total charge stays the same.
		if ( $tip_amount > 0 ) {
			$total                  = $donation_amount + $tip_amount;
			$stripe_fee_with_tip    = (int) round( $total * 0.029 + 30 );
			$stripe_fee_without_tip = (int) round( $donation_amount * 0.029 + 30 );
			$tip_stripe_fee         = $stripe_fee_with_tip - $stripe_fee_without_tip;
			$tip_amount            -= $tip_stripe_fee;
			$donation_amount       += $tip_stripe_fee;
		}

		if ( $donation_amount < 1 ) {
			return new WP_Error(
				'invalid_amount',
				__( 'Donation amount must be at least 1 cent.', 'mission' ),
				array( 'status' => 400 )
			);
		}

		$site_token = $this->settings->get( 'stripe_site_token' );

		if ( empty( $site_token ) ) {
			return new WP_Error(
				'stripe_not_connected',
				__( 'Stripe is not connected. Please connect Stripe in the plugin settings.', 'mission' ),
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_post(
			self::API_BASE . '/create-payment-intent',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $site_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'donation_amount' => $donation_amount,
						'tip_amount'      => $tip_amount,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mission_api_error',
				__( 'Could not reach the Mission API.', 'mission' ),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['client_secret'] ) || empty( $body['connected_account_id'] ) ) {
			return new WP_Error(
				'payment_intent_failed',
				$body['error'] ?? __( 'Failed to create payment intent.', 'mission' ),
				array( 'status' => $code ?: 500 )
			);
		}

		// Persist the connected account ID if not already saved.
		if ( ! $this->settings->get( 'stripe_account_id' ) ) {
			$this->settings->update( array( 'stripe_account_id' => $body['connected_account_id'] ) );
		}

		return new WP_REST_Response(
			array(
				'client_secret'        => $body['client_secret'],
				'connected_account_id' => $body['connected_account_id'],
			),
			200
		);
	}
}
