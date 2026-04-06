<?php
/**
 * Handler for payment_intent.succeeded webhook events.
 *
 * Stores the card brand and last 4 digits on the transaction (and subscription
 * if applicable) so "Visa ending in 4242" can be displayed instead of "Stripe".
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

		$payment_method = $data['payment_method'] ?? [];
		$brand          = $payment_method['brand'] ?? '';
		$last4          = $payment_method['last4'] ?? '';

		if ( ! $brand && ! $last4 ) {
			return;
		}

		// Find the transaction by gateway_transaction_id.
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

		// Store card details on the transaction.
		if ( $brand ) {
			$transaction->update_meta( 'payment_method_brand', $brand );
		}
		if ( $last4 ) {
			$transaction->update_meta( 'payment_method_last4', $last4 );
		}

		// If this transaction belongs to a subscription, store card details there
		// too so the donor dashboard can display the payment method on load.
		if ( $transaction->subscription_id ) {
			$subscription = Subscription::find( $transaction->subscription_id );

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
