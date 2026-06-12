<?php
/**
 * Tests for the DonationEmailListener.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Email;

use MissionDP\Database\DatabaseModule;
use MissionDP\Email\DonationEmailListener;
use MissionDP\Email\EmailModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Note;
use MissionDP\Models\Transaction;
use MissionDP\Models\Tribute;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * Donation email listener test class.
 *
 * Fixtures use save_silent() so that creating rows never triggers the
 * globally registered listeners; each test then either calls a handler
 * directly or fires the real action for wiring coverage.
 */
class DonationEmailListenerTest extends WP_UnitTestCase {

	/**
	 * Listener under test.
	 *
	 * @var DonationEmailListener
	 */
	private DonationEmailListener $listener;

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

		$this->listener = new DonationEmailListener();
		$this->listener->init( $email );
	}

	/**
	 * Clean up tables and settings after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_tributes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_notes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
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
	 * Create a tribute without firing creation hooks.
	 *
	 * The tributes table has a unique key on transaction_id, so each tribute
	 * gets its own backing transaction unless one is provided.
	 *
	 * @param array<string, mixed> $overrides Column values to override.
	 * @return Tribute
	 */
	private function create_tribute( array $overrides = [] ): Tribute {
		if ( ! isset( $overrides['transaction_id'] ) ) {
			$overrides['transaction_id'] = $this->create_transaction()->id;
		}

		$tribute = new Tribute( array_merge(
			[
				'tribute_type'  => 'in_honor',
				'honoree_name'  => 'Grandma Rose',
				'notify_method' => 'email',
				'notify_email'  => 'family@example.com',
				'message'       => 'With love',
			],
			$overrides
		) );

		$tribute->save_silent();

		return $tribute;
	}

	/**
	 * Get all captured emails.
	 *
	 * @return array<int, object>
	 */
	private function sent_emails(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	// -------------------------------------------------------------------------
	// on_donation_completed() guard tests.
	// -------------------------------------------------------------------------

	/**
	 * Test recurring transactions never get a one-time receipt.
	 */
	public function test_no_receipt_for_recurring_transaction(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [
			'donor_id' => $donor->id,
			'type'     => 'monthly',
		] );

		$this->listener->on_donation_completed( $transaction );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test no receipt is sent when the transaction has no donor.
	 */
	public function test_no_receipt_when_donor_missing(): void {
		$transaction = $this->create_transaction( [ 'donor_id' => 999999 ] );

		$this->listener->on_donation_completed( $transaction );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test no receipt is sent when the donor has no email address.
	 */
	public function test_no_receipt_when_donor_has_no_email(): void {
		$donor       = $this->create_donor( [ 'email' => '' ] );
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		$this->listener->on_donation_completed( $transaction );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test no receipt is sent when the donation_receipt email type is disabled.
	 */
	public function test_no_receipt_when_email_type_disabled(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[ 'emails' => [ 'donation_receipt' => [ 'enabled' => false ] ] ]
		);

		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		$this->listener->on_donation_completed( $transaction );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test the skip_receipt transaction meta suppresses the receipt.
	 */
	public function test_no_receipt_when_skip_receipt_meta_set(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );
		$transaction->add_meta( 'skip_receipt', '1' );

		$this->listener->on_donation_completed( $transaction );

		$this->assertCount( 0, $this->sent_emails() );
	}

	// -------------------------------------------------------------------------
	// on_donation_completed() send tests.
	// -------------------------------------------------------------------------

	/**
	 * Test the happy path sends a receipt to the donor.
	 */
	public function test_receipt_sent_for_completed_one_time_donation(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		$this->listener->on_donation_completed( $transaction );

		$sent = $this->sent_emails();
		$this->assertCount( 1, $sent );
		$this->assertSame( $donor->email, $sent[0]['to'][0][0] );
		$this->assertStringContainsString( '$50.00', $sent[0]['subject'] );
		$this->assertStringContainsString( 'Hi Jane,', $sent[0]['body'] );
	}

	/**
	 * Test a custom subject has all of its merge tags replaced.
	 */
	public function test_receipt_custom_subject_tags_replaced(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[
				'org_name' => 'Test Org',
				'emails'   => [
					'donation_receipt' => [
						'subject' => '{donor_name}|{amount}|{campaign}|{date}|{organization}|{receipt_id}',
					],
				],
			]
		);

		$campaign = new Campaign( [
			'title'       => 'Clean Water',
			'goal_amount' => 100000,
		] );
		$campaign->save();

		$donor       = $this->create_donor( [ 'first_name' => 'Sam' ] );
		$transaction = $this->create_transaction( [
			'donor_id'    => $donor->id,
			'campaign_id' => $campaign->id,
		] );

		$this->listener->on_donation_completed( $transaction );

		$sent     = $this->sent_emails();
		$expected = sprintf(
			'Sam|$50.00|Clean Water|%s|Test Org|%d',
			wp_date( get_option( 'date_format' ), strtotime( '2026-06-12 10:00:00' ) ),
			$transaction->id
		);

		$this->assertCount( 1, $sent );
		$this->assertSame( $expected, $sent[0]['subject'] );
	}

	/**
	 * Test custom subject fallbacks: donor without a first name and no campaign.
	 */
	public function test_receipt_custom_subject_fallbacks(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[ 'emails' => [ 'donation_receipt' => [ 'subject' => '{donor_name}|{campaign}' ] ] ]
		);

		$donor       = $this->create_donor( [ 'first_name' => '' ] );
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		$this->listener->on_donation_completed( $transaction );

		$sent = $this->sent_emails();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'Friend|', $sent[0]['subject'] );
	}

	// -------------------------------------------------------------------------
	// on_transaction_created() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a directly-created completed transaction triggers the receipt.
	 */
	public function test_on_transaction_created_sends_receipt_when_completed(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		$this->listener->on_transaction_created( $transaction );

		$this->assertCount( 1, $this->sent_emails() );
	}

	/**
	 * Test a pending transaction does not trigger the receipt on creation.
	 */
	public function test_on_transaction_created_ignores_pending(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [
			'donor_id'       => $donor->id,
			'status'         => Transaction::STATUS_PENDING,
			'date_completed' => null,
		] );

		$this->listener->on_transaction_created( $transaction );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test the real status-transition action reaches the listener.
	 */
	public function test_status_transition_action_triggers_receipt(): void {
		// Keep the admin notification listener quiet so the assertion below
		// counts only donor-facing receipts.
		update_option(
			SettingsService::OPTION_NAME,
			[ 'emails' => [ 'admin_new_donation' => [ 'enabled' => false ] ] ]
		);

		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		do_action( 'mission_transaction_status_pending_to_completed', $transaction );

		// Both the globally registered listener and this test's instance are
		// hooked, so the donor receives the receipt twice here; what matters
		// is that every recipient is the donor.
		$sent = $this->sent_emails();
		$this->assertNotEmpty( $sent );
		foreach ( $sent as $email ) {
			$this->assertSame( $donor->email, $email['to'][0][0] );
		}
	}

	// -------------------------------------------------------------------------
	// on_donor_note_created() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a donor-visible transaction note emails the donor.
	 */
	public function test_note_email_sent_for_donor_visible_transaction_note(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		$note = new Note( [
			'object_type' => 'transaction',
			'object_id'   => $transaction->id,
			'type'        => 'donor',
			'content'     => 'Thanks for the support!',
		] );

		$this->listener->on_donor_note_created( $note );

		$sent = $this->sent_emails();
		$this->assertCount( 1, $sent );
		$this->assertSame( $donor->email, $sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'Thanks for the support!', $sent[0]['body'] );
	}

	/**
	 * Test internal notes and non-transaction notes do not email the donor.
	 */
	public function test_no_note_email_for_internal_or_non_transaction_notes(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		$internal = new Note( [
			'object_type' => 'transaction',
			'object_id'   => $transaction->id,
			'type'        => 'internal',
			'content'     => 'Internal only',
		] );

		$donor_note = new Note( [
			'object_type' => 'donor',
			'object_id'   => $donor->id,
			'type'        => 'donor',
			'content'     => 'Wrong object type',
		] );

		$this->listener->on_donor_note_created( $internal );
		$this->listener->on_donor_note_created( $donor_note );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test no note email is sent when the referenced transaction is gone.
	 */
	public function test_no_note_email_when_transaction_missing(): void {
		$note = new Note( [
			'object_type' => 'transaction',
			'object_id'   => 999999,
			'type'        => 'donor',
			'content'     => 'Orphaned note',
		] );

		$this->listener->on_donor_note_created( $note );

		$this->assertCount( 0, $this->sent_emails() );
	}

	// -------------------------------------------------------------------------
	// on_tribute_saved() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a tribute notification is sent and the tribute is marked as notified.
	 */
	public function test_tribute_notification_sent_and_marked(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );
		$tribute     = $this->create_tribute( [ 'transaction_id' => $transaction->id ] );

		$this->listener->on_tribute_saved( $tribute );

		$sent = $this->sent_emails();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'family@example.com', $sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'Grandma Rose', $sent[0]['subject'] );

		$saved = Tribute::find( $tribute->id );
		$this->assertNotNull( $saved->notification_sent_at );
	}

	/**
	 * Test an already-notified tribute never sends a second email.
	 */
	public function test_no_tribute_notification_when_already_sent(): void {
		$tribute = $this->create_tribute( [ 'notification_sent_at' => '2026-06-01 00:00:00' ] );

		$this->listener->on_tribute_saved( $tribute );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test mail-method tributes and missing notify emails are skipped.
	 */
	public function test_no_tribute_notification_for_mail_method_or_missing_email(): void {
		$mail_tribute  = $this->create_tribute( [ 'notify_method' => 'mail' ] );
		$empty_tribute = $this->create_tribute( [ 'notify_email' => '' ] );

		$this->listener->on_tribute_saved( $mail_tribute );
		$this->listener->on_tribute_saved( $empty_tribute );

		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test the real tribute hooks: create sends once, a later update does not resend.
	 */
	public function test_tribute_save_sends_once_and_update_does_not_resend(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		// A real save() fires mission_tribute_created for every registered
		// listener (the plugin's and this test's), but only the first one
		// through sends; it stamps notification_sent_at, which guards the rest.
		$tribute = new Tribute( [
			'transaction_id' => $transaction->id,
			'tribute_type'   => 'in_memory',
			'honoree_name'   => 'Grandpa Joe',
			'notify_method'  => 'email',
			'notify_email'   => 'family@example.com',
		] );
		$tribute->save();

		$this->assertCount( 1, $this->sent_emails() );

		// Updating the tribute fires mission_tribute_updated; the stamp
		// prevents a resend.
		$tribute->message = 'Updated message';
		$tribute->save();

		$this->assertCount( 1, $this->sent_emails() );
	}
}
