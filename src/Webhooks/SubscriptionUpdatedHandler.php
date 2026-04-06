<?php
/**
 * Handler for customer.subscription.updated webhook events.
 *
 * @package Mission
 */

namespace Mission\Webhooks;

use Mission\Models\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs subscription status changes from Stripe.
 */
class SubscriptionUpdatedHandler {

	/**
	 * Status mapping from Stripe to local statuses.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = [
		'active'   => 'active',
		'past_due' => 'past_due',
		'unpaid'   => 'past_due',
		'canceled' => 'cancelled',
		'paused'   => 'paused',
	];

	/**
	 * Handle the event.
	 *
	 * @param array<string, mixed> $data Event data from the Mission API.
	 * @return void
	 */
	public function handle( array $data ): void {
		$stripe_subscription_id = $data['subscription_id'] ?? '';
		$stripe_status          = $data['status'] ?? '';

		if ( ! $stripe_subscription_id || ! $stripe_status ) {
			return;
		}

		$local_status = self::STATUS_MAP[ $stripe_status ] ?? null;

		if ( ! $local_status ) {
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

		// Skip if already in this status.
		if ( $subscription->status === $local_status ) {
			return;
		}

		$subscription->status = $local_status;

		if ( 'cancelled' === $local_status && ! $subscription->date_cancelled ) {
			$subscription->date_cancelled = current_time( 'mysql', true );
		}

		$subscription->save();
	}
}
