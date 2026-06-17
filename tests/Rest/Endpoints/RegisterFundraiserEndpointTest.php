<?php
/**
 * Tests for the RegisterFundraiserEndpoint routing and guards.
 *
 * The OTP/account choreography is covered by FundraiserRegistrationServiceTest;
 * these tests exercise the REST wiring: route registration, campaign validation,
 * the session requirement on register, and the dollars-to-cents conversion.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * RegisterFundraiserEndpoint test class.
 */
class RegisterFundraiserEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Boot a REST server for each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server, $wpdb;

		$wp_rest_server = null;
		wp_set_current_user( 0 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Create a P2P campaign.
	 *
	 * @param array<string, mixed> $settings P2P settings.
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
	 * Dispatch a POST to a P2P route.
	 *
	 * @param string $route Route after the namespace.
	 * @param array  $body  Body params.
	 * @return \WP_REST_Response
	 */
	private function post( string $route, array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/' . $route );
		$request->set_body_params( $body );

		return $this->server->dispatch( $request );
	}

	/**
	 * Test the routes are registered.
	 */
	public function test_routes_are_registered(): void {
		$routes = $this->server->get_routes( 'mission-donation-platform/v1' );

		$this->assertArrayHasKey( '/mission-donation-platform/v1/p2p/account-lookup', $routes );
		$this->assertArrayHasKey( '/mission-donation-platform/v1/p2p/register', $routes );
	}

	/**
	 * Test account-lookup of a brand-new email returns verify_required.
	 */
	public function test_account_lookup_new_email(): void {
		$campaign = $this->create_campaign();

		$response = $this->post(
			'p2p/account-lookup',
			[ 'campaign_id' => $campaign->id, 'email' => 'new@example.com', 'password' => 'longenough1' ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'verify_required', $response->get_data()['branch'] );
	}

	/**
	 * Test a non-P2P campaign is rejected.
	 */
	public function test_account_lookup_rejects_non_p2p_campaign(): void {
		$campaign = new Campaign( [ 'title' => 'Standard', 'type' => 'standard' ] );
		$campaign->save();

		$response = $this->post(
			'p2p/account-lookup',
			[ 'campaign_id' => $campaign->id, 'email' => 'a@example.com', 'password' => 'longenough1' ]
		);

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Test registration is rejected when closed.
	 */
	public function test_lookup_rejected_when_registration_closed(): void {
		$campaign = $this->create_campaign( [ 'registration_open' => false ] );

		$response = $this->post(
			'p2p/account-lookup',
			[ 'campaign_id' => $campaign->id, 'email' => 'a@example.com', 'password' => 'longenough1' ]
		);

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test register requires an authenticated donor session.
	 */
	public function test_register_requires_session(): void {
		$campaign = $this->create_campaign();

		$response = $this->post( 'p2p/register', [ 'campaign_id' => $campaign->id, 'goal' => 300 ] );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Test a logged-in donor creates a fundraiser, dollars converted to cents.
	 */
	public function test_register_creates_fundraiser_for_logged_in_donor(): void {
		$campaign = $this->create_campaign();

		$donor = new Donor( [ 'email' => 'live@example.com', 'first_name' => 'Live', 'last_name' => 'Donor' ] );
		$donor->save();
		$user_id = $donor->create_user_account( 'longenough1' );
		wp_set_current_user( $user_id );

		$response = $this->post( 'p2p/register', [ 'campaign_id' => $campaign->id, 'goal' => 300, 'story' => 'My story' ] );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'active', $data['fundraiser']['status'] );

		$fundraiser = Fundraiser::find( $data['fundraiser']['id'] );
		$this->assertSame( 30000, $fundraiser->goal );
		$this->assertSame( $donor->id, $fundraiser->donor_id );
	}
}
