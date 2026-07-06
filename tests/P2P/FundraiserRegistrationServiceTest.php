<?php
/**
 * Tests for the FundraiserRegistrationService.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\P2P;

use MissionDP\Database\DatabaseModule;
use MissionDP\DonorDashboard\DonorAuthService;
use MissionDP\DonorDashboard\OtpException;
use MissionDP\DonorDashboard\OtpService;
use MissionDP\Email\EmailModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\P2P\FundraiserRegistrationService;
use WP_UnitTestCase;

/**
 * FundraiserRegistrationService test class.
 */
class FundraiserRegistrationServiceTest extends WP_UnitTestCase {

	/**
	 * Most recent OTP code captured from a "sent" email.
	 *
	 * @var string
	 */
	private string $last_code = '';

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

		wp_set_current_user( 0 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisermeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_team_invitations" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Build a service whose email module captures the generated code.
	 *
	 * @return FundraiserRegistrationService
	 */
	private function service(): FundraiserRegistrationService {
		$test = $this;

		$email = new class( $test ) extends EmailModule {
			/**
			 * Parent test.
			 *
			 * @var FundraiserRegistrationServiceTest
			 */
			private FundraiserRegistrationServiceTest $test;

			/**
			 * Constructor.
			 *
			 * @param FundraiserRegistrationServiceTest $test Parent test.
			 */
			public function __construct( FundraiserRegistrationServiceTest $test ) {
				$this->test = $test;
				$this->init();
			}

			/**
			 * Capture the code instead of rendering.
			 *
			 * @param string $template Template name.
			 * @param array  $data     Template data.
			 * @return string
			 */
			public function render_template( string $template, array $data = [] ): string {
				if ( isset( $data['code'] ) ) {
					$this->test->capture_code( (string) $data['code'] );
				}
				return '<html></html>';
			}

			/**
			 * Swallow the send.
			 *
			 * @param string $to      Recipient.
			 * @param string $subject Subject.
			 * @param string $message Body.
			 * @param array  $headers Headers.
			 * @return bool
			 */
			public function send( string $to, string $subject, string $message, array $headers = [] ): bool {
				return true;
			}
		};

		$auth = new DonorAuthService( $email );

		return new FundraiserRegistrationService( $auth, new OtpService( $email ) );
	}

	/**
	 * Record a captured code (called by the stub email module).
	 *
	 * @param string $code The code.
	 * @return void
	 */
	public function capture_code( string $code ): void {
		$this->last_code = $code;
	}

	/**
	 * Create a P2P campaign with the given settings.
	 *
	 * @param array<string, mixed> $settings P2P settings overrides.
	 * @return Campaign
	 */
	private function create_campaign( array $settings = [] ): Campaign {
		$campaign = new Campaign( [ 'title' => 'Strut', 'type' => 'p2p' ] );
		$campaign->save();

		foreach ( $settings as $key => $value ) {
			$campaign->update_meta( $key, is_bool( $value ) ? ( $value ? '1' : '0' ) : $value );
		}

		return $campaign;
	}

	/**
	 * Create an existing donor with a login account.
	 *
	 * @param string $email    Email.
	 * @param string $password Password.
	 * @return Donor
	 */
	private function create_account( string $email, string $password ): Donor {
		$donor = new Donor( [ 'email' => $email, 'first_name' => 'Ex', 'last_name' => 'Ist' ] );
		$donor->save();
		$donor->create_user_account( $password );

		return $donor;
	}

	// -------------------------------------------------------------------------
	// resolve_account()
	// -------------------------------------------------------------------------

	/**
	 * Test a brand-new email requires verification and a code is sent.
	 */
	public function test_resolve_account_new_email_requires_verification(): void {
		$branch = $this->service()->resolve_account( 'new@example.com', 'longenough1' );

		$this->assertSame( FundraiserRegistrationService::BRANCH_VERIFY_REQUIRED, $branch );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', $this->last_code );
	}

	/**
	 * Test an existing account with the correct password authenticates.
	 */
	public function test_resolve_account_existing_correct_password(): void {
		$this->create_account( 'me@example.com', 'correcthorse' );
		wp_set_current_user( 0 );

		$branch = $this->service()->resolve_account( 'me@example.com', 'correcthorse' );

		$this->assertSame( FundraiserRegistrationService::BRANCH_AUTHENTICATED, $branch );
	}

	/**
	 * Test an existing account with a wrong password reports a mismatch.
	 */
	public function test_resolve_account_existing_wrong_password(): void {
		$this->create_account( 'me2@example.com', 'correcthorse' );
		wp_set_current_user( 0 );

		$branch = $this->service()->resolve_account( 'me2@example.com', 'wrongpass' );

		$this->assertSame( FundraiserRegistrationService::BRANCH_PASSWORD_MISMATCH, $branch );
		$this->assertSame( '', $this->last_code );
	}

	/**
	 * Test a weak password on a new email is rejected before any code is sent.
	 */
	public function test_resolve_account_new_email_rejects_weak_password(): void {
		try {
			$this->service()->resolve_account( 'weak@example.com', 'short' );
			$this->fail( 'Expected a RuntimeException for a weak password.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( '', $this->last_code );
		}
	}

	// -------------------------------------------------------------------------
	// complete_signup()
	// -------------------------------------------------------------------------

	/**
	 * Test a brand-new signup creates a donor, account, and session.
	 */
	public function test_complete_signup_creates_new_account(): void {
		$service = $this->service();
		$service->resolve_account( 'fresh@example.com', 'longenough1' );

		$donor = $service->complete_signup( 'fresh@example.com', $this->last_code, 'Fresh', 'Start', 'longenough1' );

		$this->assertNotEmpty( $donor->user_id );
		$this->assertSame( $donor->user_id, get_current_user_id() );
		$this->assertSame( '1', $donor->get_meta( 'email_confirmed' ) );
		$this->assertSame( 'Fresh', $donor->first_name );
	}

	/**
	 * Test signing up with an email that has a donor record links and keeps history.
	 */
	public function test_complete_signup_links_existing_donor(): void {
		$donor = new Donor( [ 'email' => 'donor@example.com', 'first_name' => 'Past', 'last_name' => 'Giver', 'total_donated' => 9000 ] );
		$donor->save();
		$donor_id = $donor->id;

		$service = $this->service();
		$service->resolve_account( 'donor@example.com', 'longenough1' );
		$linked = $service->complete_signup( 'donor@example.com', $this->last_code, '', '', 'longenough1' );

		$this->assertSame( $donor_id, $linked->id );
		$this->assertNotEmpty( $linked->user_id );
		$this->assertSame( 9000, $linked->total_donated );
	}

	/**
	 * Test a wrong code fails to complete signup.
	 */
	public function test_complete_signup_wrong_code_throws(): void {
		$service = $this->service();
		$service->resolve_account( 'badcode@example.com', 'longenough1' );

		$bad = '000000' === $this->last_code ? '111111' : '000000';

		$this->expectException( OtpException::class );
		$service->complete_signup( 'badcode@example.com', $bad, 'A', 'B', 'longenough1' );
	}

	// -------------------------------------------------------------------------
	// reset bridge
	// -------------------------------------------------------------------------

	/**
	 * Test the reset code → grant → new password path logs the donor in.
	 */
	public function test_reset_bridge_sets_new_password(): void {
		$this->create_account( 'reset@example.com', 'oldpassword1' );
		wp_set_current_user( 0 );

		$service = $this->service();
		$service->send_code( 'reset@example.com', 'reset' );
		$grant = $service->verify_reset_code( 'reset@example.com', $this->last_code );

		$donor = $service->set_password( 'reset@example.com', $grant, 'newpassword2' );

		$this->assertSame( $donor->user_id, get_current_user_id() );
		$this->assertTrue( wp_check_password( 'newpassword2', get_userdata( $donor->user_id )->user_pass, $donor->user_id ) );
	}

	/**
	 * Test set_password rejects a bad grant and leaves the password unchanged.
	 */
	public function test_set_password_rejects_bad_grant(): void {
		$donor = $this->create_account( 'reset2@example.com', 'oldpassword1' );
		wp_set_current_user( 0 );

		try {
			$this->service()->set_password( 'reset2@example.com', 'not-a-real-grant', 'newpassword2' );
			$this->fail( 'Expected a RuntimeException for a bad grant.' );
		} catch ( \RuntimeException $e ) {
			$this->assertTrue( wp_check_password( 'oldpassword1', get_userdata( $donor->user_id )->user_pass, $donor->user_id ) );
		}
	}

	/**
	 * Test no reset code is mailed for a donor linked to a privileged user, and
	 * the OTP reset path can never change that user's password.
	 */
	public function test_reset_refuses_privileged_accounts(): void {
		$donor = new Donor( [ 'email' => 'admin-donor@example.com', 'first_name' => 'Ad', 'last_name' => 'Min' ] );
		$donor->save();

		$admin_id = self::factory()->user->create( [
			'role'      => 'administrator',
			'user_pass' => 'adminpass99',
			'user_email' => 'admin-donor@example.com',
		] );
		$donor->user_id = $admin_id;
		$donor->save();
		wp_set_current_user( 0 );

		$service = $this->service();
		$service->send_code( 'admin-donor@example.com', 'reset' );

		// No code mailed: silent no-op, indistinguishable from a missing account.
		$this->assertSame( '', $this->last_code );
		$this->assertTrue( wp_check_password( 'adminpass99', get_userdata( $admin_id )->user_pass, $admin_id ) );
	}

	// -------------------------------------------------------------------------
	// register_fundraiser()
	// -------------------------------------------------------------------------

	/**
	 * Test a solo fundraiser is created active with no team.
	 */
	public function test_register_fundraiser_solo(): void {
		$campaign = $this->create_campaign();
		$donor    = new Donor( [ 'email' => 'solo@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser( $donor, $campaign, [ 'goal' => 30000, 'story' => 'Hi' ] );

		$this->assertNull( $result['team'] );
		$this->assertSame( 'active', $result['fundraiser']['status'] );
		$fundraiser = Fundraiser::find( $result['fundraiser']['id'] );
		$this->assertSame( 30000, $fundraiser->goal );
		$this->assertNull( $fundraiser->team_id );
	}

	/**
	 * Test approval_required yields a pending fundraiser.
	 */
	public function test_register_fundraiser_pending_when_approval_required(): void {
		$campaign = $this->create_campaign( [ 'approval_required' => true ] );
		$donor    = new Donor( [ 'email' => 'pending@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser( $donor, $campaign, [ 'goal' => 10000 ] );

		$this->assertSame( 'pending', $result['fundraiser']['status'] );
	}

	/**
	 * Test joining an existing public team attaches the fundraiser.
	 */
	public function test_register_fundraiser_joins_existing_team(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true ] );
		$team     = Team::register( $campaign->id, 'Runners', 100000 );
		$donor    = new Donor( [ 'email' => 'joiner@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser( $donor, $campaign, [ 'team_mode' => 'join', 'team_id' => $team->id, 'goal' => 10000 ] );

		$this->assertSame( $team->id, $result['team']['id'] );
		$this->assertSame( $team->id, Fundraiser::find( $result['fundraiser']['id'] )->team_id );
	}

	/**
	 * Test creating a team wires the circular captain foreign key.
	 */
	public function test_register_fundraiser_creates_team_with_captain(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true, 'team_creation_enabled' => true ] );
		$donor    = new Donor( [ 'email' => 'captain@example.com' ] );
		$donor->save();

		$result     = $this->service()->register_fundraiser( $donor, $campaign, [ 'team_mode' => 'create', 'team_name' => 'New Squad', 'goal' => 10000 ] );
		$fundraiser = Fundraiser::find( $result['fundraiser']['id'] );
		$team       = Team::find( $result['team']['id'] );

		$this->assertSame( 'New Squad', $team->name );
		$this->assertSame( $fundraiser->id, $team->captain_id );
		$this->assertSame( $team->id, $fundraiser->team_id );
		$this->assertTrue( $fundraiser->is_captain() );
	}

	/**
	 * Test creating a team honors the requested private access level.
	 */
	public function test_register_fundraiser_creates_private_team(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true, 'team_creation_enabled' => true ] );
		$donor    = new Donor( [ 'email' => 'private-captain@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser( $donor, $campaign, [ 'team_mode' => 'create', 'team_name' => 'Secret Squad', 'goal' => 10000, 'team_access' => 'private' ] );
		$team   = Team::find( $result['team']['id'] );

		$this->assertSame( Team::ACCESS_PRIVATE, $team->access );
	}

	/**
	 * Test an unknown team access value falls back to a public team.
	 */
	public function test_register_fundraiser_rejects_unknown_team_access(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true, 'team_creation_enabled' => true ] );
		$donor    = new Donor( [ 'email' => 'sneaky-captain@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser( $donor, $campaign, [ 'team_mode' => 'create', 'team_name' => 'Sneaky Squad', 'goal' => 10000, 'team_access' => 'sneaky' ] );
		$team   = Team::find( $result['team']['id'] );

		$this->assertSame( Team::ACCESS_PUBLIC, $team->access );
	}

	/**
	 * Test team creation errors when creation is disabled, without creating a
	 * solo page the user didn't ask for.
	 */
	public function test_register_fundraiser_create_blocked_when_disabled(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true, 'team_creation_enabled' => false ] );
		$donor    = new Donor( [ 'email' => 'noperm@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser( $donor, $campaign, [ 'team_mode' => 'create', 'team_name' => 'Nope', 'goal' => 10000 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'team_creation_disabled', $result->get_error_code() );
		$this->assertSame( 0, Fundraiser::count( [ 'campaign_id' => $campaign->id ] ) );
	}

	/**
	 * Test team creation errors without a team name.
	 */
	public function test_register_fundraiser_create_requires_name(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true, 'team_creation_enabled' => true ] );
		$donor    = new Donor( [ 'email' => 'nameless@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser( $donor, $campaign, [ 'team_mode' => 'create', 'team_name' => '  ', 'goal' => 10000 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'team_name_required', $result->get_error_code() );
		$this->assertSame( 0, Fundraiser::count( [ 'campaign_id' => $campaign->id ] ) );
	}

	/**
	 * Test joining a missing or inactive team errors instead of registering solo.
	 */
	public function test_register_fundraiser_rejects_unavailable_team(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true ] );
		$pending  = Team::register( $campaign->id, 'Unapproved', 100000, Team::ACCESS_PUBLIC, Team::STATUS_PENDING );
		$donor    = new Donor( [ 'email' => 'lost@example.com' ] );
		$donor->save();

		$service = $this->service();

		$missing = $service->register_fundraiser( $donor, $campaign, [ 'team_mode' => 'join', 'team_id' => 99999, 'goal' => 10000 ] );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 'team_unavailable', $missing->get_error_code() );

		$inactive = $service->register_fundraiser( $donor, $campaign, [ 'team_mode' => 'join', 'team_id' => $pending->id, 'goal' => 10000 ] );
		$this->assertInstanceOf( \WP_Error::class, $inactive );
		$this->assertSame( 'team_unavailable', $inactive->get_error_code() );

		$this->assertSame( 0, Fundraiser::count( [ 'campaign_id' => $campaign->id ] ) );
	}

	/**
	 * Test a join request errors when teams are disabled for the campaign.
	 */
	public function test_register_fundraiser_rejects_join_when_teams_disabled(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => false ] );
		$donor    = new Donor( [ 'email' => 'noteams@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser( $donor, $campaign, [ 'team_mode' => 'join', 'team_id' => 123, 'goal' => 10000 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'teams_disabled', $result->get_error_code() );
	}

	/**
	 * Test a duplicate registration returns the existing fundraiser.
	 */
	public function test_register_fundraiser_is_idempotent(): void {
		$campaign = $this->create_campaign();
		$donor    = new Donor( [ 'email' => 'dupe@example.com' ] );
		$donor->save();

		$service = $this->service();
		$first   = $service->register_fundraiser( $donor, $campaign, [ 'goal' => 10000 ] );
		$second  = $service->register_fundraiser( $donor, $campaign, [ 'goal' => 99999 ] );

		$this->assertSame( $first['fundraiser']['id'], $second['fundraiser']['id'] );
		$this->assertSame( 1, Fundraiser::count( [ 'campaign_id' => $campaign->id ] ) );
	}

	// -------------------------------------------------------------------------
	// Private team invitation accept flow.
	// -------------------------------------------------------------------------

	/**
	 * Test joining a private team is blocked without an invitation.
	 */
	public function test_register_fundraiser_private_team_blocked_without_invite(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true ] );
		$team     = Team::register( $campaign->id, 'Secret', 100000, Team::ACCESS_PRIVATE );
		$donor    = new Donor( [ 'email' => 'nope@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser( $donor, $campaign, [ 'team_mode' => 'join', 'team_id' => $team->id, 'goal' => 10000 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invitation_invalid', $result->get_error_code() );
		$this->assertSame( 0, Fundraiser::count( [ 'campaign_id' => $campaign->id ] ) );
	}

	/**
	 * Test a valid invitation lets the matching email join a private team.
	 */
	public function test_register_fundraiser_private_team_accepts_valid_invite(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true ] );
		$team     = Team::register( $campaign->id, 'Secret', 100000, Team::ACCESS_PRIVATE );
		$invite   = $team->invite( 'invited@example.com' );

		$donor = new Donor( [ 'email' => 'invited@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser(
			$donor,
			$campaign,
			[ 'team_mode' => 'join', 'team_id' => $team->id, 'goal' => 10000, 'invite_token' => $invite->token ]
		);

		$this->assertSame( $team->id, $result['team']['id'] );
		$this->assertSame( $team->id, Fundraiser::find( $result['fundraiser']['id'] )->team_id );
		$this->assertSame( \MissionDP\Models\TeamInvitation::STATUS_ACCEPTED, \MissionDP\Models\TeamInvitation::find( $invite->id )->status );
	}

	/**
	 * Test an invitation for a different email is refused.
	 */
	public function test_register_fundraiser_private_team_rejects_email_mismatch(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true ] );
		$team     = Team::register( $campaign->id, 'Secret', 100000, Team::ACCESS_PRIVATE );
		$invite   = $team->invite( 'someone@example.com' );

		$donor = new Donor( [ 'email' => 'imposter@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser(
			$donor,
			$campaign,
			[ 'team_mode' => 'join', 'team_id' => $team->id, 'goal' => 10000, 'invite_token' => $invite->token ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invitation_email_mismatch', $result->get_error_code() );
	}

	/**
	 * Test an invitation for a different team is refused.
	 */
	public function test_register_fundraiser_private_team_rejects_wrong_team(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true ] );
		$team     = Team::register( $campaign->id, 'Secret', 100000, Team::ACCESS_PRIVATE );
		$other    = Team::register( $campaign->id, 'Other', 100000, Team::ACCESS_PRIVATE );
		$invite   = $other->invite( 'invited@example.com' );

		$donor = new Donor( [ 'email' => 'invited@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser(
			$donor,
			$campaign,
			[ 'team_mode' => 'join', 'team_id' => $team->id, 'goal' => 10000, 'invite_token' => $invite->token ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invitation_invalid', $result->get_error_code() );
	}

	/**
	 * Test an expired invitation is refused and retired as expired.
	 */
	public function test_register_fundraiser_private_team_rejects_expired_invite(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true ] );
		$team     = Team::register( $campaign->id, 'Secret', 100000, Team::ACCESS_PRIVATE );
		$invite   = $team->invite( 'invited@example.com' );

		$invite->date_created = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$invite->save();

		$donor = new Donor( [ 'email' => 'invited@example.com' ] );
		$donor->save();

		$result = $this->service()->register_fundraiser(
			$donor,
			$campaign,
			[ 'team_mode' => 'join', 'team_id' => $team->id, 'goal' => 10000, 'invite_token' => $invite->token ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invitation_invalid', $result->get_error_code() );
		$this->assertSame( \MissionDP\Models\TeamInvitation::STATUS_EXPIRED, \MissionDP\Models\TeamInvitation::find( $invite->id )->status );
		$this->assertSame( 0, Fundraiser::count( [ 'campaign_id' => $campaign->id ] ) );
	}

	/**
	 * Test a re-submission with a valid invite token joins the team.
	 */
	public function test_register_fundraiser_resubmit_with_valid_token_joins(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true ] );
		$team     = Team::register( $campaign->id, 'Secret', 100000, Team::ACCESS_PRIVATE );
		$donor    = new Donor( [ 'email' => 'latecomer@example.com' ] );
		$donor->save();

		$service = $this->service();
		$first   = $service->register_fundraiser( $donor, $campaign, [ 'goal' => 10000 ] );
		$this->assertNull( $first['team'] );

		$invite = $team->invite( 'latecomer@example.com' );
		$second = $service->register_fundraiser( $donor, $campaign, [ 'goal' => 10000, 'invite_token' => $invite->token ] );

		$this->assertSame( $first['fundraiser']['id'], $second['fundraiser']['id'] );
		$this->assertSame( $team->id, $second['team']['id'] );
		$this->assertSame( $team->id, Fundraiser::find( $first['fundraiser']['id'] )->team_id );
	}

	/**
	 * Test a re-submission with an unusable token errors instead of quietly
	 * returning the solo page.
	 */
	public function test_register_fundraiser_resubmit_with_invalid_token_errors(): void {
		$campaign = $this->create_campaign( [ 'teams_enabled' => true ] );
		$donor    = new Donor( [ 'email' => 'stale@example.com' ] );
		$donor->save();

		$service = $this->service();
		$first   = $service->register_fundraiser( $donor, $campaign, [ 'goal' => 10000 ] );

		$result = $service->register_fundraiser( $donor, $campaign, [ 'goal' => 10000, 'invite_token' => 'junk-token' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invitation_invalid', $result->get_error_code() );
		$this->assertNull( Fundraiser::find( $first['fundraiser']['id'] )->team_id );
	}

	/**
	 * Test a dedication is stored as fundraiser meta.
	 */
	public function test_register_fundraiser_stores_dedication(): void {
		$campaign = $this->create_campaign();
		$donor    = new Donor( [ 'email' => 'tribute@example.com' ] );
		$donor->save();

		$result     = $this->service()->register_fundraiser(
			$donor,
			$campaign,
			[ 'goal' => 10000, 'dedicate' => true, 'tribute_type' => 'memory', 'honoree_name' => 'Rex' ]
		);
		$fundraiser = Fundraiser::find( $result['fundraiser']['id'] );

		$this->assertSame( 'memory', $fundraiser->get_meta( 'tribute_type' ) );
		$this->assertSame( 'Rex', $fundraiser->get_meta( 'tribute_name' ) );
	}
}
