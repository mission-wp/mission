<?php
/**
 * Tests for the donor dashboard TeamEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints\DonorDashboard;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\TeamInvitation;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * TeamEndpoint test class.
 */
class TeamEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Captain WP user ID.
	 *
	 * @var int
	 */
	private int $captain_user_id;

	/**
	 * The captain donor record.
	 *
	 * @var Donor
	 */
	private Donor $captain_donor;

	/**
	 * A P2P campaign.
	 *
	 * @var Campaign
	 */
	private Campaign $campaign;

	/**
	 * The team the captain leads.
	 *
	 * @var Team
	 */
	private Team $team;

	/**
	 * Ensure the donor role + tables exist.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		DatabaseModule::create_tables();

		if ( ! get_role( 'missiondp_donor' ) ) {
			add_role( 'missiondp_donor', 'Donor', [] );
		}
	}

	/**
	 * Remove rows committed by other test classes.
	 */
	private function reset_tables(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( [ 'team_invitations', 'fundraisermeta', 'fundraisers', 'teammeta', 'teams', 'donormeta', 'donors', 'campaignmeta', 'campaigns' ] as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_{$table}" );
		}
		// phpcs:enable
	}

	/**
	 * Set up each test: a campaign, a captain, and a team led by that captain.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->reset_tables();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		update_option( SettingsService::OPTION_NAME, [ 'test_mode' => false, 'currency' => 'USD' ] );

		$this->captain_user_id = self::factory()->user->create(
			[
				'role'       => 'missiondp_donor',
				'user_email' => 'cap@example.com',
			]
		);

		$this->captain_donor = new Donor(
			[
				'email'      => 'cap@example.com',
				'first_name' => 'Cap',
				'last_name'  => 'Tain',
				'user_id'    => $this->captain_user_id,
			]
		);
		$this->captain_donor->save();

		$this->campaign = new Campaign( [ 'title' => 'Marathon', 'type' => Campaign::TYPE_P2P ] );
		$this->campaign->save();

		$this->team = Team::register( $this->campaign->id, 'Runners', 100000 );
		$captain    = new Fundraiser(
			[
				'campaign_id'     => $this->campaign->id,
				'donor_id'        => $this->captain_donor->id,
				'team_id'         => $this->team->id,
				'is_team_captain' => true,
				'status'          => Fundraiser::STATUS_ACTIVE,
			]
		);
		$captain->save();
		$this->team->set_captain( $captain );

		wp_set_current_user( $this->captain_user_id );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Add a non-captain member (with their own donor + user) to the team.
	 *
	 * @param string $email Member email.
	 * @return Fundraiser
	 */
	private function add_member( string $email ): Fundraiser {
		$donor = new Donor( [ 'email' => $email, 'first_name' => 'Mem', 'last_name' => 'Ber' ] );
		$donor->save();

		$member = new Fundraiser(
			[
				'campaign_id' => $this->campaign->id,
				'donor_id'    => $donor->id,
				'team_id'     => $this->team->id,
				'status'      => Fundraiser::STATUS_ACTIVE,
			]
		);
		$member->save();

		return $member;
	}

	/**
	 * Dispatch a request as the current user.
	 */
	private function dispatch( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * GET returns the captain's team with members and invitations.
	 */
	public function test_get_returns_team_for_captain(): void {
		$this->add_member( 'm1@example.com' );

		$response = $this->dispatch( 'GET', "/mission-donation-platform/v1/donor-dashboard/teams/{$this->team->id}" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Runners', $data['name'] );
		$this->assertCount( 2, $data['members'] );
	}

	/**
	 * A member who is not the captain gets a 403.
	 */
	public function test_member_who_is_not_captain_gets_403(): void {
		$member = $this->add_member( 'plain@example.com' );
		$user   = self::factory()->user->create( [ 'role' => 'missiondp_donor', 'user_email' => 'plain2@example.com' ] );

		// Link the member's donor to a WP user and act as them.
		$member_donor          = $member->donor();
		$member_donor->user_id = $user;
		$member_donor->save();
		wp_set_current_user( $user );

		$response = $this->dispatch( 'GET', "/mission-donation-platform/v1/donor-dashboard/teams/{$this->team->id}" );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * PUT updates the editable team fields (goal converts from major units).
	 */
	public function test_put_updates_team_fields(): void {
		$response = $this->dispatch(
			'PUT',
			"/mission-donation-platform/v1/donor-dashboard/teams/{$this->team->id}",
			[ 'name' => 'Trail Blazers', 'description' => 'We run', 'goal' => 2000 ]
		);

		$this->assertSame( 200, $response->get_status() );

		$updated = Team::find( $this->team->id );
		$this->assertSame( 'Trail Blazers', $updated->name );
		$this->assertSame( 'We run', $updated->description );
		$this->assertSame( 200000, $updated->goal );
	}

	/**
	 * PUT never changes the team status.
	 */
	public function test_put_does_not_change_status(): void {
		$this->team->status = Team::STATUS_PENDING;
		$this->team->save();

		$this->dispatch(
			'PUT',
			"/mission-donation-platform/v1/donor-dashboard/teams/{$this->team->id}",
			[ 'name' => 'Edited', 'status' => Team::STATUS_ACTIVE ]
		);

		$this->assertSame( Team::STATUS_PENDING, Team::find( $this->team->id )->status );
	}

	/**
	 * Invite creates a pending invitation for the team.
	 */
	public function test_invite_creates_invitation(): void {
		$response = $this->dispatch(
			'POST',
			"/mission-donation-platform/v1/donor-dashboard/teams/{$this->team->id}/invite",
			[ 'email' => 'newbie@example.com' ]
		);

		$this->assertSame( 201, $response->get_status() );
		$invitations = $this->team->invitations( [ 'status' => TeamInvitation::STATUS_PENDING ] );
		$this->assertCount( 1, $invitations );
		$this->assertSame( 'newbie@example.com', $invitations[0]->email );
	}

	/**
	 * Remove detaches the member from the team.
	 */
	public function test_remove_member(): void {
		$member = $this->add_member( 'gone@example.com' );

		$response = $this->dispatch(
			'POST',
			"/mission-donation-platform/v1/donor-dashboard/teams/{$this->team->id}/members/{$member->id}/remove"
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( Fundraiser::find( $member->id )->team_id );
	}

	/**
	 * Removing the captain is rejected.
	 */
	public function test_remove_captain_rejected(): void {
		$captain_id = $this->team->captain_id;

		$response = $this->dispatch(
			'POST',
			"/mission-donation-platform/v1/donor-dashboard/teams/{$this->team->id}/members/{$captain_id}/remove"
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $this->team->id, Fundraiser::find( $captain_id )->team_id );
	}

	/**
	 * Promote swaps the captaincy; the old captain then loses access (403).
	 */
	public function test_promote_member_then_old_captain_loses_access(): void {
		$member = $this->add_member( 'rising@example.com' );

		$response = $this->dispatch(
			'POST',
			"/mission-donation-platform/v1/donor-dashboard/teams/{$this->team->id}/members/{$member->id}/promote"
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $member->id, Team::find( $this->team->id )->captain_id );

		// The original captain (still the current user) is no longer captain.
		$after = $this->dispatch( 'GET', "/mission-donation-platform/v1/donor-dashboard/teams/{$this->team->id}" );
		$this->assertSame( 403, $after->get_status() );
	}

	/**
	 * Routes require an authenticated donor.
	 */
	public function test_requires_login(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch( 'GET', "/mission-donation-platform/v1/donor-dashboard/teams/{$this->team->id}" );

		$this->assertSame( 401, $response->get_status() );
	}
}
