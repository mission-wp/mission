<?php
/**
 * Handler for invoice.payment_succeeded webhook events.
 *
 * @package MissionDP
 */

namespace MissionDP\Webhooks;

use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Handles successful invoice payments (subscription renewals).
 */
class InvoicePaymentSucceededHandler {

	/**
	 * Handle the event.
	 *
	 * @param array<string, mixed> $data Event data from the Mission API.
	 * @return void
	 */
	public function handle( array $data ): void {
		$stripe_subscription_id = $data['subscription_id'] ?? '';
		$payment_intent_id      = $data['payment_intent_id'] ?? '';

		if ( ! $stripe_subscription_id || ! $payment_intent_id ) {
			return;
		}

		// Skip the first invoice — it's already handled by ConfirmSubscriptionEndpoint.
		$billing_reason = $data['billing_reason'] ?? '';
		if ( 'subscription_create' === $billing_reason ) {
			return;
		}

		// Idempotency: check if we already recorded a transaction for this PaymentIntent.
		$existing = Transaction::query(
			[
				'gateway_transaction_id' => $payment_intent_id,
				'per_page'               => 1,
			]
		);

		if ( ! empty( $existing ) ) {
			return;
		}

		// Find the subscription.
		$subscriptions = Subscription::query(
			[
				'gateway_subscription_id' => $stripe_subscription_id,
				'per_page'                => 1,
			]
		);

		if ( empty( $subscriptions ) ) {
			return;
		}

		$subscription = $subscriptions[0];

		$transaction = $subscription->record_renewal(
			[
				'gateway_transaction_id'  => $payment_intent_id,
				'gateway_subscription_id' => $stripe_subscription_id,
				'gateway_customer_id'     => $data['customer_id'] ?? $subscription->gateway_customer_id,
			]
		);

		// Store card details on the renewal transaction if provided.
		$payment_method = $data['payment_method'] ?? [];
		$brand          = $payment_method['brand'] ?? '';
		$last4          = $payment_method['last4'] ?? '';

		if ( $brand ) {
			$transaction->update_meta( 'payment_method_brand', $brand );
		}
		if ( $last4 ) {
			$transaction->update_meta( 'payment_method_last4', $last4 );
		}

		// Keep subscription-level card details current.
		if ( $brand || $last4 ) {
			if ( $brand ) {
				$subscription->update_meta( 'payment_method_brand', $brand );
			}
			if ( $last4 ) {
				$subscription->update_meta( 'payment_method_last4', $last4 );
			}
		}
	}
}
