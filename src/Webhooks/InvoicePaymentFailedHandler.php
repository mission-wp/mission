<?php
/**
 * Handler for invoice.payment_failed webhook events.
 *
 * @package Mission
 */

namespace Mission\Webhooks;

use Mission\Models\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Handles failed invoice payments (subscription renewal failures).
 */
class InvoicePaymentFailedHandler {

	/**
	 * Handle the event.
	 *
	 * @param array<string, mixed> $data Event data from the Mission API.
	 * @return void
	 */
	public function handle( array $data ): void {
		$stripe_subscription_id = $data['subscription_id'] ?? '';

		if ( ! $stripe_subscription_id ) {
			return;
		}

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

		// Mark past_due immediately so the local status doesn't depend on
		// a separate customer.subscription.updated event arriving in time.
		if ( 'active' === $subscription->status ) {
			$subscription->status = 'past_due';
			$subscription->save();
		}

		/**
		 * Fires when a subscription renewal payment fails.
		 *
		 * @param Subscription $subscription The subscription that failed to renew.
		 * @param array        $data         Webhook event data.
		 */
		do_action( 'mission_subscription_payment_failed', $subscription, $data );
	}
}
