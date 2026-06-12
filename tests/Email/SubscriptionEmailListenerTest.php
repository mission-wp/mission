<?php
/**
 * Tests for the SubscriptionEmailListener.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Email;

use MissionDP\Database\DatabaseModule;
use MissionDP\Email\EmailModule;
use MissionDP\Email\SubscriptionEmailListener;
use MissionDP\Models\Donor;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * Subscription email listener test class.
 *
 * Handlers are called directly on a test-owned listener instance; creating
 * subscriptions with their final status fires no transition hooks, so the
 * mailer only captures what each test triggers.
 */
class SubscriptionEmailListenerTest extends WP_UnitTestCase {

	/**
	 * Listener under test.
	 *
	 * @var SubscriptionEmailListener
	 */
	private SubscriptionEmailListener $listener;

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

		$this->listener = new SubscriptionEmailListener();
		$this->listener->init( $email );
	}

	/**
	 * Clean up tables and settings after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptions" );
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
				'email'      => "subscriber{$counter}@example.com",
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
			],
			$overrides
		) );

		$donor->save();

		return $donor;
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
				'status'            => Subscription::STATUS_ACTIVE,
				'amount'            => 2500,
				'total_amount'      => 2500,
				'currency'          => 'usd',
				'frequency'         => 'monthly',
				'date_next_renewal' => '2026-07-12 00:00:00',
			],
			$overrides
		) );

		$subscription->save();

		return $subscription;
	}

	/**
	 * Create a completed renewal transaction without firing creation hooks.
	 *
	 * @param array<string, mixed> $overrides Column values to override.
	 * @return Transaction
	 */
	private function create_renewal_transaction( array $overrides = [] ): Transaction {
		$transaction = new Transaction( array_merge(
			[
				'status'         => Transaction::STATUS_COMPLETED,
				'type'           => 'monthly',
				'amount'         => 2500,
				'total_amount'   => 2500,
				'currency'       => 'usd',
				'date_completed' => '2026-06-12 10:00:00',
			],
			$overrides
		) );

		$transaction->save_silent();

		return $transaction;
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
	 * Disable a single email type in settings.
	 *
	 * @param string $email_type Email type key.
	 * @return void
	 */
	private function disable_email( string $email_type ): void {
		update_option(
			SettingsService::OPTION_NAME,
			[ 'emails' => [ $email_type => [ 'enabled' => false ] ] ]
		);
	}

	// -------------------------------------------------------------------------
	// Happy path tests.
	// -------------------------------------------------------------------------

	/**
	 * Test the activation email is sent with amount and frequency in the subject.
	 */
	public function test_activation_email_sent(): void {
		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );

		$this->listener->on_subscription_activated( $subscription );

		$sent = $this->sent_emails();
		$this->assertCount( 1, $sent );
		$this->assertSame( $donor->email, $sent[0]['to'][0][0] );
		$this->assertSame( 'Thank you for your $25.00 monthly donation', $sent[0]['subject'] );
	}

	/**
	 * Test the renewal receipt includes the renewal transaction's amount.
	 */
	public function test_renewal_email_sent(): void {
		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );
		$transaction  = $this->create_renewal_transaction( [
			'donor_id'        => $donor->id,
			'subscription_id' => $subscription->id,
		] );

		$this->listener->on_subscription_renewed( $subscription, $transaction );

		$sent = $this->sent_emails();
		$this->assertCount( 1, $sent );
		$this->assertSame( $donor->email, $sent[0]['to'][0][0] );
		$this->assertSame( 'Thank you for your monthly gift of $25.00', $sent[0]['subject'] );
	}

	/**
	 * Test the payment failure notice is sent.
	 */
	public function test_payment_failed_email_sent(): void {
		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [
			'donor_id' => $donor->id,
			'status'   => Subscription::STATUS_PAST_DUE,
		] );

		$this->listener->on_payment_failed( $subscription );

		$sent = $this->sent_emails();
		$this->assertCount( 1, $sent );
		$this->assertSame( $donor->email, $sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'Action needed', $sent[0]['subject'] );
	}

	/**
	 * Test the cancellation notice is sent.
	 */
	public function test_cancellation_email_sent(): void {
		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [
			'donor_id' => $donor->id,
			'status'   => Subscription::STATUS_CANCELLED,
		] );

		$this->listener->on_subscription_cancelled( $subscription );

		$sent = $this->sent_emails();
		$this->assertCount( 1, $sent );
		$this->assertSame( $donor->email, $sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'recurring donation has ended', $sent[0]['subject'] );
	}

	// -------------------------------------------------------------------------
	// Guard tests.
	// -------------------------------------------------------------------------

	/**
	 * Test no handler sends when the donor has no email address.
	 */
	public function test_no_emails_when_donor_has_no_email(): void {
		$donor        = $this->create_donor( [ 'email' => '' ] );
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );
		$transaction  = $this->create_renewal_transaction( [ 'donor_id' => $donor->id ] );

		$this->listener->on_subscription_activated( $subscription );
		$this->listener->on_subscription_renewed( $subscription, $transaction );
		$this->listener->on_payment_failed( $subscription );
		$this->listener->on_subscription_cancelled( $subscription );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test no handler sends when the subscription has no donor.
	 */
	public function test_no_emails_when_donor_missing(): void {
		$subscription = $this->create_subscription( [ 'donor_id' => 999999 ] );
		$transaction  = $this->create_renewal_transaction();

		$this->listener->on_subscription_activated( $subscription );
		$this->listener->on_subscription_renewed( $subscription, $transaction );
		$this->listener->on_payment_failed( $subscription );
		$this->listener->on_subscription_cancelled( $subscription );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test the activation email respects its enabled setting.
	 */
	public function test_activation_not_sent_when_disabled(): void {
		$this->disable_email( 'subscription_activated' );

		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );

		$this->listener->on_subscription_activated( $subscription );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test the renewal receipt respects its enabled setting.
	 */
	public function test_renewal_not_sent_when_disabled(): void {
		$this->disable_email( 'renewal_receipt' );

		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );
		$transaction  = $this->create_renewal_transaction( [ 'donor_id' => $donor->id ] );

		$this->listener->on_subscription_renewed( $subscription, $transaction );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test the payment failure notice respects its enabled setting.
	 */
	public function test_payment_failed_not_sent_when_disabled(): void {
		$this->disable_email( 'payment_failed' );

		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );

		$this->listener->on_payment_failed( $subscription );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test the cancellation notice respects its enabled setting.
	 */
	public function test_cancellation_not_sent_when_disabled(): void {
		$this->disable_email( 'subscription_cancelled' );

		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );

		$this->listener->on_subscription_cancelled( $subscription );

		$this->assertCount( 0, $this->sent_emails() );
	}

	// -------------------------------------------------------------------------
	// Custom subject tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a custom activation subject resolves all subscription tags.
	 */
	public function test_custom_subject_tags_for_activation(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[
				'org_name' => 'Test Org',
				'emails'   => [
					'subscription_activated' => [
						'subject' => '{donor_name}|{amount}|{frequency}|{next_renewal_date}|{organization}',
					],
				],
			]
		);

		$donor        = $this->create_donor( [ 'first_name' => 'Sam' ] );
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );

		$this->listener->on_subscription_activated( $subscription );

		$sent     = $this->sent_emails();
		$expected = sprintf(
			'Sam|$25.00|Monthly|%s|Test Org',
			wp_date( get_option( 'date_format' ), strtotime( '2026-07-12 00:00:00' ) )
		);

		$this->assertCount( 1, $sent );
		$this->assertSame( $expected, $sent[0]['subject'] );
	}

	/**
	 * Test a custom renewal subject also resolves the transaction date and receipt ID.
	 */
	public function test_custom_subject_renewal_includes_date_and_receipt_id(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[ 'emails' => [ 'renewal_receipt' => [ 'subject' => '{date}|{receipt_id}' ] ] ]
		);

		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );
		$transaction  = $this->create_renewal_transaction( [
			'donor_id'        => $donor->id,
			'subscription_id' => $subscription->id,
		] );

		$this->listener->on_subscription_renewed( $subscription, $transaction );

		$sent     = $this->sent_emails();
		$expected = sprintf(
			'%s|%d',
			wp_date( get_option( 'date_format' ), strtotime( '2026-06-12 10:00:00' ) ),
			$transaction->id
		);

		$this->assertCount( 1, $sent );
		$this->assertSame( $expected, $sent[0]['subject'] );
	}

	// -------------------------------------------------------------------------
	// Wiring test.
	// -------------------------------------------------------------------------

	/**
	 * Test a real pending-to-active status transition reaches the listener.
	 */
	public function test_status_transition_to_active_sends_activation_email(): void {
		// Keep the admin notification listener quiet so every captured email
		// is donor-facing.
		update_option(
			SettingsService::OPTION_NAME,
			[ 'emails' => [ 'admin_new_donation' => [ 'enabled' => false ] ] ]
		);

		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [
			'donor_id' => $donor->id,
			'status'   => Subscription::STATUS_PENDING,
		] );

		$subscription->status = Subscription::STATUS_ACTIVE;
		$subscription->save();

		// The plugin's listener and this test's instance are both hooked, so
		// the donor is emailed twice; the wiring is what matters here.
		$sent = $this->sent_emails();
		$this->assertNotEmpty( $sent );
		foreach ( $sent as $email ) {
			$this->assertSame( $donor->email, $email['to'][0][0] );
		}
	}
}
