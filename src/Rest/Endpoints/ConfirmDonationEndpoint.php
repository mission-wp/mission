<?php
/**
 * REST endpoint for confirming a donation after Stripe payment.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Database\DataStore\DonorDataStore;
use Mission\Database\DataStore\TransactionDataStore;
use Mission\Models\Donor;
use Mission\Models\Transaction;
use Mission\Rest\RestModule;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ConfirmDonation endpoint class.
 */
class ConfirmDonationEndpoint {

	/**
	 * Constructor.
	 *
	 * @param DonorDataStore       $donors       Donor data store.
	 * @param TransactionDataStore $transactions Transaction data store.
	 */
	public function __construct(
		private readonly DonorDataStore $donors,
		private readonly TransactionDataStore $transactions,
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/donations/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'payment_intent_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'donor_email'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'donor_first_name'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'donor_last_name'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'donation_amount'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'fee_amount'        => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'tip_amount'        => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'currency'          => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'usd',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'frequency'         => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'one_time',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'campaign_id'       => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'source_post_id'    => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'is_anonymous'      => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);
	}

	/**
	 * Record a confirmed donation in the database.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = $request->get_param( 'donor_email' );

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'A valid email address is required.', 'mission' ),
				array( 'status' => 400 )
			);
		}

		// Upsert donor.
		$donor = $this->donors->find_by_email( $email );

		if ( ! $donor ) {
			$donor = new Donor(
				array(
					'email'      => $email,
					'first_name' => $request->get_param( 'donor_first_name' ),
					'last_name'  => $request->get_param( 'donor_last_name' ),
				)
			);
			$this->donors->create( $donor );
		}

		$donation_amount = $request->get_param( 'donation_amount' );
		$fee_amount      = $request->get_param( 'fee_amount' );
		$tip_amount      = $request->get_param( 'tip_amount' );
		$total_amount    = $donation_amount + $fee_amount + $tip_amount;
		$campaign_id     = $request->get_param( 'campaign_id' );

		$transaction = new Transaction(
			array(
				'status'                 => 'completed',
				'type'                   => $request->get_param( 'frequency' ),
				'donor_id'               => $donor->id,
				'source_post_id'         => $request->get_param( 'source_post_id' ),
				'campaign_id'            => $campaign_id ?: null,
				'amount'                 => $donation_amount,
				'fee_amount'             => $fee_amount,
				'tip_amount'             => $tip_amount,
				'total_amount'           => $total_amount,
				'currency'               => strtolower( $request->get_param( 'currency' ) ),
				'payment_gateway'        => 'stripe',
				'gateway_transaction_id' => $request->get_param( 'payment_intent_id' ),
				'is_anonymous'           => $request->get_param( 'is_anonymous' ),
				'donor_ip'               => $this->get_client_ip(),
				'date_completed'         => current_time( 'mysql', true ),
			)
		);

		$transaction_id = $this->transactions->create( $transaction );

		return new WP_REST_Response(
			array(
				'success'        => true,
				'transaction_id' => $transaction_id,
			),
			200
		);
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';

		return sanitize_text_field( $ip );
	}
}
