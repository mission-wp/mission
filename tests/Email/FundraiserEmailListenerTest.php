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
			 * @var array<array{to: string, subject: string}>
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
			 * Skip template rendering.
			 *
			 * @param string $template Template name.
			 * @param array  $data     Template data.
			 * @return string
			 */
			public function render_template( string $template, array $data = [] ): string {
				return '<html>' . $template . '</html>';
			}

			/**
			 * Record the send.
			 *
			 * @param string $to      Recipient.
			 * @param string $subject Subject.
			 * @param string $message Body.
			 * @param array  $headers Headers.
			 * @return bool
			 */
			public function send( string $to, string $subject, string $message, array $headers = [] ): bool {
				$this->sent[] = [ 'to' => $to, 'subject' => $subject ];
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

		$this->assertCount( 1, $email->sent );
		$this->assertSame( 'owner@example.com', $email->sent[0]['to'] );
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
}
