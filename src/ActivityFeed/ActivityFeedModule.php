<?php
/**
 * Activity feed module — wires event listeners and pruning.
 *
 * @package Mission
 */

namespace Mission\ActivityFeed;

use Mission\Models\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Activity feed module class.
 */
class ActivityFeedModule {

	/**
	 * Initialize the module.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->register_event_listeners();
		$this->register_pruning();
	}

	/**
	 * Log an activity event.
	 *
	 * @param string               $event       Event name.
	 * @param string               $object_type Object type.
	 * @param int                   $object_id   Object ID.
	 * @param array<string, mixed> $data        Event-specific data.
	 * @param bool                  $is_test     Whether this is a test-mode event.
	 * @param string               $level       Log level: 'info', 'warning', or 'error'.
	 * @param string               $category    Log category: 'payment', 'webhook', 'email', 'subscription', or 'system'.
	 *
	 * @return int The new log entry ID.
	 */
	public function log(
		string $event,
		string $object_type,
		int $object_id = 0,
		array $data = [],
		bool $is_test = false,
		string $level = 'info',
		string $category = 'system',
	): int {
		$actor_id = get_current_user_id();
		$entry    = new ActivityLog(
			[
				'event'       => $event,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'actor_id'    => $actor_id ?: null,
				'data'        => $data ? wp_json_encode( $data ) : null,
				'is_test'     => $is_test,
				'level'       => $level,
				'category'    => $category,
			]
		);

		return $entry->save();
	}

	/**
	 * Register all event listeners.
	 *
	 * @return void
	 */
	private function register_event_listeners(): void {
		// Donation completed (via status transition or created directly as completed).
		add_action( 'mission_transaction_status_pending_to_completed', [ $this, 'on_donation_completed' ] );
		add_action( 'mission_transaction_created', [ $this, 'on_transaction_created' ] );

		// Donation refunded.
		add_action( 'mission_transaction_status_completed_to_refunded', [ $this, 'on_donation_refunded' ] );

		// Subscription created.
		add_action( 'mission_subscription_created', [ $this, 'on_subscription_created' ] );

		// Subscription cancelled.
		add_action( 'mission_subscription_status_active_to_cancelled', [ $this, 'on_subscription_cancelled' ] );
		add_action( 'mission_subscription_status_pending_to_cancelled', [ $this, 'on_subscription_cancelled' ] );
		add_action( 'mission_subscription_status_paused_to_cancelled', [ $this, 'on_subscription_cancelled' ] );
		add_action( 'mission_subscription_status_past_due_to_cancelled', [ $this, 'on_subscription_cancelled' ] );

		// Subscription failed.
		add_action( 'mission_subscription_status_active_to_failed', [ $this, 'on_subscription_failed' ] );
		add_action( 'mission_subscription_status_pending_to_failed', [ $this, 'on_subscription_failed' ] );

		// Subscription amount changed.
		add_action( 'mission_subscription_amount_changed', [ $this, 'on_subscription_amount_changed' ], 10, 3 );

		// Campaign created.
		add_action( 'mission_campaign_created', [ $this, 'on_campaign_created' ] );

		// Campaign milestone reached.
		add_action( 'mission_campaign_milestone_reached', [ $this, 'on_campaign_milestone_reached' ], 10, 3 );

		// Plugin updated.
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrader_complete' ], 10, 2 );

		// Plugin deactivated.
		add_action( 'mission_plugin_deactivating', [ $this, 'on_plugin_deactivating' ] );

		// Admin notification sent.
		add_action( 'mission_admin_notification_sent', [ $this, 'on_admin_notification_sent' ], 10, 3 );

		// Payment failed.
		add_action( 'mission_transaction_status_pending_to_failed', [ $this, 'on_payment_failed' ] );

		// Webhook processed.
		add_action( 'mission_webhook_event_processed', [ $this, 'on_webhook_processed' ], 10, 3 );

		// Email sent / failed.
		add_action( 'mission_email_sent', [ $this, 'on_email_sent' ], 10, 2 );
		add_action( 'mission_email_failed', [ $this, 'on_email_failed' ], 10, 2 );

		// Settings updated.
		add_action( 'mission_settings_updated', [ $this, 'on_settings_updated' ], 10, 3 );
	}

	/**
	 * Register the pruning cron callback.
	 *
	 * @return void
	 */
	private function register_pruning(): void {
		add_action( 'mission_daily_cleanup', [ $this, 'run_prune' ] );

		// Ensure the cron is scheduled.
		add_action( 'init', [ $this, 'ensure_cron_scheduled' ] );
	}

	/**
	 * Ensure the daily cleanup cron is scheduled.
	 *
	 * @return void
	 */
	public function ensure_cron_scheduled(): void {
		if ( ! wp_next_scheduled( 'mission_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'mission_daily_cleanup' );
		}
	}

	/**
	 * Run the prune operation.
	 *
	 * @return void
	 */
	public function run_prune(): void {
		/** @var int $days Number of days to retain activity log entries. */
		$days = (int) apply_filters( 'mission_activity_log_retention_days', 90 );

		/** @var \Mission\Database\DataStore\ActivityLogDataStore $store */
		$store = ActivityLog::store();
		$store->prune( $days );
	}

	/**
	 * Handle transaction created — log if already completed.
	 *
	 * @param object $transaction Transaction model.
	 *
	 * @return void
	 */
	public function on_transaction_created( object $transaction ): void {
		if ( 'completed' === $transaction->status ) {
			$this->on_donation_completed( $transaction );
		}
	}

	/**
	 * Handle donation completed.
	 *
	 * Logs as 'recurring_donation_processed' for subscription renewals,
	 * or 'donation_completed' for one-time donations.
	 *
	 * @param object $transaction Transaction model.
	 *
	 * @return void
	 */
	public function on_donation_completed( object $transaction ): void {
		$donor    = $transaction->donor();
		$campaign = $transaction->campaign();

		$data = [
			'amount'         => $transaction->amount,
			'donor_id'       => $transaction->donor_id,
			'donor_name'     => $donor?->full_name() ?: '',
			'campaign_id'    => $transaction->campaign_id,
			'campaign_title' => $campaign?->title ?: '',
		];

		if ( $transaction->subscription_id ) {
			$subscription      = $transaction->subscription();
			$data['frequency'] = $subscription?->frequency ?: '';

			$this->log(
				'recurring_donation_processed',
				'transaction',
				$transaction->id,
				$data,
				(bool) $transaction->is_test,
				category: 'subscription',
			);
		} else {
			$this->log(
				'donation_completed',
				'transaction',
				$transaction->id,
				$data,
				(bool) $transaction->is_test,
				category: 'payment',
			);
		}
	}

	/**
	 * Handle donation refunded.
	 *
	 * @param object $transaction Transaction model.
	 *
	 * @return void
	 */
	public function on_donation_refunded( object $transaction ): void {
		$donor    = $transaction->donor();
		$campaign = $transaction->campaign();

		$this->log(
			'donation_refunded',
			'transaction',
			$transaction->id,
			[
				'amount'         => $transaction->amount,
				'donor_id'       => $transaction->donor_id,
				'donor_name'     => $donor?->full_name() ?: '',
				'campaign_id'    => $transaction->campaign_id,
				'campaign_title' => $campaign?->title ?: '',
			],
			(bool) $transaction->is_test,
			category: 'payment',
		);
	}

	/**
	 * Handle subscription created.
	 *
	 * @param object $subscription Subscription model.
	 *
	 * @return void
	 */
	public function on_subscription_created( object $subscription ): void {
		$donor    = $subscription->donor();
		$campaign = $subscription->campaign();

		$this->log(
			'subscription_created',
			'subscription',
			$subscription->id,
			[
				'amount'         => $subscription->amount,
				'frequency'      => $subscription->frequency,
				'donor_id'       => $subscription->donor_id,
				'donor_name'     => $donor?->full_name() ?: '',
				'campaign_id'    => $subscription->campaign_id,
				'campaign_title' => $campaign?->title ?: '',
			],
			(bool) $subscription->is_test,
			category: 'subscription',
		);
	}

	/**
	 * Handle subscription cancelled.
	 *
	 * @param object $subscription Subscription model.
	 *
	 * @return void
	 */
	public function on_subscription_cancelled( object $subscription ): void {
		$donor = $subscription->donor();

		$this->log(
			'subscription_cancelled',
			'subscription',
			$subscription->id,
			[
				'amount'     => $subscription->amount,
				'frequency'  => $subscription->frequency,
				'donor_id'   => $subscription->donor_id,
				'donor_name' => $donor?->full_name() ?: '',
			],
			(bool) $subscription->is_test,
			level: 'warning',
			category: 'subscription',
		);
	}

	/**
	 * Handle subscription failed.
	 *
	 * @param object $subscription Subscription model.
	 *
	 * @return void
	 */
	public function on_subscription_failed( object $subscription ): void {
		$donor = $subscription->donor();

		$this->log(
			'subscription_failed',
			'subscription',
			$subscription->id,
			[
				'amount'     => $subscription->amount,
				'frequency'  => $subscription->frequency,
				'donor_id'   => $subscription->donor_id,
				'donor_name' => $donor?->full_name() ?: '',
			],
			(bool) $subscription->is_test,
			level: 'error',
			category: 'subscription',
		);
	}

	/**
	 * Handle subscription amount changed.
	 *
	 * Logs as 'subscription_amount_increased' or 'subscription_amount_decreased'.
	 *
	 * @param object $subscription The subscription.
	 * @param int    $old_amount   Previous donation amount in minor units.
	 * @param int    $new_amount   New donation amount in minor units.
	 *
	 * @return void
	 */
	public function on_subscription_amount_changed( object $subscription, int $old_amount, int $new_amount ): void {
		$donor = $subscription->donor();
		$event = $new_amount > $old_amount
			? 'subscription_amount_increased'
			: 'subscription_amount_decreased';

		$this->log(
			$event,
			'subscription',
			$subscription->id,
			[
				'old_amount' => $old_amount,
				'new_amount' => $new_amount,
				'frequency'  => $subscription->frequency,
				'donor_id'   => $subscription->donor_id,
				'donor_name' => $donor?->full_name() ?: '',
			],
			(bool) $subscription->is_test,
			category: 'subscription',
		);
	}

	/**
	 * Handle campaign created.
	 *
	 * @param object $campaign Campaign model.
	 *
	 * @return void
	 */
	public function on_campaign_created( object $campaign ): void {
		$this->log(
			'campaign_created',
			'campaign',
			$campaign->id,
			[
				'post_id' => $campaign->post_id ?? 0,
				'title'   => $campaign->title ?? '',
			]
		);
	}

	/**
	 * Handle campaign milestone reached.
	 *
	 * Logs percentage-based milestones (25%, 50%, 75%, 100%) to the activity
	 * feed. The 100% milestone uses the 'campaign_goal_reached' event; others
	 * use 'campaign_milestone_reached'.
	 *
	 * @param object $campaign     Campaign model.
	 * @param string $milestone_id Milestone ID (e.g. 'first-donation', '25-pct', '100-pct').
	 * @param bool   $is_test      Whether from a test-mode transaction.
	 *
	 * @return void
	 */
	public function on_campaign_milestone_reached( object $campaign, string $milestone_id, bool $is_test = false ): void {
		$pct_milestones = [
			'25-pct'  => 25,
			'50-pct'  => 50,
			'75-pct'  => 75,
			'100-pct' => 100,
		];

		if ( ! isset( $pct_milestones[ $milestone_id ] ) ) {
			return;
		}

		$event = '100-pct' === $milestone_id ? 'campaign_goal_reached' : 'campaign_milestone_reached';

		$this->log(
			$event,
			'campaign',
			$campaign->id,
			[
				'title'       => $campaign->title ?? '',
				'campaign_id' => $campaign->id,
				'goal_amount' => $campaign->goal_amount,
				'goal_type'   => $campaign->goal_type ?? 'amount',
				'percentage'  => $pct_milestones[ $milestone_id ],
			],
			$is_test
		);
	}

	/**
	 * Handle plugin update via the upgrader.
	 *
	 * @param object               $upgrader   Upgrader instance.
	 * @param array<string, mixed> $hook_extra Extra data about the update.
	 *
	 * @return void
	 */
	public function on_upgrader_complete( object $upgrader, array $hook_extra ): void {
		if ( 'plugin' !== ( $hook_extra['type'] ?? '' ) || 'update' !== ( $hook_extra['action'] ?? '' ) ) {
			return;
		}

		$plugins = $hook_extra['plugins'] ?? [];

		if ( ! in_array( MISSION_BASENAME, $plugins, true ) ) {
			return;
		}

		$this->log(
			'plugin_updated',
			'settings',
			0,
			[
				'new_version' => MISSION_VERSION,
			]
		);
	}

	/**
	 * Handle plugin deactivation.
	 *
	 * @return void
	 */
	public function on_plugin_deactivating(): void {
		$this->log( 'plugin_deactivated', 'settings', level: 'warning' );
	}

	/**
	 * Log when an admin notification email is sent.
	 *
	 * @param string   $type       Notification type key (e.g. 'admin_new_donation').
	 * @param string[] $recipients Email addresses that were sent to.
	 * @param array    $data       Template data.
	 * @return void
	 */
	public function on_admin_notification_sent( string $type, array $recipients, array $data ): void {
		$object_type = 'transaction';
		$object_id   = $data['transaction']->id ?? ( $data['subscription']->id ?? 0 );

		if ( isset( $data['subscription'] ) && ! isset( $data['transaction'] ) ) {
			$object_type = 'subscription';
		}

		$this->log(
			'admin_notification_sent',
			$object_type,
			$object_id,
			[
				'notification_type' => $type,
				'recipient_count'   => count( $recipients ),
			],
			category: 'email',
		);
	}

	/**
	 * Handle payment failed.
	 *
	 * @param object $transaction Transaction model.
	 *
	 * @return void
	 */
	public function on_payment_failed( object $transaction ): void {
		$donor    = $transaction->donor();
		$campaign = $transaction->campaign();

		$this->log(
			'payment_failed',
			'transaction',
			$transaction->id,
			[
				'amount'         => $transaction->amount,
				'donor_id'       => $transaction->donor_id,
				'donor_name'     => $donor?->full_name() ?: '',
				'campaign_id'    => $transaction->campaign_id,
				'campaign_title' => $campaign?->title ?: '',
			],
			(bool) $transaction->is_test,
			level: 'error',
			category: 'payment',
		);
	}

	/**
	 * Handle Stripe webhook processed.
	 *
	 * @param string               $event_type Stripe event type (e.g. 'charge.succeeded').
	 * @param array<string, mixed> $data       Event data.
	 * @param array<string, mixed> $payload    Full event payload.
	 *
	 * @return void
	 */
	public function on_webhook_processed( string $event_type, array $data, array $payload ): void {
		$this->log(
			'webhook_received',
			'settings',
			0,
			[
				'stripe_event_type' => $event_type,
				'event_id'          => $payload['id'] ?? '',
			],
			category: 'webhook',
		);
	}

	/**
	 * Handle email sent successfully.
	 *
	 * @param string $to      Recipient email address.
	 * @param string $subject Email subject.
	 *
	 * @return void
	 */
	public function on_email_sent( string $to, string $subject ): void {
		$this->log(
			'email_sent',
			'settings',
			0,
			[
				'recipient' => $to,
				'subject'   => $subject,
			],
			category: 'email',
		);
	}

	/**
	 * Handle email send failure.
	 *
	 * @param string $to      Recipient email address.
	 * @param string $subject Email subject.
	 *
	 * @return void
	 */
	public function on_email_failed( string $to, string $subject ): void {
		$this->log(
			'email_failed',
			'settings',
			0,
			[
				'recipient' => $to,
				'subject'   => $subject,
			],
			level: 'error',
			category: 'email',
		);
	}

	/**
	 * Handle settings updated.
	 *
	 * @param array<string, mixed> $updated All settings after update.
	 * @param array<string, mixed> $values  Only the changed values.
	 *
	 * @return void
	 */
	/**
	 * Settings keys whose values should never be logged.
	 *
	 * @var string[]
	 */
	private const SENSITIVE_SETTINGS = [
		'stripe_site_token',
		'stripe_webhook_secret',
	];

	public function on_settings_updated( array $updated, array $values, array $current ): void {
		$changes = [];

		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) || $current[ $key ] !== $value ) {
				if ( in_array( $key, self::SENSITIVE_SETTINGS, true ) ) {
					$changes[ $key ] = [
						'from' => '***',
						'to'   => '***',
					];
				} else {
					$changes[ $key ] = [
						'from' => $current[ $key ] ?? null,
						'to'   => $value,
					];
				}
			}
		}

		if ( empty( $changes ) ) {
			return;
		}

		$this->log(
			'settings_updated',
			'settings',
			0,
			[
				'changed_keys' => array_keys( $changes ),
				'changes'      => $changes,
			],
		);
	}
}
