<?php
/**
 * Handler for payment_intent.succeeded webhook events.
 *
 * Owns the authoritative transition of pending donation transactions to
 * completed once Stripe confirms payment. Also activates the associated
 * subscription for initial subscription payments, and stores card metadata
 * (brand, last 4) for display in the admin and donor dashboard.
 *
 * @package Mission
 */

namespace Mission\Webhooks;

use Mission\Models\Subscription;
use Mission\Models\Transaction;

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

		// Find the transaction created by the corresponding create-payment-intent
		// or create-subscription request.
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

		// Complete the transaction if still pending. Idempotent — webhook may
		// be redelivered; subsequent deliveries see a non-pending status and
		// skip the transition. `save()` fires the status transition hooks
		// that update donor/campaign aggregates and send receipt emails.
		if ( 'pending' === $transaction->status ) {
			$transaction->status         = 'completed';
			$transaction->date_completed = current_time( 'mysql', true );
			$transaction->save();
		}

		// Activate the subscription if this was the initial payment for one.
		if ( $transaction->subscription_id ) {
			$subscription = Subscription::find( $transaction->subscription_id );

			if ( $subscription && 'pending' === $subscription->status ) {
				$subscription->activate( $transaction->id );
			}
		}

		// Store card metadata on the transaction and (if applicable) on the
		// subscription so the donor dashboard can display "Visa ending in 4242"
		// rather than "Stripe".
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
