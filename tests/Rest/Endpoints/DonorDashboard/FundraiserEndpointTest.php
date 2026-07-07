<?php
/**
 * Tests for the donor dashboard FundraiserEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints\DonorDashboard;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\Transaction;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * FundraiserEndpoint test class.
 */
class FundraiserEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Donor WP user ID (the owner).
	 *
	 * @var int
	 */
	private int $donor_user_id;

	/**
	 * The owning donor record.
	 *
	 * @var Donor
	 */
	private Donor $donor;

	/**
	 * A P2P campaign.
	 *
	 * @var Campaign
	 */
	private Campaign $campaign;

	/**
	 * Ensure the donor role exists for these tests.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		DatabaseModule::create_tables();

		if ( ! get_role( 'missiondp_donor' ) ) {
			add_role( 'missiondp_donor', 'Donor', [] );
		}
	}

	/**
	 * Remove rows committed by other test classes so donor lookups resolve
	 * cleanly. Runs inside the per-test transaction, so it rolls back too.
	 */
	private function reset_tables(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( [ 'team_invitations', 'teammeta', 'teams', 'fundraisermeta', 'fundraisers', 'transactionmeta', 'transactions', 'donormeta', 'donors', 'campaignmeta', 'campaigns' ] as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_{$table}" );
		}
		// phpcs:enable
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->reset_tables();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		update_option( SettingsService::OPTION_NAME, [ 'test_mode' => false, 'currency' => 'USD' ] );

		$this->donor_user_id = self::factory()->user->create(
			[
				'role'       => 'missiondp_donor',
				'user_email' => 'jane@example.com',
			]
		);

		$this->donor = new Donor(
			[
				'email'      => 'jane@example.com',
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'user_id'    => $this->donor_user_id,
			]
		);
		$this->donor->save();

		$this->campaign = new Campaign(
			[
				'title' => 'Marathon',
				'type'  => Campaign::TYPE_P2P,
			]
		);
		$this->campaign->save();

		wp_set_current_user( $this->donor_user_id );
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
	 * Create a fundraiser owned by the default donor.
	 *
	 * @param array $overrides Data overrides.
	 * @return Fundraiser
	 */
	private function create_fundraiser( array $overrides = [] ): Fundraiser {
		$fundraiser = new Fundraiser(
			array_merge(
				[
					'campaign_id' => $this->campaign->id,
					'donor_id'    => $this->donor->id,
					'status'      => Fundraiser::STATUS_ACTIVE,
					'goal'        => 50000,
					'headline'    => 'Help me help',
					'story'       => 'My story',
				],
				$overrides
			)
		);
		$fundraiser->save();

		return $fundraiser;
	}

	/**
	 * Dispatch a GET request as the current user.
	 */
	private function get( string $route, array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a PUT request as the current user.
	 */
	private function put( string $route, array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * PUT updates the editable fields.
	 */
	public function test_put_updates_editable_fields(): void {
		$fundraiser = $this->create_fundraiser();

		$response = $this->put(
			"/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}",
			[
				'headline' => 'New headline',
				'story'    => 'A fresh story',
				// Sent in major units; the server converts to minor (USD: x100).
				'goal'     => 750,
			]
		);

		$this->assertSame( 200, $response->get_status() );

		$updated = Fundraiser::find( $fundraiser->id );
		$this->assertSame( 'New headline', $updated->headline );
		$this->assertSame( 'A fresh story', $updated->story );
		$this->assertSame( 75000, $updated->goal );

		// The response precomputes the progress-bar strings the dashboard renders.
		$data = $response->get_data();
		$this->assertSame( '0%', $data['bar_width'] );
		$this->assertSame( '0%', $data['percent_label'] );
	}

	/**
	 * Editing never changes the fundraiser's status.
	 */
	public function test_put_does_not_change_status(): void {
		$fundraiser = $this->create_fundraiser( [ 'status' => Fundraiser::STATUS_PENDING ] );

		$this->put(
			"/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}",
			[ 'headline' => 'Edited while pending', 'status' => Fundraiser::STATUS_ACTIVE ]
		);

		$updated = Fundraiser::find( $fundraiser->id );
		$this->assertSame( Fundraiser::STATUS_PENDING, $updated->status );
	}

	/**
	 * PUT sanitizes the headline and story.
	 */
	public function test_put_sanitizes_input(): void {
		$fundraiser = $this->create_fundraiser();

		$this->put(
			"/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}",
			[
				'headline' => 'Bold <b>headline</b>',
				'story'    => 'Story <script>alert(1)</script> body',
			]
		);

		$updated = Fundraiser::find( $fundraiser->id );
		$this->assertStringNotContainsString( '<b>', $updated->headline );
		$this->assertStringNotContainsString( '<script>', $updated->story );
		$this->assertStringContainsString( 'body', $updated->story );
	}

	/**
	 * PUT on an unowned fundraiser is rejected and changes nothing.
	 */
	public function test_put_rejects_unowned_fundraiser(): void {
		$other_donor = new Donor( [ 'email' => 'bob@example.com' ] );
		$other_donor->save();
		$other = $this->create_fundraiser( [ 'donor_id' => $other_donor->id, 'headline' => 'Original' ] );

		$response = $this->put(
			"/mission-donation-platform/v1/donor-dashboard/fundraisers/{$other->id}",
			[ 'headline' => 'Hijacked' ]
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'Original', Fundraiser::find( $other->id )->headline );
	}

	/**
	 * The routes require an authenticated donor.
	 */
	public function test_requires_donor_role(): void {
		$fundraiser = $this->create_fundraiser();
		wp_set_current_user( 0 );

		$response = $this->put(
			"/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}",
			[ 'headline' => 'Anonymous edit' ]
		);

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A logged-in user without the donor role gets 403, not 401.
	 */
	public function test_logged_in_without_donor_role_gets_403(): void {
		$fundraiser = $this->create_fundraiser();
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$response = $this->put(
			"/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}",
			[ 'headline' => 'Subscriber edit' ]
		);

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A logged-in non-donor cannot list a fundraiser's supporters (PII).
	 */
	public function test_non_donor_cannot_list_supporters(): void {
		$fundraiser = $this->create_fundraiser();
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$response = $this->get( "/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}/donors" );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Another fundraiser's session gets 404 editing this one's page.
	 */
	public function test_other_fundraiser_session_cannot_edit(): void {
		$fundraiser = $this->create_fundraiser( [ 'headline' => 'Original' ] );

		// Fundraiser B: a different donor with their own session and page.
		$other_user  = self::factory()->user->create( [ 'role' => 'missiondp_donor', 'user_email' => 'mallory@example.com' ] );
		$other_donor = new Donor(
			[
				'email'      => 'mallory@example.com',
				'first_name' => 'Mal',
				'user_id'    => $other_user,
			]
		);
		$other_donor->save();
		$this->create_fundraiser( [ 'donor_id' => $other_donor->id ] );
		wp_set_current_user( $other_user );

		$put = $this->put(
			"/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}",
			[ 'headline' => 'Hijacked' ]
		);
		$this->assertSame( 404, $put->get_status() );
		$this->assertSame( 'Original', Fundraiser::find( $fundraiser->id )->headline );
	}

	/**
	 * The donors route lists supporters of the fundraiser.
	 */
	public function test_donors_route_lists_supporters(): void {
		$fundraiser = $this->create_fundraiser();

		$giver = new Donor( [ 'email' => 'giver@example.com', 'first_name' => 'Gina', 'last_name' => 'Giver' ] );
		$giver->save();

		$txn = new Transaction(
			[
				'donor_id'       => $giver->id,
				'campaign_id'    => $this->campaign->id,
				'fundraiser_id'  => $fundraiser->id,
				'amount'         => 5000,
				'currency'       => 'USD',
				'status'         => Transaction::STATUS_COMPLETED,
				'is_test'        => false,
				'date_completed' => current_time( 'mysql', true ),
			]
		);
		$txn->save();

		$response = $this->get( "/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}/donors" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 'Gina Giver', $data[0]['name'] );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
	}

	/**
	 * The donors route includes avatar initials and a relative time.
	 */
	public function test_donors_route_includes_initials_and_time_ago(): void {
		$fundraiser = $this->create_fundraiser();

		$giver = new Donor( [ 'email' => 'giver@example.com', 'first_name' => 'Gina', 'last_name' => 'Giver' ] );
		$giver->save();
		$anon = new Donor( [ 'email' => 'anon@example.com', 'first_name' => 'Secret', 'last_name' => 'Admirer' ] );
		$anon->save();

		foreach ( [
			[ 'donor_id' => $giver->id, 'is_anonymous' => false, 'date_completed' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ],
			[ 'donor_id' => $anon->id, 'is_anonymous' => true, 'date_completed' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ],
		] as $txn_data ) {
			$txn = new Transaction(
				array_merge(
					[
						'campaign_id'   => $this->campaign->id,
						'fundraiser_id' => $fundraiser->id,
						'amount'        => 5000,
						'currency'      => 'USD',
						'status'        => Transaction::STATUS_COMPLETED,
						'is_test'       => false,
					],
					$txn_data
				)
			);
			$txn->save();
		}

		$data = $this->get( "/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}/donors" )->get_data();

		$this->assertSame( 'GG', $data[0]['initials'] );
		$this->assertSame( '2 days ago', $data[0]['time_ago'] );

		// Anonymous gifts never leak the giver's initials.
		$this->assertSame( '?', $data[1]['initials'] );
	}

	/**
	 * PUT sets, normalizes, and clears the page dedication.
	 */
	public function test_put_updates_dedication(): void {
		$fundraiser = $this->create_fundraiser();

		$response = $this->put(
			"/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}",
			[
				'tribute_type' => 'memory',
				'tribute_name' => 'Jane Smith',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'In memory of Jane Smith', $response->get_data()['dedication_label'] );
		$this->assertSame(
			[
				'type' => 'memory',
				'name' => 'Jane Smith',
			],
			Fundraiser::find( $fundraiser->id )->dedication()
		);

		// Sending only the name keeps the type.
		$this->put(
			"/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}",
			[ 'tribute_name' => 'John Smith' ]
		);
		$this->assertSame( 'memory', Fundraiser::find( $fundraiser->id )->dedication()['type'] );

		// An empty type clears the dedication.
		$response = $this->put(
			"/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}",
			[ 'tribute_type' => '' ]
		);
		$this->assertSame( '', $response->get_data()['dedication_label'] );
		$this->assertNull( Fundraiser::find( $fundraiser->id )->dedication() );
	}

	/**
	 * Writes to a fundraiser on an ended campaign are rejected.
	 */
	public function test_put_rejected_when_campaign_ended(): void {
		$ended = new Campaign(
			[
				'title'  => 'Last Year',
				'type'   => Campaign::TYPE_P2P,
				'status' => Campaign::STATUS_ENDED,
			]
		);
		$ended->save();
		$fundraiser = $this->create_fundraiser(
			[
				'campaign_id' => $ended->id,
				'headline'    => 'Original',
			]
		);

		$response = $this->put(
			"/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}",
			[ 'headline' => 'Too late' ]
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'fundraiser_locked', $response->get_data()['code'] );
		$this->assertSame( 'Original', Fundraiser::find( $fundraiser->id )->headline );
	}

	/**
	 * DELETE /photo clears the cover image.
	 */
	public function test_remove_photo_clears_cover(): void {
		$fundraiser = $this->create_fundraiser( [ 'cover_image' => 'https://example.com/cover.jpg' ] );

		$response = $this->server->dispatch(
			new WP_REST_Request( 'DELETE', "/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}/photo" )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $response->get_data()['cover_image_url'] );
		$this->assertSame( '', (string) Fundraiser::find( $fundraiser->id )->cover_image );
	}

	/**
	 * DELETE /photo is rejected when the campaign has ended.
	 */
	public function test_remove_photo_rejected_when_campaign_ended(): void {
		$ended = new Campaign(
			[
				'title'  => 'Last Year',
				'type'   => Campaign::TYPE_P2P,
				'status' => Campaign::STATUS_ENDED,
			]
		);
		$ended->save();
		$fundraiser = $this->create_fundraiser(
			[
				'campaign_id' => $ended->id,
				'cover_image' => 'https://example.com/cover.jpg',
			]
		);

		$response = $this->server->dispatch(
			new WP_REST_Request( 'DELETE', "/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}/photo" )
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'https://example.com/cover.jpg', Fundraiser::find( $fundraiser->id )->cover_image );
	}

	/**
	 * A member can leave their team; the page itself stays untouched.
	 */
	public function test_leave_team_detaches_member(): void {
		$team = new Team(
			[
				'campaign_id' => $this->campaign->id,
				'name'        => 'Rangers',
				'status'      => Team::STATUS_ACTIVE,
			]
		);
		$team->save();
		$fundraiser = $this->create_fundraiser( [ 'team_id' => $team->id ] );

		$request  = new WP_REST_Request( 'POST', "/mission-donation-platform/v1/donor-dashboard/fundraisers/{$fundraiser->id}/leave-team" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['team_id'] );

		$updated = Fundraiser::find( $fundraiser->id );
		$this->assertNull( $updated->team_id );
		$this->assertSame( Fundraiser::STATUS_ACTIVE, $updated->status );
	}

	/**
	 * The captain must promote a successor before leaving; teamless pages get a 400.
	 */
	public function test_leave_team_rejects_captain_and_teamless(): void {
		$team = new Team(
			[
				'campaign_id' => $this->campaign->id,
				'name'        => 'Rangers',
				'status'      => Team::STATUS_ACTIVE,
			]
		);
		$team->save();
		$captain = $this->create_fundraiser( [ 'team_id' => $team->id ] );
		$team->set_captain( $captain );

		$response = $this->server->dispatch(
			new WP_REST_Request( 'POST', "/mission-donation-platform/v1/donor-dashboard/fundraisers/{$captain->id}/leave-team" )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'captain_cannot_leave', $response->get_data()['code'] );
		$this->assertSame( $team->id, Fundraiser::find( $captain->id )->team_id );

		$captain->leave_team();
		$response = $this->server->dispatch(
			new WP_REST_Request( 'POST', "/mission-donation-platform/v1/donor-dashboard/fundraisers/{$captain->id}/leave-team" )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_on_team', $response->get_data()['code'] );
	}
}
