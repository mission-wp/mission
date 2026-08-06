<?php
/**
 * Handler for payment_intent.succeeded webhook events.
 *
 * Owns the authoritative transition of pending donation transactions to
 * completed once Stripe confirms payment. Also activates the associated
 * subscription for initial subscription payments, and stores card metadata
 * (brand, last 4) for display in the admin and donor dashboard.
 *
 * @package MissionDP
 */

namespace MissionDP\Webhooks;

use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Handles payment_intent.succeeded events.
 */
class PaymentIntentSucceededHandler {

	/**
	 * Handle the event.
	 *
	 * @param array<string, mixed> $data Event data from the Mission API.
	 * @return void
	 */
	public function handle( array $data ): void {
		$payment_intent_id = $data['payment_intent_id'] ?? '';

		if ( ! $payment_intent_id ) {
			return;
		}

		$transactions = Transaction::query(
			[
				'gateway_transaction_id' => $payment_intent_id,
				'per_page'               => 1,
			]
		);

		if ( empty( $transactions ) ) {
			return;
		}

		$transaction = $transactions[0];

		// Idempotent: webhooks can be redelivered, so only a pending status transitions.
		if ( Transaction::STATUS_PENDING === $transaction->status ) {
			$transaction->status         = Transaction::STATUS_COMPLETED;
			$transaction->date_completed = current_time( 'mysql', true );
			$transaction->save();
		}

		if ( $transaction->subscription_id ) {
			$subscription = Subscription::find( $transaction->subscription_id );

			if ( $subscription && Subscription::STATUS_PENDING === $subscription->status ) {
				$subscription->activate( $transaction->id );
			}
		}

		$payment_method = $data['payment_method'] ?? [];
		$brand          = $payment_method['brand'] ?? '';
		$last4          = $payment_method['last4'] ?? '';

		if ( $brand ) {
			$transaction->update_meta( 'payment_method_brand', $brand );
		}
		if ( $last4 ) {
			$transaction->update_meta( 'payment_method_last4', $last4 );
		}

		if ( $transaction->subscription_id && ( $brand || $last4 ) ) {
			$subscription = $subscription ?? Subscription::find( $transaction->subscription_id );

			if ( $subscription ) {
				if ( $brand ) {
					$subscription->update_meta( 'payment_method_brand', $brand );
				}
				if ( $last4 ) {
					$subscription->update_meta( 'payment_method_last4', $last4 );
				}
			}
		}
	}
}
