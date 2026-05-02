<?php
/**
 * REST endpoint for creating a Stripe Subscription via the Mission API.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Currency\Currency;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Models\Tribute;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\MinimumAmountTrait;
use MissionDP\Rest\Traits\RateLimitTrait;
use MissionDP\Settings\SettingsService;
use MissionDP\Tip\TipCalculator;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * CreateSubscription endpoint class.
 */
class CreateSubscriptionEndpoint {

	use MinimumAmountTrait;
	use RateLimitTrait;

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.missionwp.com';

	/**
	 * Allowed recurring frequencies.
	 *
	 * @var string[]
	 */
	private const ALLOWED_FREQUENCIES = [ 'weekly', 'monthly', 'quarterly', 'annually' ];

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
			'/donations/create-subscription',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				// Public — donors are unauthenticated when starting a recurring
				// donation. Abuse is mitigated by rate limiting, input validation,
				// and the fact that subscription creation happens server-side via
				// the Mission API (a malicious caller cannot mint usable Stripe state).
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
						'required'          => true,
						'type'              => 'string',
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
	 * Create a Subscription via the Mission API and a pending transaction.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'create_payment', 15, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		$email = $request->get_param( 'donor_email' );

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'A valid email address is required.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$frequency = $request->get_param( 'frequency' );

		if ( ! in_array( $frequency, self::ALLOWED_FREQUENCIES, true ) ) {
			return new WP_Error(
				'invalid_frequency',
				__( 'Invalid recurring frequency. Must be weekly, monthly, quarterly, or annually.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$donation_amount = $request->get_param( 'donation_amount' );
		$tip_amount      = $request->get_param( 'tip_amount' );
		$fee_amount      = $request->get_param( 'fee_amount' );
		$fee_mode        = $request->get_param( 'fee_mode' );
		$currency        = $request->get_param( 'currency' );

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
				__( 'Stripe is not connected. Please connect Stripe in the plugin settings.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$donor_name = trim(
			$request->get_param( 'donor_first_name' ) . ' ' . $request->get_param( 'donor_last_name' )
		);

		$description = $this->build_description( $original_donation, $fee_amount, $tip_amount, $fee_mode, $currency, $frequency );

		$response = wp_remote_post(
			self::API_BASE . '/create-subscription',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $site_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'donor_email'     => $email,
						'donor_name'      => $donor_name,
						'donation_amount' => $donation_amount,
						'tip_amount'      => $tip_amount,
						'fee_mode'        => $fee_mode,
						'currency'        => $currency,
						'frequency'       => $frequency,
						'description'     => $description,
						'test_mode'       => (bool) $this->settings->get( 'test_mode' ),
					]
				),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'missiondp_api_error',
				__( 'Could not reach the Mission API.', 'mission-donation-platform' ),
				[ 'status' => 502 ]
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['client_secret'] ) || empty( $body['connected_account_id'] ) ) {
			return new WP_Error(
				'subscription_creation_failed',
				$body['error'] ?? __( 'Failed to create subscription.', 'mission-donation-platform' ),
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

		$phone = $request->get_param( 'phone' );
		if ( $phone ) {
			$donor->phone = $phone;
		}

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

		// Use the original request amounts for records (before fee absorption).
		$req_donation_amount = $request->get_param( 'donation_amount' );
		$req_fee_amount      = $request->get_param( 'fee_amount' );
		$req_tip_amount      = $request->get_param( 'tip_amount' );
		$total_amount        = $req_donation_amount + $req_tip_amount;
		$is_test             = (bool) $this->settings->get( 'test_mode' );

		// Create subscription record.
		$subscription = new Subscription(
			[
				'status'                  => 'pending',
				'donor_id'                => $donor->id,
				'source_post_id'          => $request->get_param( 'source_post_id' ),
				'campaign_id'             => $campaign_id,
				'amount'                  => $req_donation_amount - $req_fee_amount,
				'fee_amount'              => $req_fee_amount,
				'tip_amount'              => $req_tip_amount,
				'total_amount'            => $total_amount,
				'currency'                => $currency,
				'frequency'               => $frequency,
				'payment_gateway'         => 'stripe',
				'gateway_subscription_id' => $body['subscription_id'] ?? null,
				'gateway_customer_id'     => $body['customer_id'] ?? null,
				'is_test'                 => $is_test,
			]
		);

		$subscription->save();

		// Create pending transaction for the initial payment.
		$transaction = new Transaction(
			[
				'status'                  => 'pending',
				'type'                    => $frequency,
				'donor_id'                => $donor->id,
				'subscription_id'         => $subscription->id,
				'source_post_id'          => $request->get_param( 'source_post_id' ),
				'campaign_id'             => $campaign_id,
				'amount'                  => $req_donation_amount - $req_fee_amount,
				'fee_amount'              => $req_fee_amount,
				'tip_amount'              => $req_tip_amount,
				'total_amount'            => $total_amount,
				'currency'                => $currency,
				'payment_gateway'         => 'stripe',
				'gateway_transaction_id'  => $payment_intent_id,
				'gateway_subscription_id' => $body['subscription_id'] ?? null,
				'is_anonymous'            => $request->get_param( 'is_anonymous' ),
				'is_test'                 => $is_test,
				'donor_ip'                => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			]
		);

		$transaction->save();

		// Store fee rates at time of transaction.
		$transaction->add_meta( 'stripe_fee_percent', (string) $this->settings->get( 'stripe_fee_percent', 2.9 ) );
		$transaction->add_meta( 'stripe_fee_fixed', (string) $this->settings->get( 'stripe_fee_fixed', 30 ) );

		// Store the platform fee mode so historical records are accurate.
		$transaction->add_meta( 'fee_mode', $fee_mode );
		$subscription->add_meta( 'fee_mode', $fee_mode );

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

		// Store donor comment.
		$comment = $request->get_param( 'comment' );
		if ( ! empty( $comment ) ) {
			$transaction->add_meta( 'donor_comment', $comment );
		}

		// Store billing address snapshot.
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
				'subscription_id'      => $subscription->id,
			],
			200
		);
	}

	/**
	 * Build a human-readable description for the Stripe Subscription.
	 *
	 * @param int    $donation_amount Original donation amount in minor units.
	 * @param int    $fee_amount      Fee recovery amount in minor units (0 if not covered).
	 * @param int    $tip_amount      Tip amount in minor units (after fee absorption).
	 * @param string $fee_mode        Platform fee mode: 'tip' or 'flat'.
	 * @param string $currency        Lowercase ISO 4217 currency code.
	 * @param string $frequency       Billing frequency (weekly, monthly, quarterly, annually).
	 * @return string
	 */
	private function build_description( int $donation_amount, int $fee_amount, int $tip_amount, string $fee_mode, string $currency, string $frequency ): string {
		$currency_upper     = strtoupper( $currency );
		$donation_formatted = Currency::format_amount( $donation_amount, $currency_upper );

		$frequency_labels = [
			'weekly'    => __( 'weekly', 'mission-donation-platform' ),
			'monthly'   => __( 'monthly', 'mission-donation-platform' ),
			'quarterly' => __( 'quarterly', 'mission-donation-platform' ),
			'annually'  => __( 'annual', 'mission-donation-platform' ),
		];

		$frequency_label = $frequency_labels[ $frequency ] ?? $frequency;

		/* translators: 1: formatted donation amount, 2: frequency label */
		$description = sprintf( __( '%1$s %2$s recurring donation.', 'mission-donation-platform' ), $donation_formatted, $frequency_label );

		if ( $fee_amount > 0 ) {
			$description .= ' ' . __( 'Donor covered processing fees.', 'mission-donation-platform' );
		}

		if ( $tip_amount > 0 ) {
			$tip_formatted = Currency::format_amount( $tip_amount, $currency_upper );

			$description .= ' ' . sprintf(
				/* translators: %s: formatted tip amount */
				__( 'The %s application fee is a tip from the donor to Mission, not a charge to your organization.', 'mission-donation-platform' ),
				$tip_formatted,
			);
		}

		if ( 'flat' === $fee_mode ) {
			$description .= ' ' . __( 'Includes 3% platform fee.', 'mission-donation-platform' );
		}

		/**
		 * Filters the Subscription description sent to Stripe.
		 *
		 * @since 1.0.0
		 *
		 * @param string $description     The description string.
		 * @param int    $donation_amount  Original donation amount in minor units.
		 * @param int    $fee_amount       Fee recovery amount in minor units.
		 * @param int    $tip_amount       Tip amount in minor units.
		 * @param string $fee_mode         Platform fee mode: 'tip' or 'flat'.
		 * @param string $currency         Lowercase ISO 4217 currency code.
		 * @param string $frequency        Billing frequency.
		 */
		return apply_filters( 'missiondp_subscription_description', $description, $donation_amount, $fee_amount, $tip_amount, $fee_mode, $currency, $frequency );
	}
}
