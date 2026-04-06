<?php
/**
 * Cron reconciler for subscription status.
 *
 * Safety net for missed webhooks — checks active subscriptions that haven't
 * renewed on time against Stripe via the Mission API.
 *
 * @package Mission
 */

namespace Mission\Subscriptions;

use Mission\Models\Subscription;
use Mission\Plugin;
use Mission\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Subscription reconciler class.
 */
class SubscriptionReconciler {

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'mission_check_recurring_payments';

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.missionwp.com';

	/**
	 * Number of days past due before a subscription is considered stale.
	 *
	 * @var int
	 */
	private const STALE_THRESHOLD_DAYS = 2;

	/**
	 * Initialize the reconciler.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::CRON_HOOK, [ $this, 'reconcile' ] );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Run the reconciliation process.
	 *
	 * Finds active subscriptions where date_next_renewal is more than
	 * STALE_THRESHOLD_DAYS in the past, then checks Stripe for their status.
	 *
	 * @return void
	 */
	public function reconcile(): void {
		$settings   = new SettingsService();
		$site_token = $settings->get( 'stripe_site_token' );

		if ( ! $site_token ) {
			return;
		}

		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( self::STALE_THRESHOLD_DAYS * DAY_IN_SECONDS ) );

		$stale_subscriptions = Subscription::query(
			[
				'status'                   => 'active',
				'date_next_renewal_before' => $threshold,
				'per_page'                 => 50,
			]
		);

		if ( empty( $stale_subscriptions ) ) {
			return;
		}

		foreach ( $stale_subscriptions as $subscription ) {
			$this->check_subscription( $subscription, $site_token );
		}
	}

	/**
	 * Check a single subscription against Stripe.
	 *
	 * @param Subscription $subscription The subscription to check.
	 * @param string       $site_token   API auth token.
	 * @return void
	 */
	private function check_subscription( Subscription $subscription, string $site_token ): void {
		if ( ! $subscription->gateway_subscription_id ) {
			return;
		}

		$response = wp_remote_get(
			self::API_BASE . '/subscription-status?' . http_build_query(
				[
					'subscription_id' => $subscription->gateway_subscription_id,
					'test_mode'       => $subscription->is_test,
				]
			),
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $site_token,
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['status'] ) ) {
			return;
		}

		$stripe_status = $body['status'];
		$status_map    = [
			'canceled' => 'cancelled',
			'past_due' => 'past_due',
			'unpaid'   => 'past_due',
			'paused'   => 'paused',
		];

		$new_status = $status_map[ $stripe_status ] ?? null;

		if ( ! $new_status ) {
			return;
		}

		$subscription->status = $new_status;

		if ( 'cancelled' === $new_status ) {
			$subscription->date_cancelled = current_time( 'mysql', true );
		}

		$subscription->save();
	}
}
