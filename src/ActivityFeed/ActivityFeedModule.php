<?php
/**
 * Activity feed module — wires event listeners and pruning.
 *
 * @package MissionDP
 */

namespace MissionDP\ActivityFeed;

use MissionDP\Models\ActivityLog;
use MissionDP\Models\Transaction;

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

		// Stripe account fallback (form requested a disconnected account, used default instead).
		add_action( 'mission_stripe_account_fallback', [ $this, 'on_stripe_account_fallback' ], 10, 2 );

		// Outgoing webhooks.
		add_action( 'mission_outgoing_webhook_created', [ $this, 'on_outgoing_webhook_created' ] );
		add_action( 'mission_outgoing_webhook_deleted', [ $this, 'on_outgoing_webhook_deleted' ], 10, 2 );
		add_action( 'mission_outgoing_webhook_auto_paused', [ $this, 'on_outgoing_webhook_auto_paused' ] );

		// Migration runs.
		add_action( 'mission_migration_completed', [ $this, 'on_migration_completed' ], 10, 2 );
		add_action( 'mission_migration_rolled_back', [ $this, 'on_migration_rolled_back' ], 10, 2 );
		add_action( 'mission_migration_failed', [ $this, 'on_migration_failed' ], 10, 3 );
	}

	/**
	 * Get the current user's display name for activity log data.
	 *
	 * @return string Empty string when there is no logged-in user.
	 */
	private function get_actor_name(): string {
		$user = get_userdata( get_current_user_id() );

		return $user ? ( $user->display_name ?: $user->user_login ) : '';
	}

	/**
	 * Resolve a user's display name for activity data (migration ticks run in
	 * Action Scheduler workers, where there is no current user).
	 *
	 * @param int $user_id WP user ID recorded on the job.
	 *
	 * @return string Empty string when the user no longer exists.
	 */
	private function get_user_name( int $user_id ): string {
		$user = get_userdata( $user_id );

		return $user ? ( $user->display_name ?: $user->user_login ) : '';
	}

	/**
	 * Log when a migration run completes.
	 *
	 * @param string   $job_id Job token.
	 * @param object[] $phases MigrationPhase rows.
	 *
	 * @return void
	 */
	public function on_migration_completed( string $job_id, array $phases ): void {
		if ( empty( $phases ) ) {
			return;
		}

		$counts = [];
		$errors = 0;
		foreach ( $phases as $phase ) {
			$counts[ $phase->entity ] = $phase->imported;
			$errors                  += $phase->errors;
		}

		$this->log(
			'data_migrated',
			'migration',
			0,
			[
				'source'     => $phases[0]->source,
				'counts'     => $counts,
				'errors'     => $errors,
				'job_id'     => $job_id,
				'actor_name' => $this->get_user_name( $phases[0]->user_id ),
			],
			false,
			$errors > 0 ? 'warning' : 'info',
		);
	}

	/**
	 * Log when a migration rollback completes.
	 *
	 * @param string $job_id Rollback job token.
	 * @param object $phase  Final MigrationPhase row.
	 *
	 * @return void
	 */
	public function on_migration_rolled_back( string $job_id, object $phase ): void {
		$this->log(
			'migration_rolled_back',
			'migration',
			0,
			[
				'source'     => $phase->source,
				'job_id'     => $job_id,
				'actor_name' => $this->get_user_name( $phase->user_id ),
			],
		);
	}

	/**
	 * Log when a migration run fails.
	 *
	 * @param string $job_id Job token.
	 * @param object $phase  The failed MigrationPhase row.
	 * @param string $reason Failure reason.
	 *
	 * @return void
	 */
	public function on_migration_failed( string $job_id, object $phase, string $reason ): void {
		$this->log(
			'migration_failed',
			'migration',
			0,
			[
				'source'     => $phase->source,
				'entity'     => $phase->entity,
				'reason'     => $reason,
				'job_id'     => $job_id,
				'actor_name' => $this->get_user_name( $phase->user_id ),
			],
			false,
			'error',
		);
	}

	/**
	 * Log when an outgoing webhook is created.
	 *
	 * @param object $webhook OutgoingWebhook model.
	 *
	 * @return void
	 */
	public function on_outgoing_webhook_created( object $webhook ): void {
		$this->log(
			'outgoing_webhook_created',
			'webhook',
			$webhook->id,
			[
				'name'       => $webhook->name,
				'url'        => $webhook->url,
				'actor_name' => $this->get_actor_name(),
			],
			category: 'webhook',
		);
	}

	/**
	 * Log when an outgoing webhook is deleted.
	 *
	 * @param int         $webhook_id Deleted webhook ID.
	 * @param object|null $webhook    The webhook as it was before deletion.
	 *
	 * @return void
	 */
	public function on_outgoing_webhook_deleted( int $webhook_id, ?object $webhook = null ): void {
		$this->log(
			'outgoing_webhook_deleted',
			'webhook',
			$webhook_id,
			[
				'name'       => $webhook->name ?? '',
				'url'        => $webhook->url ?? '',
				'actor_name' => $this->get_actor_name(),
			],
			category: 'webhook',
		);
	}

	/**
	 * Log when an outgoing webhook is auto-paused after prolonged failures.
	 *
	 * @param object $webhook OutgoingWebhook model.
	 *
	 * @return void
	 */
	public function on_outgoing_webhook_auto_paused( object $webhook ): void {
		$this->log(
			'outgoing_webhook_auto_paused',
			'webhook',
			$webhook->id,
			[
				'name'          => $webhook->name,
				'url'           => $webhook->url,
				'failure_count' => $webhook->failure_count,
				'failing_since' => $webhook->failing_since,
			],
			level: 'warning',
			category: 'webhook',
		);
	}

	/**
	 * Log a fallback to the default Stripe account when a form-selected account is unavailable.
	 *
	 * @param string $requested_account_id Account ID the form asked for.
	 * @param string $used_account_id      Account ID that was actually used (default).
	 * @return void
	 */
	public function on_stripe_account_fallback( string $requested_account_id, string $used_account_id ): void {
		$this->log(
			'stripe_account_fallback',
			'settings',
			0,
			[
				'requested_account_id' => $requested_account_id,
				'used_account_id'      => $used_account_id,
			]
		);
	}

	/**
	 * Register the pruning cron callback.
	 *
	 * @return void
	 */
	private function register_pruning(): void {
		add_action( 'missiondp_daily_cleanup', [ $this, 'run_prune' ] );

		// Ensure the cron is scheduled.
		add_action( 'init', [ $this, 'ensure_cron_scheduled' ] );
	}

	/**
	 * Ensure the daily cleanup cron is scheduled.
	 *
	 * @return void
	 */
	public function ensure_cron_scheduled(): void {
		if ( ! wp_next_scheduled( 'missiondp_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'missiondp_daily_cleanup' );
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

		/** @var \MissionDP\Database\DataStore\ActivityLogDataStore $store */
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
		if ( Transaction::STATUS_COMPLETED === $transaction->status ) {
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

		if ( ! in_array( MISSIONDP_BASENAME, $plugins, true ) ) {
			return;
		}

		// Read the version from the freshly-updated plugin file on disk.
		// MISSIONDP_VERSION still reflects the old code loaded into memory at request start.
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . MISSIONDP_BASENAME, false, false );
		$new_version = $plugin_data['Version'] ?? MISSIONDP_VERSION;

		$this->log(
			'plugin_updated',
			'settings',
			0,
			[
				'new_version' => $new_version,
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
		'stripe_accounts',
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
