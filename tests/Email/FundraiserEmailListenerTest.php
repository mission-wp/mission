<?php
/**
 * Tests for the FundraiserEmailListener.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Email;

use MissionDP\Database\DatabaseModule;
use MissionDP\Email\EmailModule;
use MissionDP\Email\FundraiserEmailListener;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Transaction;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * FundraiserEmailListener test class.
 */
class FundraiserEmailListenerTest extends WP_UnitTestCase {

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Build an EmailModule stub that records send() calls.
	 *
	 * @param bool $enabled Value is_email_enabled() should report.
	 * @return EmailModule
	 */
	private function stub_email_module( bool $enabled = true ): EmailModule {
		return new class( $enabled ) extends EmailModule {
			/**
			 * Captured send() calls.
			 *
			 * @var array<array{to: string, subject: string, body: string}>
			 */
			public array $sent = [];

			/**
			 * Configured enabled state.
			 *
			 * @var bool
			 */
			private bool $enabled;

			/**
			 * Constructor.
			 *
			 * @param bool $enabled Whether emails report as enabled.
			 */
			public function __construct( bool $enabled ) {
				$this->enabled = $enabled;
				$this->init();
			}

			/**
			 * Report the configured enabled state.
			 *
			 * @param string $email_type Email type key.
			 * @return bool
			 */
			public function is_email_enabled( string $email_type ): bool {
				return $this->enabled;
			}

			/**
			 * Record the send. Templates render for real so bodies can be asserted.
			 *
			 * @param string $to      Recipient.
			 * @param string $subject Subject.
			 * @param string $message Body.
			 * @param array  $headers Headers.
			 * @return bool
			 */
			public function send( string $to, string $subject, string $message, array $headers = [] ): bool {
				$this->sent[] = [ 'to' => $to, 'subject' => $subject, 'body' => $message ];
				return true;
			}
		};
	}

	/**
	 * Create a fundraiser owned by a donor with an email.
	 *
	 * @return Fundraiser
	 */
	private function create_fundraiser(): Fundraiser {
		$campaign = new Campaign( [ 'title' => 'P2P', 'type' => 'p2p' ] );
		$campaign->save();

		$donor = new Donor( [ 'email' => 'owner@example.com', 'first_name' => 'Owen', 'last_name' => 'Er' ] );
		$donor->save();

		return Fundraiser::register( $campaign->id, $donor->id, 50000 );
	}

	/**
	 * Test the participant is emailed when their fundraiser is approved.
	 */
	public function test_approved_emails_participant(): void {
		$fundraiser = $this->create_fundraiser();
		$email      = $this->stub_email_module();

		$listener = new FundraiserEmailListener();
		$listener->init( $email );
		$listener->on_fundraiser_approved( $fundraiser );

		$this->assertCount( 1, $email->sent );
		$this->assertSame( 'owner@example.com', $email->sent[0]['to'] );
		$this->assertSame( 'Your fundraising page is live', $email->sent[0]['subject'] );
	}

	/**
	 * Test no approval email when the type is disabled.
	 */
	public function test_approved_respects_disabled_setting(): void {
		$fundraiser = $this->create_fundraiser();
		$email      = $this->stub_email_module( false );

		$listener = new FundraiserEmailListener();
		$listener->init( $email );
		$listener->on_fundraiser_approved( $fundraiser );

		$this->assertCount( 0, $email->sent );
	}

	/**
	 * Test the participant is emailed when a gift is credited to their page.
	 */
	public function test_received_donation_emails_owner(): void {
		$fundraiser = $this->create_fundraiser();

		$transaction = new Transaction(
			[
				'donor_id'      => $fundraiser->donor_id,
				'campaign_id'   => $fundraiser->campaign_id,
				'fundraiser_id' => $fundraiser->id,
				'amount'        => 5000,
				'currency'      => 'USD',
				'status'        => Transaction::STATUS_COMPLETED,
			]
		);
		$transaction->save();

		$email    = $this->stub_email_module();
		$listener = new FundraiserEmailListener();
		$listener->init( $email );
		$listener->on_donation_completed( $transaction );

		$amount = $email->format_amount( 5000, 'USD' );

		$this->assertCount( 1, $email->sent );
		$this->assertSame( 'owner@example.com', $email->sent[0]['to'] );
		$this->assertSame( "You received a {$amount} donation!", $email->sent[0]['subject'] );
		// The donation amount and the giver's name appear in the body.
		$this->assertStringContainsString( $amount, $email->sent[0]['body'] );
		$this->assertStringContainsString( 'Owen Er', $email->sent[0]['body'] );
	}

	/**
	 * Test a test-mode gift never congratulates the fundraiser.
	 */
	public function test_received_donation_skips_test_gifts(): void {
		$fundraiser = $this->create_fundraiser();

		$transaction = new Transaction(
			[
				'donor_id'      => $fundraiser->donor_id,
				'campaign_id'   => $fundraiser->campaign_id,
				'fundraiser_id' => $fundraiser->id,
				'amount'        => 5000,
				'currency'      => 'USD',
				'status'        => Transaction::STATUS_COMPLETED,
				'is_test'       => true,
			]
		);
		$transaction->save();

		$email    = $this->stub_email_module();
		$listener = new FundraiserEmailListener();
		$listener->init( $email );
		$listener->on_donation_completed( $transaction );

		$this->assertCount( 0, $email->sent );
	}

	/**
	 * Test a gift with no fundraiser attribution sends nothing.
	 */
	public function test_received_donation_skips_without_fundraiser(): void {
		$donor = new Donor( [ 'email' => 'plain@example.com' ] );
		$donor->save();

		$transaction = new Transaction(
			[
				'donor_id' => $donor->id,
				'amount'   => 5000,
				'currency' => 'USD',
				'status'   => Transaction::STATUS_COMPLETED,
			]
		);
		$transaction->save();

		$email    = $this->stub_email_module();
		$listener = new FundraiserEmailListener();
		$listener->init( $email );
		$listener->on_donation_completed( $transaction );

		$this->assertCount( 0, $email->sent );
	}

	/**
	 * Test the participant is emailed when they reach a milestone.
	 */
	public function test_milestone_emails_participant(): void {
		$fundraiser = $this->create_fundraiser();
		$email      = $this->stub_email_module();

		$listener = new FundraiserEmailListener();
		$listener->init( $email );
		$listener->on_fundraiser_milestone( $fundraiser, '50-pct', false );

		$this->assertCount( 1, $email->sent );
		$this->assertSame( 'owner@example.com', $email->sent[0]['to'] );
		$this->assertSame( "You've reached 50% of your goal!", $email->sent[0]['subject'] );
		// The milestone label and the goal amount appear in the body.
		$this->assertStringContainsString( 'reached 50% of your goal', $email->sent[0]['body'] );
		$this->assertStringContainsString( $email->format_amount( 50000, 'USD' ), $email->sent[0]['body'] );
	}

	/**
	 * Test milestone emails are skipped in test mode.
	 */
	public function test_milestone_skips_test_mode(): void {
		$fundraiser = $this->create_fundraiser();
		$email      = $this->stub_email_module();

		$listener = new FundraiserEmailListener();
		$listener->init( $email );
		$listener->on_fundraiser_milestone( $fundraiser, '50-pct', true );

		$this->assertCount( 0, $email->sent );
	}

	/**
	 * Test an unrecognized milestone ID sends nothing.
	 */
	public function test_milestone_skips_unknown_id(): void {
		$fundraiser = $this->create_fundraiser();
		$email      = $this->stub_email_module();

		$listener = new FundraiserEmailListener();
		$listener->init( $email );
		$listener->on_fundraiser_milestone( $fundraiser, '10-pct', false );

		$this->assertCount( 0, $email->sent );
	}

	/**
	 * Test the email-levels filter trims which milestones send email.
	 */
	public function test_milestone_email_levels_filterable(): void {
		add_filter( 'mission_fundraiser_milestone_email_levels', static fn() => [ 50, 100 ] );

		$fundraiser = $this->create_fundraiser();
		$email      = $this->stub_email_module();

		$listener = new FundraiserEmailListener();
		$listener->init( $email );
		$listener->on_fundraiser_milestone( $fundraiser, '25-pct', false );
		$listener->on_fundraiser_milestone( $fundraiser, '50-pct', false );

		remove_all_filters( 'mission_fundraiser_milestone_email_levels' );

		$this->assertCount( 1, $email->sent );
		$this->assertSame( "You've reached 50% of your goal!", $email->sent[0]['subject'] );
	}

	/**
	 * Test a custom template subject has its merge tags replaced, not sent literally.
	 */
	public function test_custom_subject_replaces_merge_tags(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[
				'emails' => [
					'p2p_fundraiser_received_donation' => [
						'subject' => '{giver_name} gave {amount} to {donor_name}',
					],
				],
			]
		);

		$fundraiser = $this->create_fundraiser();

		$giver = new Donor( [ 'email' => 'giver@example.com', 'first_name' => 'Gia', 'last_name' => 'Ver' ] );
		$giver->save();

		$transaction = new Transaction(
			[
				'donor_id'      => $giver->id,
				'campaign_id'   => $fundraiser->campaign_id,
				'fundraiser_id' => $fundraiser->id,
				'amount'        => 5000,
				'currency'      => 'USD',
				'status'        => Transaction::STATUS_COMPLETED,
			]
		);
		$transaction->save();

		$email    = $this->stub_email_module();
		$listener = new FundraiserEmailListener();
		$listener->init( $email );
		$listener->on_donation_completed( $transaction );

		$amount = $email->format_amount( 5000, 'USD' );

		$this->assertCount( 1, $email->sent );
		$this->assertSame( "Gia Ver gave {$amount} to Owen", $email->sent[0]['subject'] );
		$this->assertStringNotContainsString( '{giver_name}', $email->sent[0]['subject'] );
	}

	/**
	 * Test init() wires the real WordPress actions with the right arg counts.
	 *
	 * Fires do_action() with the production hook names/args instead of calling
	 * the on_*() handlers directly, so a wrong hook name or arg count in init()
	 * cannot pass unnoticed.
	 */
	public function test_init_wires_real_actions(): void {
		$fundraiser = $this->create_fundraiser();
		$email      = $this->stub_email_module();

		$listener = new FundraiserEmailListener();
		$listener->init( $email );

		do_action( 'mission_fundraiser_approved', $fundraiser );
		$this->assertCount( 1, $email->sent );
		$this->assertSame( 'Your fundraising page is live', $email->sent[0]['subject'] );

		do_action( 'mission_fundraiser_milestone_reached', $fundraiser, '25-pct', false );
		$this->assertCount( 2, $email->sent );
		$this->assertSame( "You've reached 25% of your goal!", $email->sent[1]['subject'] );

		// The is_test arg must reach the handler; a test-mode milestone sends nothing.
		do_action( 'mission_fundraiser_milestone_reached', $fundraiser, '50-pct', true );
		$this->assertCount( 2, $email->sent );
	}
}
