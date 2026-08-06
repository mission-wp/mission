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
use MissionDP\Rest\Args;
use MissionDP\Rest\RestErrors;
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
		private PaymentIntentVerifier $verifier,
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
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'transaction_id'    => Args::integer( [ 'required' => true ] ),
					'payment_intent_id' => Args::string( [ 'required' => true ] ),
				],
			]
		);
	}

	/**
	 * Permission check for the confirm endpoint.
	 *
	 * The endpoint must accept unauthenticated requests because donors aren't
	 * logged in when the donation form posts here. Authorization comes from
	 * the caller proving they originated the PaymentIntent: the submitted
	 * `payment_intent_id` must match the value Stripe returned when the
	 * pending transaction was created. Without that pairing, the request
	 * cannot transition the transaction.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ): bool|WP_Error {
		$transaction = Transaction::find( $request->get_param( 'transaction_id' ) );

		if ( ! $transaction ) {
			return RestErrors::transaction_not_found();
		}

		$payment_intent_id = (string) $request->get_param( 'payment_intent_id' );

		if ( ! hash_equals( (string) $transaction->gateway_transaction_id, $payment_intent_id ) ) {
			return new WP_Error(
				'transaction_mismatch',
				__( 'Payment intent does not match this transaction.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return true;
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

		// Existence + payment_intent_id match are validated in check_permission().
		$transaction       = Transaction::find( $request->get_param( 'transaction_id' ) );
		$payment_intent_id = (string) $request->get_param( 'payment_intent_id' );

		// Happy path: the webhook arrived before the client's confirm call.
		if ( Transaction::STATUS_COMPLETED === $transaction->status ) {
			return new WP_REST_Response(
				[
					'status'         => 'completed',
					'transaction_id' => $transaction->id,
				],
				200
			);
		}

		if ( in_array( $transaction->status, [ Transaction::STATUS_FAILED, Transaction::STATUS_CANCELLED ], true ) ) {
			return new WP_Error(
				'payment_failed',
				__( 'The payment was not successful.', 'mission-donation-platform' ),
				[ 'status' => 402 ]
			);
		}

		$verification = $this->verifier->verify(
			$payment_intent_id,
			(bool) $transaction->is_test,
			(string) $transaction->get_meta( 'stripe_account_id' )
		);

		if ( ! $verification['verified'] ) {
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
			$transaction->status = Transaction::STATUS_FAILED;
			$transaction->save();

			return new WP_Error(
				'payment_failed',
				__( 'The payment was not successful.', 'mission-donation-platform' ),
				[ 'status' => 402 ]
			);
		}

		// Remaining Stripe statuses (processing, requires_action, requires_confirmation)
		// mean the payment is still in flight; the client should poll.
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
		$transaction->status         = Transaction::STATUS_COMPLETED;
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
