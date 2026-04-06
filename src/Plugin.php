<?php
/**
 * Main plugin class that bootstraps all modules.
 *
 * @package Mission
 */

namespace Mission;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin class.
 */
class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Database module instance.
	 *
	 * @var Database\DatabaseModule|null
	 */
	private ?Database\DatabaseModule $database_module = null;

	/**
	 * Admin module instance.
	 *
	 * @var Admin\AdminModule|null
	 */
	private ?Admin\AdminModule $admin_module = null;

	/**
	 * Blocks module instance.
	 *
	 * @var Blocks\BlocksModule|null
	 */
	private ?Blocks\BlocksModule $blocks_module = null;

	/**
	 * REST module instance.
	 *
	 * @var Rest\RestModule|null
	 */
	private ?Rest\RestModule $rest_module = null;

	/**
	 * Email module instance.
	 *
	 * @var Email\EmailModule|null
	 */
	private ?Email\EmailModule $email_module = null;

	/**
	 * Campaign post type instance.
	 *
	 * @var Campaigns\CampaignPostType|null
	 */
	private ?Campaigns\CampaignPostType $campaign_post_type = null;

	/**
	 * Campaign lifecycle module instance.
	 *
	 * @var Campaigns\CampaignLifecycleModule|null
	 */
	private ?Campaigns\CampaignLifecycleModule $campaign_lifecycle_module = null;

	/**
	 * Activity feed module instance.
	 *
	 * @var ActivityFeed\ActivityFeedModule|null
	 */
	private ?ActivityFeed\ActivityFeedModule $activity_feed_module = null;

	/**
	 * Transaction history module instance.
	 *
	 * @var TransactionHistory\TransactionHistoryModule|null
	 */
	private ?TransactionHistory\TransactionHistoryModule $transaction_history_module = null;

	/**
	 * Donor dashboard module instance.
	 *
	 * @var DonorDashboard\DonorDashboardModule|null
	 */
	private ?DonorDashboard\DonorDashboardModule $donor_dashboard_module = null;

	/**
	 * Admin notification listener instance.
	 *
	 * @var Email\AdminNotificationListener|null
	 */
	private ?Email\AdminNotificationListener $admin_notification_listener = null;


	/**
	 * Get plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the plugin and all modules.
	 *
	 * @return void
	 */
	public function init(): void {
		// Initialize database module first (needed by other modules).
		$this->database_module = new Database\DatabaseModule();
		$this->database_module->init();

		// Initialize campaign post type (must be before blocks module).
		$this->campaign_post_type = new Campaigns\CampaignPostType();
		$this->campaign_post_type->init();

		// Initialize milestone tracker for campaigns.
		$milestone_tracker = new Campaigns\MilestoneTracker();
		$milestone_tracker->init();

		// Initialize blocks module (registers custom blocks).
		$this->blocks_module = new Blocks\BlocksModule();
		$this->blocks_module->init();

		// Initialize admin module.
		$this->admin_module = new Admin\AdminModule();
		$this->admin_module->init();

		// Initialize activity feed module.
		$this->activity_feed_module = new ActivityFeed\ActivityFeedModule();
		$this->activity_feed_module->init();

		// Initialize campaign lifecycle module (status transitions and end-of-campaign actions).
		$this->campaign_lifecycle_module = new Campaigns\CampaignLifecycleModule();
		$this->campaign_lifecycle_module->init();

		// Initialize transaction history module.
		$this->transaction_history_module = new TransactionHistory\TransactionHistoryModule();
		$this->transaction_history_module->init();

		// Initialize REST module.
		$this->rest_module = new Rest\RestModule();
		$this->rest_module->init();

		// Initialize email module.
		$this->email_module = new Email\EmailModule();
		$this->email_module->init();

		// Initialize subscription email listener.
		$subscription_email_listener = new Email\SubscriptionEmailListener();
		$subscription_email_listener->init( $this->email_module );

		// Initialize donation email listener (one-time donation receipts).
		$donation_email_listener = new Email\DonationEmailListener();
		$donation_email_listener->init( $this->email_module );

		// Initialize admin notification listener.
		$admin_notifier                    = new Email\AdminNotifier( $this->email_module, new Settings\SettingsService() );
		$this->admin_notification_listener = new Email\AdminNotificationListener();
		$this->admin_notification_listener->init( $admin_notifier, $this->email_module );

		// Initialize subscription reconciler (cron safety net).
		$subscription_reconciler = new Subscriptions\SubscriptionReconciler();
		$subscription_reconciler->init();

		// Initialize donor dashboard module (donor auth, wp-admin redirect).
		$this->donor_dashboard_module = new DonorDashboard\DonorDashboardModule();
		$this->donor_dashboard_module->init();
	}

	/**
	 * Get database module instance.
	 *
	 * @return Database\DatabaseModule|null
	 */
	public function get_database_module(): ?Database\DatabaseModule {
		return $this->database_module;
	}

	/**
	 * Get admin module instance.
	 *
	 * @return Admin\AdminModule|null
	 */
	public function get_admin_module(): ?Admin\AdminModule {
		return $this->admin_module;
	}

	/**
	 * Get blocks module instance.
	 *
	 * @return Blocks\BlocksModule|null
	 */
	public function get_blocks_module(): ?Blocks\BlocksModule {
		return $this->blocks_module;
	}

	/**
	 * Get REST module instance.
	 *
	 * @return Rest\RestModule|null
	 */
	public function get_rest_module(): ?Rest\RestModule {
		return $this->rest_module;
	}

	/**
	 * Get activity feed module instance.
	 *
	 * @return ActivityFeed\ActivityFeedModule|null
	 */
	public function get_activity_feed_module(): ?ActivityFeed\ActivityFeedModule {
		return $this->activity_feed_module;
	}

	/**
	 * Get email module instance.
	 *
	 * @return Email\EmailModule|null
	 */
	public function get_email_module(): ?Email\EmailModule {
		return $this->email_module;
	}

	/**
	 * Get transaction history module instance.
	 *
	 * @return TransactionHistory\TransactionHistoryModule|null
	 */
	public function get_transaction_history_module(): ?TransactionHistory\TransactionHistoryModule {
		return $this->transaction_history_module;
	}

	/**
	 * Get admin notification listener instance.
	 *
	 * @return Email\AdminNotificationListener|null
	 */
	public function get_admin_notification_listener(): ?Email\AdminNotificationListener {
		return $this->admin_notification_listener;
	}
}
