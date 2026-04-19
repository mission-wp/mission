<?php
/**
 * REST endpoint for confirming a subscription's initial payment after Stripe payment.
 *
 * Called by the donation form after Stripe.js confirms the initial payment
 * client-side. This endpoint synchronously verifies the PaymentIntent status
 * with Stripe (via the Mission API) and, when Stripe confirms success,
 * transitions the pending transaction to completed and activates the pending
 * subscription. Falls back to webhook authority if the Mission API is
 * unavailable.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Models\Subscription;
use Mission\Models\Transaction;
use Mission\Payments\PaymentIntentVerifier;
use Mission\Rest\RestModule;
use Mission\Rest\Traits\RateLimitTrait;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ConfirmSubscription endpoint class.
 */
class ConfirmSubscriptionEndpoint {

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
			'/donations/confirm-subscription',
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
					'subscription_id'   => [
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
	 * Confirm a subscription's initial payment.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'confirm_subscription', 10, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		$transaction = Transaction::find( $request->get_param( 'transaction_id' ) );

		if ( ! $transaction ) {
			return new WP_Error(
				'transaction_not_found',
				__( 'Transaction not found.', 'missionwp-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$payment_intent_id = $request->get_param( 'payment_intent_id' );
		$subscription_id   = (int) $request->get_param( 'subscription_id' );

		if ( ! hash_equals( $transaction->gateway_transaction_id, $payment_intent_id ) ) {
			return new WP_Error(
				'transaction_mismatch',
				__( 'Payment intent does not match this transaction.', 'missionwp-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		// The submitted subscription_id must match the transaction's own
		// subscription_id. Prevents a caller from pairing a valid (transaction,
		// payment_intent) tuple with an arbitrary subscription ID.
		if ( (int) $transaction->subscription_id !== $subscription_id ) {
			return new WP_Error(
				'subscription_mismatch',
				__( 'Subscription does not match this transaction.', 'missionwp-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		// Happy path: the webhook arrived before the client's confirm call.
		if ( 'completed' === $transaction->status ) {
			return new WP_REST_Response(
				[
					'status'          => 'completed',
					'transaction_id'  => $transaction->id,
					'subscription_id' => $subscription_id,
				],
				200
			);
		}

		if ( in_array( $transaction->status, [ 'failed', 'cancelled' ], true ) ) {
			return new WP_Error(
				'payment_failed',
				__( 'The payment was not successful.', 'missionwp-donation-platform' ),
				[ 'status' => 402 ]
			);
		}

		// Transaction is still pending — verify with Stripe synchronously.
		$verification = $this->verifier->verify( $payment_intent_id, (bool) $transaction->is_test );

		if ( ! $verification['verified'] ) {
			return new WP_REST_Response(
				[
					'status'          => 'processing',
					'transaction_id'  => $transaction->id,
					'subscription_id' => $subscription_id,
				],
				202
			);
		}

		$stripe_status = $verification['stripe_status'];

		if ( 'succeeded' === $stripe_status ) {
			$this->complete_transaction_and_activate( $transaction, $verification );

			return new WP_REST_Response(
				[
					'status'          => 'completed',
					'transaction_id'  => $transaction->id,
					'subscription_id' => $subscription_id,
				],
				200
			);
		}

		if ( in_array( $stripe_status, [ 'canceled', 'requires_payment_method' ], true ) ) {
			$transaction->status = 'failed';
			$transaction->save();

			return new WP_Error(
				'payment_failed',
				__( 'The payment was not successful.', 'missionwp-donation-platform' ),
				[ 'status' => 402 ]
			);
		}

		// Stripe reports processing / requires_action / requires_confirmation — payment is
		// still in flight. Client should poll or show a processing state.
		return new WP_REST_Response(
			[
				'status'          => 'processing',
				'transaction_id'  => $transaction->id,
				'subscription_id' => $subscription_id,
			],
			202
		);
	}

	/**
	 * Complete the initial transaction, activate the subscription, and store card metadata.
	 *
	 * @param Transaction          $transaction  Initial subscription transaction.
	 * @param array<string, mixed> $verification Verifier response payload.
	 * @return void
	 */
	private function complete_transaction_and_activate( Transaction $transaction, array $verification ): void {
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

		$subscription = Subscription::find( $transaction->subscription_id );

		if ( $subscription && 'pending' === $subscription->status ) {
			$subscription->activate( $transaction->id );

			if ( $brand ) {
				$subscription->update_meta( 'payment_method_brand', $brand );
			}
			if ( $last4 ) {
				$subscription->update_meta( 'payment_method_last4', $last4 );
			}
		}
	}
}
