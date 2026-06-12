<?php
/**
 * Tests for the AdminNotificationListener.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Email;

use MissionDP\Database\DatabaseModule;
use MissionDP\Email\AdminNotificationListener;
use MissionDP\Email\AdminNotifier;
use MissionDP\Email\EmailModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Models\Tribute;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * Admin notification listener test class.
 *
 * Handlers are called directly; fixtures use save_silent() where a regular
 * save would fire creation hooks for the plugin's own listeners.
 */
class AdminNotificationListenerTest extends WP_UnitTestCase {

	/**
	 * Listener under test.
	 *
	 * @var AdminNotificationListener
	 */
	private AdminNotificationListener $listener;

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Set up a fresh listener and mailer before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		reset_phpmailer_instance();

		$email = new EmailModule();
		$email->init();

		$this->listener = new AdminNotificationListener();
		$this->listener->init( new AdminNotifier( $email, new SettingsService() ), $email );
	}

	/**
	 * Clean up tables and settings after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_tributes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		// phpcs:enable

		delete_option( SettingsService::OPTION_NAME );
		reset_phpmailer_instance();

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a donor with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Column values to override.
	 * @return Donor
	 */
	private function create_donor( array $overrides = [] ): Donor {
		static $counter = 0;
		++$counter;

		$donor = new Donor( array_merge(
			[
				'email'      => "donor{$counter}@example.com",
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
			],
			$overrides
		) );

		$donor->save();

		return $donor;
	}

	/**
	 * Create a completed one-time transaction without firing creation hooks.
	 *
	 * @param array<string, mixed> $overrides Column values to override.
	 * @return Transaction
	 */
	private function create_transaction( array $overrides = [] ): Transaction {
		$transaction = new Transaction( array_merge(
			[
				'status'         => Transaction::STATUS_COMPLETED,
				'type'           => 'one_time',
				'amount'         => 5000,
				'total_amount'   => 5000,
				'currency'       => 'usd',
				'date_completed' => '2026-06-12 10:00:00',
			],
			$overrides
		) );

		$transaction->save_silent();

		return $transaction;
	}

	/**
	 * Create an active subscription with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Column values to override.
	 * @return Subscription
	 */
	private function create_subscription( array $overrides = [] ): Subscription {
		$subscription = new Subscription( array_merge(
			[
				'status'       => Subscription::STATUS_ACTIVE,
				'amount'       => 2500,
				'total_amount' => 2500,
				'currency'     => 'usd',
				'frequency'    => 'monthly',
			],
			$overrides
		) );

		$subscription->save();

		return $subscription;
	}

	/**
	 * Get all captured emails.
	 *
	 * @return array<int, object>
	 */
	private function sent_emails(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/**
	 * Assert exactly one admin email was sent with the given subject.
	 *
	 * @param string $subject Expected subject line.
	 * @return object The sent email.
	 */
	private function assert_single_admin_email( string $subject ): object {
		$sent = $this->sent_emails();
		$this->assertCount( 1, $sent );
		$this->assertSame( get_option( 'admin_email' ), $sent[0]['to'][0][0] );
		$this->assertSame( $subject, $sent[0]['subject'] );

		return (object) $sent[0];
	}

	// -------------------------------------------------------------------------
	// Donation notification tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a completed one-time donation notifies the admin.
	 */
	public function test_donation_notification_sent(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		$this->listener->on_donation_completed( $transaction );

		$this->assert_single_admin_email( 'New donation: $50.00 from Jane Doe' );
	}

	/**
	 * Test recurring transactions do not trigger the one-time donation notification.
	 */
	public function test_no_donation_notification_for_recurring_type(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [
			'donor_id' => $donor->id,
			'type'     => 'monthly',
		] );

		$this->listener->on_donation_completed( $transaction );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test a disabled admin email type suppresses the notification.
	 */
	public function test_no_donation_notification_when_disabled(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[ 'emails' => [ 'admin_new_donation' => [ 'enabled' => false ] ] ]
		);

		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		$this->listener->on_donation_completed( $transaction );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test a first recurring donation notifies the admin.
	 */
	public function test_first_recurring_donation_notification_sent(): void {
		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );

		$this->listener->on_first_recurring_donation( $subscription );

		$this->assert_single_admin_email( 'New recurring donation: $25.00/monthly from Jane Doe' );
	}

	/**
	 * Test a subscription renewal notifies the admin with the renewal amount.
	 */
	public function test_renewal_notification_sent(): void {
		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );
		$transaction  = $this->create_transaction( [
			'donor_id'        => $donor->id,
			'subscription_id' => $subscription->id,
			'type'            => 'monthly',
			'amount'          => 2500,
			'total_amount'    => 2500,
		] );

		$this->listener->on_subscription_renewed( $subscription, $transaction );

		$this->assert_single_admin_email( 'Recurring renewal: $25.00 from Jane Doe' );
	}

	// -------------------------------------------------------------------------
	// Refund notification tests.
	// -------------------------------------------------------------------------

	/**
	 * Test partial and full refunds produce distinct subjects.
	 */
	public function test_refund_notification_partial_and_full(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [
			'donor_id'        => $donor->id,
			'amount_refunded' => 2000,
		] );

		$this->listener->on_refund_applied( $transaction, 2000 );

		$transaction->amount_refunded = 5000;
		$this->listener->on_refund_applied( $transaction, 3000 );

		$sent = $this->sent_emails();
		$this->assertCount( 2, $sent );
		$this->assertSame( 'Partial refund: $20.00 to Jane Doe', $sent[0]['subject'] );
		$this->assertSame( 'Full refund: $30.00 to Jane Doe', $sent[1]['subject'] );
	}

	// -------------------------------------------------------------------------
	// Subscription lifecycle notification tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a failed payment notifies the admin.
	 */
	public function test_payment_failed_notification_sent(): void {
		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [
			'donor_id' => $donor->id,
			'status'   => Subscription::STATUS_PAST_DUE,
		] );

		$this->listener->on_payment_failed( $subscription );

		$this->assert_single_admin_email( 'Failed payment: $25.00 from Jane Doe' );
	}

	/**
	 * Test a cancellation notifies the admin.
	 */
	public function test_cancellation_notification_sent(): void {
		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [
			'donor_id' => $donor->id,
			'status'   => Subscription::STATUS_CANCELLED,
		] );

		$this->listener->on_subscription_cancelled( $subscription );

		$this->assert_single_admin_email( 'Subscription cancelled: $25.00/monthly from Jane Doe' );
	}

	/**
	 * Test handlers bail quietly when the donor is missing.
	 */
	public function test_no_notifications_when_donor_missing(): void {
		$transaction  = $this->create_transaction( [ 'donor_id' => 999999 ] );
		$subscription = $this->create_subscription( [ 'donor_id' => 999999 ] );

		$this->listener->on_donation_completed( $transaction );
		$this->listener->on_first_recurring_donation( $subscription );
		$this->listener->on_payment_failed( $subscription );
		$this->listener->on_subscription_cancelled( $subscription );

		$this->assertCount( 0, $this->sent_emails() );
	}

	// -------------------------------------------------------------------------
	// Campaign milestone tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a reached milestone notifies the admin.
	 */
	public function test_milestone_notification_sent(): void {
		$campaign = new Campaign( [
			'title'        => 'Clean Water',
			'goal_amount'  => 100000,
			'total_raised' => 50000,
			'currency'     => 'usd',
		] );
		$campaign->save();

		$this->listener->on_campaign_milestone( $campaign, '50-pct', false );

		$this->assert_single_admin_email( 'Campaign milestone: Clean Water reached 50%' );
	}

	/**
	 * Test the created milestone and test-mode transactions are skipped.
	 */
	public function test_milestone_skips_created_and_test_mode(): void {
		$campaign = new Campaign( [
			'title'       => 'Clean Water',
			'goal_amount' => 100000,
		] );
		$campaign->save();

		$this->listener->on_campaign_milestone( $campaign, 'created', false );
		$this->listener->on_campaign_milestone( $campaign, '50-pct', true );

		$this->assertCount( 0, $this->sent_emails() );
	}

	// -------------------------------------------------------------------------
	// Mail dedication tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a mail-method tribute notifies the admin; email-method does not.
	 */
	public function test_mail_dedication_notification_sent_only_for_mail_method(): void {
		$donor = $this->create_donor();

		$email_transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );
		$email_tribute     = new Tribute( [
			'transaction_id' => $email_transaction->id,
			'honoree_name'   => 'Grandma Rose',
			'notify_method'  => 'email',
			'notify_email'   => 'family@example.com',
		] );
		$email_tribute->save_silent();

		$this->listener->on_mail_dedication( $email_tribute );
		$this->assertCount( 0, $this->sent_emails() );

		$mail_transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );
		$mail_tribute     = new Tribute( [
			'transaction_id'   => $mail_transaction->id,
			'honoree_name'     => 'Grandma Rose',
			'notify_method'    => 'mail',
			'notify_name'      => 'The Rose Family',
			'notify_address_1' => '1 Main St',
			'notify_city'      => 'Springfield',
			'notify_state'     => 'IL',
			'notify_zip'       => '62701',
		] );
		$mail_tribute->save_silent();

		$this->listener->on_mail_dedication( $mail_tribute );

		$email = $this->assert_single_admin_email( 'Mail dedication pending: Grandma Rose (from Jane Doe)' );
		$this->assertStringContainsString( '1 Main St', $email->body );
	}

	/**
	 * Test a mail tribute without a transaction is skipped.
	 */
	public function test_no_mail_dedication_notification_when_transaction_missing(): void {
		$tribute = new Tribute( [
			'transaction_id' => 999999,
			'honoree_name'   => 'Grandma Rose',
			'notify_method'  => 'mail',
		] );

		$this->listener->on_mail_dedication( $tribute );

		$this->assertCount( 0, $this->sent_emails() );
	}
}
