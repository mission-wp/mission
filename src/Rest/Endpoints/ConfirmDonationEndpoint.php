<?php
/**
 * REST endpoint for confirming a donation after Stripe payment.
 *
 * Called by the donation form after Stripe.js confirms the payment client-side.
 * This endpoint synchronously verifies the PaymentIntent status with Stripe
 * (via the Mission API) and transitions the pending transaction to completed
 * when Stripe confirms success. If the Mission API is unavailable, the endpoint
 * falls back to a processing response and the Stripe webhook completes the
 * transaction asynchronously.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Models\Transaction;
use MissionDP\Payments\PaymentIntentVerifier;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\RateLimitTrait;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ConfirmDonation endpoint class.
 */
class ConfirmDonationEndpoint {

	use RateLimitTrait;

	/**
	 * Constructor.
	 *
	 * @param PaymentIntentVerifier $verifier PaymentIntent verifier service.
	 */
	public function __construct(
		private readonly PaymentIntentVerifier $verifier,
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
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'transaction_id'    => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'payment_intent_id' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Confirm a donation by verifying PaymentIntent status with Stripe.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'confirm_donation', 10, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		$transaction = Transaction::find( $request->get_param( 'transaction_id' ) );

		if ( ! $transaction ) {
			return new WP_Error(
				'transaction_not_found',
				__( 'Transaction not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		// Require the client to know the correct PaymentIntent ID. Prevents
		// enumeration by transaction ID alone.
		$payment_intent_id = $request->get_param( 'payment_intent_id' );

		if ( ! hash_equals( $transaction->gateway_transaction_id, $payment_intent_id ) ) {
			return new WP_Error(
				'transaction_mismatch',
				__( 'Payment intent does not match this transaction.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		// Happy path: the webhook arrived before the client's confirm call.
		if ( 'completed' === $transaction->status ) {
			return new WP_REST_Response(
				[
					'status'         => 'completed',
					'transaction_id' => $transaction->id,
				],
				200
			);
		}

		if ( in_array( $transaction->status, [ 'failed', 'cancelled' ], true ) ) {
			return new WP_Error(
				'payment_failed',
				__( 'The payment was not successful.', 'mission-donation-platform' ),
				[ 'status' => 402 ]
			);
		}

		// Transaction is still pending — verify with Stripe synchronously.
		$verification = $this->verifier->verify( $payment_intent_id, (bool) $transaction->is_test );

		if ( ! $verification['verified'] ) {
			// Mission API unavailable or not deployed — fall back to the
			// webhook path. Client will see "processing" and can retry or the
			// webhook will complete the transaction asynchronously.
			return new WP_REST_Response(
				[
					'status'         => 'processing',
					'transaction_id' => $transaction->id,
				],
				202
			);
		}

		$stripe_status = $verification['stripe_status'];

		if ( 'succeeded' === $stripe_status ) {
			$this->complete_transaction( $transaction, $verification );

			return new WP_REST_Response(
				[
					'status'         => 'completed',
					'transaction_id' => $transaction->id,
				],
				200
			);
		}

		if ( in_array( $stripe_status, [ 'canceled', 'requires_payment_method' ], true ) ) {
			$transaction->status = 'failed';
			$transaction->save();

			return new WP_Error(
				'payment_failed',
				__( 'The payment was not successful.', 'mission-donation-platform' ),
				[ 'status' => 402 ]
			);
		}

		// Stripe reports processing / requires_action / requires_confirmation — payment is
		// still in flight. Client should poll or show a processing state.
		return new WP_REST_Response(
			[
				'status'         => 'processing',
				'transaction_id' => $transaction->id,
			],
			202
		);
	}

	/**
	 * Transition a pending transaction to completed and store card metadata.
	 *
	 * @param Transaction          $transaction  Transaction to complete.
	 * @param array<string, mixed> $verification Verifier response payload.
	 * @return void
	 */
	private function complete_transaction( Transaction $transaction, array $verification ): void {
		$transaction->status         = 'completed';
		$transaction->date_completed = current_time( 'mysql', true );
		$transaction->save();

		$payment_method = $verification['payment_method'] ?? [];
		$brand          = (string) ( $payment_method['brand'] ?? '' );
		$last4          = (string) ( $payment_method['last4'] ?? '' );

		if ( $brand ) {
			$transaction->update_meta( 'payment_method_brand', $brand );
		}
		if ( $last4 ) {
			$transaction->update_meta( 'payment_method_last4', $last4 );
		}
	}
}
