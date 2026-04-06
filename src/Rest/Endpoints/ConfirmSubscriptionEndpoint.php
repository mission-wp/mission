<?php
/**
 * REST endpoint for confirming a subscription after Stripe payment.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Models\Subscription;
use Mission\Models\Transaction;
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
	 * Confirm the initial subscription payment.
	 *
	 * Transitions the transaction to completed and activates the subscription.
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
				__( 'Transaction not found.', 'mission' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'pending' !== $transaction->status ) {
			return new WP_Error(
				'transaction_already_processed',
				__( 'This transaction has already been processed.', 'mission' ),
				[ 'status' => 409 ]
			);
		}

		if ( ! hash_equals( $transaction->gateway_transaction_id, $request->get_param( 'payment_intent_id' ) ) ) {
			return new WP_Error(
				'transaction_mismatch',
				__( 'Payment intent does not match this transaction.', 'mission' ),
				[ 'status' => 403 ]
			);
		}

		$subscription = Subscription::find( $request->get_param( 'subscription_id' ) );

		if ( ! $subscription ) {
			return new WP_Error(
				'subscription_not_found',
				__( 'Subscription not found.', 'mission' ),
				[ 'status' => 404 ]
			);
		}

		// Complete the transaction.
		$transaction->status         = 'completed';
		$transaction->date_completed = current_time( 'mysql', true );
		$transaction->save();

		// Activate the subscription.
		$subscription->activate( $transaction->id );

		return new WP_REST_Response(
			[
				'success'         => true,
				'transaction_id'  => $transaction->id,
				'subscription_id' => $subscription->id,
			],
			200
		);
	}
}
