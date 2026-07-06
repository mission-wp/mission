<?php
/**
 * Tests for the TeamEmailListener.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Email;

use MissionDP\Database\DatabaseModule;
use MissionDP\Email\EmailModule;
use MissionDP\Email\TeamEmailListener;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\TeamInvitation;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * TeamEmailListener test class.
 */
class TeamEmailListenerTest extends WP_UnitTestCase {

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Default to live mode for each test.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( SettingsService::OPTION_NAME, [ 'test_mode' => false ] );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_team_invitations" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
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
	 * Create a team with a captain who has an email.
	 *
	 * @return Team
	 */
	private function create_team_with_captain(): Team {
		$campaign = new Campaign( [ 'title' => 'P2P', 'type' => 'p2p' ] );
		$campaign->save();

		$donor = new Donor( [ 'email' => 'cap@example.com', 'first_name' => 'Cap', 'last_name' => 'Tain' ] );
		$donor->save();

		$team    = Team::register( $campaign->id, 'Runners', 100000 );
		$captain = new Fundraiser(
			[
				'campaign_id' => $campaign->id,
				'donor_id'    => $donor->id,
				'team_id'     => $team->id,
			]
		);
		$captain->save();
		$team->set_captain( $captain );

		return $team;
	}

	/**
	 * Test the invitee is emailed and the invitation is stamped sent.
	 */
	public function test_invitation_emails_invitee_and_stamps_sent_at(): void {
		$team   = $this->create_team_with_captain();
		$invite = new TeamInvitation( [ 'team_id' => $team->id, 'email' => 'invitee@example.com', 'token' => 'tok123' ] );
		$invite->save();

		$email    = $this->stub_email_module();
		$listener = new TeamEmailListener();
		$listener->init( $email );
		$listener->on_invitation_created( $invite );

		$this->assertCount( 1, $email->sent );
		$this->assertSame( 'invitee@example.com', $email->sent[0]['to'] );
		$this->assertSame( "You're invited to join Runners", $email->sent[0]['subject'] );
		// The accept URL, carrying the invitation token, appears in the body.
		$this->assertStringContainsString( 'team_invite=tok123', $email->sent[0]['body'] );
		$this->assertNotNull( TeamInvitation::find( $invite->id )->sent_at );
	}

	/**
	 * Test the invitation email is skipped in test mode.
	 */
	public function test_invitation_sends_in_test_mode(): void {
		// Team lifecycle emails are human-initiated and independent of payment
		// mode; test mode must not silently break the invite flow.
		update_option( SettingsService::OPTION_NAME, [ 'test_mode' => true ] );

		$team   = $this->create_team_with_captain();
		$invite = new TeamInvitation( [ 'team_id' => $team->id, 'email' => 'invitee@example.com', 'token' => 'tok123' ] );
		$invite->save();

		$email    = $this->stub_email_module();
		$listener = new TeamEmailListener();
		$listener->init( $email );
		$listener->on_invitation_created( $invite );

		$this->assertCount( 1, $email->sent );
		$this->assertNotNull( TeamInvitation::find( $invite->id )->sent_at );
	}

	/**
	 * Test invitations to a pending team are held until approval, then flushed.
	 */
	public function test_invitation_held_while_team_pending_and_flushed_on_approval(): void {
		$team = $this->create_team_with_captain();
		$team->status = Team::STATUS_PENDING;
		$team->save();

		$invite = new TeamInvitation( [ 'team_id' => $team->id, 'email' => 'invitee@example.com', 'token' => 'tok123' ] );
		$invite->save();

		$email    = $this->stub_email_module();
		$listener = new TeamEmailListener();
		$listener->init( $email );

		// A pending team's page 404s, so the invite is held (no email, no stamp).
		$listener->on_invitation_created( $invite );
		$this->assertCount( 0, $email->sent );
		$this->assertNull( TeamInvitation::find( $invite->id )->sent_at );

		// The flush is wired to team approval and sends the held invitation.
		$this->assertNotFalse( has_action( 'mission_team_approved', [ $listener, 'flush_pending_invitations' ] ) );

		$team->status = Team::STATUS_ACTIVE;
		$team->save();
		$listener->flush_pending_invitations( $team );

		$this->assertContains( 'invitee@example.com', array_column( $email->sent, 'to' ) );
		$this->assertNotNull( TeamInvitation::find( $invite->id )->sent_at );
	}

	/**
	 * Test the invitation email respects the disabled setting.
	 */
	public function test_invitation_respects_disabled_setting(): void {
		$team   = $this->create_team_with_captain();
		$invite = new TeamInvitation( [ 'team_id' => $team->id, 'email' => 'invitee@example.com', 'token' => 'tok123' ] );
		$invite->save();

		$email    = $this->stub_email_module( false );
		$listener = new TeamEmailListener();
		$listener->init( $email );
		$listener->on_invitation_created( $invite );

		$this->assertCount( 0, $email->sent );
	}

	/**
	 * Test the captain is emailed when a new member joins.
	 */
	public function test_member_joined_emails_captain(): void {
		$team = $this->create_team_with_captain();

		$donor = new Donor( [ 'email' => 'newbie@example.com', 'first_name' => 'New', 'last_name' => 'Bie' ] );
		$donor->save();
		$member = new Fundraiser( [ 'campaign_id' => $team->campaign_id, 'donor_id' => $donor->id, 'team_id' => $team->id ] );
		$member->save();

		$email    = $this->stub_email_module();
		$listener = new TeamEmailListener();
		$listener->init( $email );
		$listener->on_member_joined( $member, $team );

		$this->assertCount( 1, $email->sent );
		$this->assertSame( 'cap@example.com', $email->sent[0]['to'] );
		$this->assertSame( 'A new member joined Runners', $email->sent[0]['subject'] );
		$this->assertStringContainsString( 'New Bie', $email->sent[0]['body'] );
	}

	/**
	 * Test no email when the captain "joins" their own team at creation.
	 */
	public function test_member_joined_skips_captain_self(): void {
		$team    = $this->create_team_with_captain();
		$captain = $team->captain();

		$email    = $this->stub_email_module();
		$listener = new TeamEmailListener();
		$listener->init( $email );
		$listener->on_member_joined( $captain, $team );

		$this->assertCount( 0, $email->sent );
	}

	/**
	 * Test the captain is emailed when the team is approved.
	 */
	public function test_team_approved_emails_captain(): void {
		$team = $this->create_team_with_captain();

		$email    = $this->stub_email_module();
		$listener = new TeamEmailListener();
		$listener->init( $email );
		$listener->on_team_approved( $team );

		$this->assertCount( 1, $email->sent );
		$this->assertSame( 'cap@example.com', $email->sent[0]['to'] );
		$this->assertSame( 'Your team Runners has been approved', $email->sent[0]['subject'] );
	}

	/**
	 * Test a custom template subject has its merge tags replaced, not sent literally.
	 */
	public function test_custom_subject_replaces_merge_tags(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[
				'test_mode' => false,
				'emails'    => [
					'p2p_team_approved' => [
						'subject' => '{captain_name}, your {team_name} page is ready',
					],
				],
			]
		);

		$team = $this->create_team_with_captain();

		$email    = $this->stub_email_module();
		$listener = new TeamEmailListener();
		$listener->init( $email );
		$listener->on_team_approved( $team );

		$this->assertCount( 1, $email->sent );
		$this->assertSame( 'Cap, your Runners page is ready', $email->sent[0]['subject'] );
		$this->assertStringNotContainsString( '{team_name}', $email->sent[0]['subject'] );
	}

	/**
	 * Test init() wires the real WordPress actions with the right arg counts.
	 *
	 * Fires do_action() with the production hook names/args instead of calling
	 * the on_*() handlers directly, so a wrong hook name or arg count in init()
	 * cannot pass unnoticed.
	 */
	public function test_init_wires_real_actions(): void {
		$team = $this->create_team_with_captain();

		$donor = new Donor( [ 'email' => 'newbie@example.com', 'first_name' => 'New', 'last_name' => 'Bie' ] );
		$donor->save();
		$member = new Fundraiser( [ 'campaign_id' => $team->campaign_id, 'donor_id' => $donor->id, 'team_id' => $team->id ] );
		$member->save();

		$email    = $this->stub_email_module();
		$listener = new TeamEmailListener();
		$listener->init( $email );

		// mission_team_joined passes two args (fundraiser, team).
		do_action( 'mission_team_joined', $member, $team );
		$this->assertCount( 1, $email->sent );
		$this->assertSame( 'cap@example.com', $email->sent[0]['to'] );
		$this->assertSame( 'A new member joined Runners', $email->sent[0]['subject'] );

		do_action( 'mission_team_approved', $team );
		$this->assertCount( 2, $email->sent );
		$this->assertSame( 'Your team Runners has been approved', $email->sent[1]['subject'] );
	}
}
