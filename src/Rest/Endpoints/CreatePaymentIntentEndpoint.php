<?php
/**
 * REST endpoint for creating a Stripe PaymentIntent via the Mission API.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Currency\Currency;
use Mission\Models\Campaign;
use Mission\Models\Donor;
use Mission\Models\Transaction;
use Mission\Models\Tribute;
use Mission\Rest\RestModule;
use Mission\Rest\Traits\MinimumAmountTrait;
use Mission\Rest\Traits\RateLimitTrait;
use Mission\Settings\SettingsService;
use Mission\Tip\TipCalculator;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * CreatePaymentIntent endpoint class.
 */
class CreatePaymentIntentEndpoint {

	use MinimumAmountTrait;
	use RateLimitTrait;

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
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'donation_amount'      => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'tip_amount'           => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
					'fee_amount'           => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
					'currency'             => [
						'required'          => false,
						'type'              => 'string',
						'default'           => 'usd',
						'sanitize_callback' => static fn( $val ) => strtolower( sanitize_text_field( $val ) ),
					],
					'donor_email'          => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					],
					'donor_first_name'     => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'donor_last_name'      => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'frequency'            => [
						'required'          => false,
						'type'              => 'string',
						'default'           => 'one_time',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'campaign_id'          => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
					'source_post_id'       => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
					'form_id'              => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'is_anonymous'         => [
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					],
					'phone'                => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'comment'              => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'tribute_type'         => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'honoree_name'         => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'notify_name'          => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'notify_email'         => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_email',
					],
					'address_1'            => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'address_2'            => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'city'                 => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'state'                => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'zip'                  => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'country'              => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'custom_fields'        => [
						'required' => false,
						'type'     => 'object',
						'default'  => [],
					],
					'custom_fields_config' => [
						'required' => false,
						'type'     => 'array',
						'default'  => [],
					],
					'fee_mode'             => [
						'required'          => false,
						'type'              => 'string',
						'default'           => 'tip',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $val ) => in_array( $val, [ 'tip', 'flat' ], true ),
					],
				],
			]
		);
	}

	/**
	 * Create a PaymentIntent via the Mission API and a pending transaction.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'create_payment', 15, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		// Validate email early, before any external calls.
		$email = $request->get_param( 'donor_email' );

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'A valid email address is required.', 'mission' ),
				[ 'status' => 400 ]
			);
		}

		$donation_amount = $request->get_param( 'donation_amount' );
		$tip_amount      = $request->get_param( 'tip_amount' );
		$fee_amount      = $request->get_param( 'fee_amount' );
		$fee_mode        = $request->get_param( 'fee_mode' );
		$currency        = $request->get_param( 'currency' );

		if ( $fee_amount > $donation_amount ) {
			return new WP_Error(
				'invalid_fee',
				__( 'Fee amount cannot exceed the donation amount.', 'mission' ),
				[ 'status' => 400 ]
			);
		}

		// Preserve the original donation amount (before fee inclusion) for the description.
		$original_donation = $donation_amount - $fee_amount;

		// Mission absorbs the Stripe fee on its own tip so the nonprofit never
		// pays higher fees because of our tip.
		[ $fee_rate, $fee_fixed ] = TipCalculator::get_fee_params_from_settings( $this->settings );
		TipCalculator::absorb_fee( $donation_amount, $tip_amount, $fee_rate, $fee_fixed );

		$minimum_check = $this->validate_minimum_amount(
			$donation_amount,
			$request->get_param( 'source_post_id' ),
			$request->get_param( 'form_id' ),
		);

		if ( is_wp_error( $minimum_check ) ) {
			return $minimum_check;
		}

		$site_token = $this->settings->get( 'stripe_site_token' );

		if ( empty( $site_token ) ) {
			return new WP_Error(
				'stripe_not_connected',
				__( 'Stripe is not connected. Please connect Stripe in the plugin settings.', 'mission' ),
				[ 'status' => 400 ]
			);
		}

		$description = $this->build_description( $original_donation, $fee_amount, $tip_amount, $fee_mode, $currency );

		$response = wp_remote_post(
			self::API_BASE . '/create-payment-intent',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $site_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'donation_amount'      => $donation_amount,
						'tip_amount'           => $tip_amount,
						'fee_mode'             => $fee_mode,
						'currency'             => $currency,
						'description'          => $description,
						'payment_method_types' => [ 'card' ],
						'test_mode'            => (bool) $this->settings->get( 'test_mode' ),
					]
				),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mission_api_error',
				__( 'Could not reach the Mission API.', 'mission' ),
				[ 'status' => 502 ]
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['client_secret'] ) || empty( $body['connected_account_id'] ) ) {
			return new WP_Error(
				'payment_intent_failed',
				$body['error'] ?? __( 'Failed to create payment intent.', 'mission' ),
				[ 'status' => $code ?: 500 ]
			);
		}

		// Persist the connected account ID if not already saved.
		if ( ! $this->settings->get( 'stripe_account_id' ) ) {
			$this->settings->update( [ 'stripe_account_id' => $body['connected_account_id'] ] );
		}

		// Upsert donor.
		$donor = Donor::find_by_email( $email );

		if ( ! $donor ) {
			$donor = new Donor(
				[
					'email'      => $email,
					'first_name' => $request->get_param( 'donor_first_name' ),
					'last_name'  => $request->get_param( 'donor_last_name' ),
				]
			);
		}

		// Update phone if provided.
		$phone = $request->get_param( 'phone' );
		if ( $phone ) {
			$donor->phone = $phone;
		}

		// Update address if provided (new or returning donor).
		$address_1 = $request->get_param( 'address_1' );
		if ( $address_1 ) {
			$donor->address_1 = $address_1;
			$donor->address_2 = $request->get_param( 'address_2' );
			$donor->city      = $request->get_param( 'city' );
			$donor->state     = $request->get_param( 'state' );
			$donor->zip       = $request->get_param( 'zip' );
			$donor->country   = $request->get_param( 'country' );
		}

		$donor->save();

		// Extract the PaymentIntent ID from the client secret.
		$payment_intent_id = explode( '_secret_', $body['client_secret'] )[0];

		// Resolve campaign from the provided campaign table ID.
		$campaign_id = $request->get_param( 'campaign_id' );
		$campaign    = $campaign_id ? Campaign::find( $campaign_id ) : null;
		$campaign_id = $campaign?->id;

		// Use the original request amounts for the transaction record (before fee absorption math).
		$req_donation_amount = $request->get_param( 'donation_amount' );
		$req_fee_amount      = $request->get_param( 'fee_amount' );
		$req_tip_amount      = $request->get_param( 'tip_amount' );
		$total_amount        = $req_donation_amount + $req_tip_amount;

		$transaction = new Transaction(
			[
				'status'                 => 'pending',
				'type'                   => $request->get_param( 'frequency' ),
				'donor_id'               => $donor->id,
				'source_post_id'         => $request->get_param( 'source_post_id' ),
				'campaign_id'            => $campaign_id,
				'amount'                 => $req_donation_amount - $req_fee_amount,
				'fee_amount'             => $req_fee_amount,
				'tip_amount'             => $req_tip_amount,
				'total_amount'           => $total_amount,
				'currency'               => $currency,
				'payment_gateway'        => 'stripe',
				'gateway_transaction_id' => $payment_intent_id,
				'is_anonymous'           => $request->get_param( 'is_anonymous' ),
				'is_test'                => (bool) $this->settings->get( 'test_mode' ),
				'donor_ip'               => $this->get_client_ip(),
			]
		);

		$transaction->save();

		// Store the fee rate at time of transaction for accurate historical reporting.
		$transaction->add_meta( 'stripe_fee_percent', (string) $this->settings->get( 'stripe_fee_percent', 2.9 ) );
		$transaction->add_meta( 'stripe_fee_fixed', (string) $this->settings->get( 'stripe_fee_fixed', 30 ) );

		// Store the platform fee mode so historical records are accurate.
		$transaction->add_meta( 'fee_mode', $fee_mode );

		// Store tribute if provided.
		$tribute_type = $request->get_param( 'tribute_type' );
		if ( ! empty( $tribute_type ) ) {
			$tribute = new Tribute(
				[
					'transaction_id'   => $transaction->id,
					'tribute_type'     => $tribute_type,
					'honoree_name'     => $request->get_param( 'honoree_name' ) ?? '',
					'notify_name'      => $request->get_param( 'notify_name' ) ?? '',
					'notify_email'     => $request->get_param( 'notify_email' ) ?? '',
					'notify_method'    => $request->get_param( 'notify_method' ) ?? '',
					'notify_address_1' => $request->get_param( 'notify_address_1' ) ?? '',
					'notify_city'      => $request->get_param( 'notify_city' ) ?? '',
					'notify_state'     => $request->get_param( 'notify_state' ) ?? '',
					'notify_zip'       => $request->get_param( 'notify_zip' ) ?? '',
					'notify_country'   => $request->get_param( 'notify_country' ) ?? '',
					'message'          => $request->get_param( 'message' ) ?? '',
				]
			);
			$tribute->save();
		}

		// Store donor comment as transaction meta.
		$comment = $request->get_param( 'comment' );
		if ( ! empty( $comment ) ) {
			$transaction->add_meta( 'donor_comment', $comment );
		}

		// Store billing address as transaction meta (point-in-time snapshot).
		$address_meta = [
			'address_1' => $request->get_param( 'address_1' ),
			'address_2' => $request->get_param( 'address_2' ),
			'city'      => $request->get_param( 'city' ),
			'state'     => $request->get_param( 'state' ),
			'zip'       => $request->get_param( 'zip' ),
			'country'   => $request->get_param( 'country' ),
		];

		foreach ( $address_meta as $key => $value ) {
			if ( ! empty( $value ) ) {
				$transaction->add_meta( $key, $value );
			}
		}

		// Store custom field responses.
		$custom_fields = $request->get_param( 'custom_fields' );
		$custom_config = $request->get_param( 'custom_fields_config' );

		if ( ! empty( $custom_fields ) && is_array( $custom_fields ) ) {
			foreach ( $custom_fields as $field_id => $value ) {
				$safe_key = sanitize_key( $field_id );
				if ( is_array( $value ) ) {
					$safe_value = wp_json_encode( array_map( 'sanitize_text_field', $value ) );
				} elseif ( is_bool( $value ) ) {
					$safe_value = $value ? '1' : '0';
				} else {
					$safe_value = sanitize_text_field( (string) $value );
				}
				$transaction->add_meta( 'custom_field_' . $safe_key, $safe_value );
			}
		}

		if ( ! empty( $custom_config ) && is_array( $custom_config ) ) {
			// Whitelist config to id, type, label only.
			$sanitized_config = array_map(
				static fn( $field ) => [
					'id'    => sanitize_key( $field['id'] ?? '' ),
					'type'  => sanitize_text_field( $field['type'] ?? 'text' ),
					'label' => sanitize_text_field( $field['label'] ?? '' ),
				],
				$custom_config,
			);
			$transaction->add_meta( 'custom_fields_config', wp_json_encode( $sanitized_config ) );
		}

		return new WP_REST_Response(
			[
				'client_secret'        => $body['client_secret'],
				'connected_account_id' => $body['connected_account_id'],
				'transaction_id'       => $transaction->id,
			],
			200
		);
	}

	/**
	 * Build a human-readable description for the Stripe PaymentIntent.
	 *
	 * @param int    $donation_amount Original donation amount in minor units.
	 * @param int    $fee_amount      Fee recovery amount in minor units (0 if not covered).
	 * @param int    $tip_amount      Tip amount in minor units (after fee absorption).
	 * @param string $fee_mode        Platform fee mode: 'tip' or 'flat'.
	 * @param string $currency        Lowercase ISO 4217 currency code.
	 * @return string
	 */
	private function build_description( int $donation_amount, int $fee_amount, int $tip_amount, string $fee_mode, string $currency ): string {
		$currency_upper     = strtoupper( $currency );
		$donation_formatted = $this->format_amount( $donation_amount, $currency_upper );

		/* translators: %s: formatted donation amount (e.g. "$50.00") */
		$description = sprintf( __( '%s donation.', 'mission' ), $donation_formatted );

		if ( $fee_amount > 0 ) {
			$description .= ' ' . __( 'Donor covered processing fees.', 'mission' );
		}

		if ( $tip_amount > 0 ) {
			$tip_formatted = $this->format_amount( $tip_amount, $currency_upper );

			$description .= ' ' . sprintf(
				/* translators: %s: formatted tip amount (e.g. "$1.23") */
				__( 'The %s application fee is a tip from the donor to Mission, not a charge to your organization.', 'mission' ),
				$tip_formatted,
			);
		}

		if ( 'flat' === $fee_mode ) {
			$description .= ' ' . __( 'Includes 3% platform fee.', 'mission' );
		}

		/**
		 * Filters the PaymentIntent description sent to Stripe.
		 *
		 * @since 1.0.0
		 *
		 * @param string $description     The description string.
		 * @param int    $donation_amount  Original donation amount in minor units.
		 * @param int    $fee_amount       Fee recovery amount in minor units.
		 * @param int    $tip_amount       Tip amount in minor units.
		 * @param string $fee_mode         Platform fee mode: 'tip' or 'flat'.
		 * @param string $currency         Lowercase ISO 4217 currency code.
		 */
		return apply_filters( 'mission_payment_intent_description', $description, $donation_amount, $fee_amount, $tip_amount, $fee_mode, $currency );
	}

	/**
	 * Format a minor-unit amount as a currency string.
	 *
	 * @param int    $minor_units   Amount in minor units (e.g. 5000 = $50.00).
	 * @param string $currency_code Uppercase ISO 4217 currency code.
	 * @return string
	 */
	private function format_amount( int $minor_units, string $currency_code ): string {
		return Currency::format_amount( $minor_units, $currency_code );
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );

		return sanitize_text_field( $ip );
	}
}
