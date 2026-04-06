<?php
/**
 * Handler for customer.subscription.deleted webhook events.
 *
 * @package Mission
 */

namespace Mission\Webhooks;

use Mission\Models\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Handles subscription deletion events from Stripe.
 */
class SubscriptionDeletedHandler {

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

		// Idempotency: skip if already cancelled.
		if ( 'cancelled' === $subscription->status ) {
			return;
		}

		$subscription->status         = 'cancelled';
		$subscription->date_cancelled = current_time( 'mysql', true );
		$subscription->save();
	}
}
