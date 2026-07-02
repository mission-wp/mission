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
	 * The most recent OTP code captured from an outgoing email.
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

		if ( ! get_role( 'missiondp_donor' ) ) {
			add_role( 'missiondp_donor', 'Donor', [] );
		}
	}

	/**
	 * Boot a REST server for each test and intercept outgoing OTP mail.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		// Capture the 6-digit code from the rendered email instead of mailing it.
		$this->last_code = '';
		add_filter(
			'wp_mail',
			function ( array $args ): array {
				// Six digits not part of a hex color (e.g. #666666 in the template CSS).
				if ( preg_match( '/(?<![#\d])(\d{6})(?!\d)/', (string) $args['message'], $matches ) ) {
					$this->last_code = $matches[1];
				}
				return $args;
			}
		);
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
	 * Test all five P2P routes are registered.
	 */
	public function test_routes_are_registered(): void {
		$routes = $this->server->get_routes( 'mission-donation-platform/v1' );

		foreach ( [ 'account-lookup', 'send-code', 'verify-code', 'set-password', 'register' ] as $route ) {
			$this->assertArrayHasKey( '/mission-donation-platform/v1/p2p/' . $route, $routes );
		}
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
	 * Test send-code then verify-code creates the account and logs the donor in.
	 */
	public function test_send_and_verify_code_signup_flow(): void {
		$response = $this->post( 'p2p/send-code', [ 'email' => 'fresh@example.com', 'purpose' => 'signup' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['sent'] );
		$this->assertGreaterThan( 0, $response->get_data()['cooldown'] );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', $this->last_code );

		$response = $this->post(
			'p2p/verify-code',
			[
				'email'      => 'fresh@example.com',
				'purpose'    => 'signup',
				'code'       => $this->last_code,
				'first_name' => 'Fresh',
				'last_name'  => 'Start',
				'password'   => 'longenough1',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['authenticated'] );
		$this->assertNotEmpty( $response->get_data()['nonce'] );

		$donor = Donor::find_by_email( 'fresh@example.com' );
		$this->assertNotNull( $donor );
		$this->assertGreaterThan( 0, (int) $donor->user_id );
		$this->assertSame( (int) $donor->user_id, get_current_user_id() );
	}

	/**
	 * Test the full reset flow: send-code, verify-code grant, set-password.
	 */
	public function test_reset_flow_sets_new_password(): void {
		$donor = new Donor( [ 'email' => 'reset@example.com', 'first_name' => 'Re', 'last_name' => 'Set' ] );
		$donor->save();
		$donor->create_user_account( 'oldpassword1' );

		$response = $this->post( 'p2p/send-code', [ 'email' => 'reset@example.com', 'purpose' => 'reset' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', $this->last_code );

		$response = $this->post(
			'p2p/verify-code',
			[
				'email'   => 'reset@example.com',
				'purpose' => 'reset',
				'code'    => $this->last_code,
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$grant = $response->get_data()['grant'];
		$this->assertNotEmpty( $grant );

		$response = $this->post(
			'p2p/set-password',
			[
				'email'    => 'reset@example.com',
				'grant'    => $grant,
				'password' => 'brandnewpw22',
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['authenticated'] );

		$user = wp_authenticate( 'reset@example.com', 'brandnewpw22' );
		$this->assertNotWPError( $user );
	}

	/**
	 * Test set-password rejects a bogus grant with the generic reset error.
	 */
	public function test_set_password_rejects_bad_grant(): void {
		$donor = new Donor( [ 'email' => 'badgrant@example.com' ] );
		$donor->save();
		$donor->create_user_account( 'oldpassword1' );

		$response = $this->post(
			'p2p/set-password',
			[
				'email'    => 'badgrant@example.com',
				'grant'    => 'not-a-real-grant',
				'password' => 'brandnewpw22',
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'reset_failed', $response->get_data()['code'] );
		$this->assertNotWPError( wp_authenticate( 'badgrant@example.com', 'oldpassword1' ) );
	}

	/**
	 * Test a reset code is silently not sent for an unknown email.
	 */
	public function test_send_code_reset_is_silent_for_unknown_email(): void {
		$response = $this->post( 'p2p/send-code', [ 'email' => 'nobody@example.com', 'purpose' => 'reset' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['sent'] );
		$this->assertSame( '', $this->last_code );
	}

	/**
	 * Test a reset code is silently not sent for a non-donor-role account.
	 */
	public function test_send_code_reset_is_silent_for_privileged_account(): void {
		$admin_id = self::factory()->user->create(
			[
				'role'       => 'administrator',
				'user_email' => 'boss@example.com',
			]
		);

		$donor = new Donor( [ 'email' => 'boss@example.com', 'user_id' => $admin_id ] );
		$donor->save();

		$response = $this->post( 'p2p/send-code', [ 'email' => 'boss@example.com', 'purpose' => 'reset' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['sent'] );
		$this->assertSame( '', $this->last_code );
	}

	/**
	 * Test a wrong code and a missing/expired code collapse to the same error.
	 */
	public function test_verify_code_invalid_and_expired_collapse_to_same_error(): void {
		$this->post( 'p2p/send-code', [ 'email' => 'has-code@example.com', 'purpose' => 'signup' ] );
		$wrong = '000000' === $this->last_code ? '111111' : '000000';

		$invalid = $this->post(
			'p2p/verify-code',
			[
				'email'    => 'has-code@example.com',
				'purpose'  => 'signup',
				'code'     => $wrong,
				'password' => 'longenough1',
			]
		);

		// No code was ever sent to this address, so it reads as expired.
		$expired = $this->post(
			'p2p/verify-code',
			[
				'email'    => 'no-code@example.com',
				'purpose'  => 'signup',
				'code'     => '123456',
				'password' => 'longenough1',
			]
		);

		$this->assertSame( 400, $invalid->get_status() );
		$this->assertSame( 400, $expired->get_status() );
		$this->assertSame( 'otp_invalid', $invalid->get_data()['code'] );
		$this->assertSame( $invalid->get_data()['code'], $expired->get_data()['code'] );
	}

	/**
	 * Test resending within the cooldown returns 429 with a retry hint.
	 */
	public function test_send_code_within_cooldown_returns_429_with_retry_after(): void {
		$this->post( 'p2p/send-code', [ 'email' => 'eager@example.com', 'purpose' => 'signup' ] );

		$response = $this->post( 'p2p/send-code', [ 'email' => 'eager@example.com', 'purpose' => 'signup' ] );
		$data     = $response->get_data();

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'otp_cooldown', $data['code'] );
		$this->assertGreaterThan( 0, $data['data']['retry_after'] );
	}

	/**
	 * Test the IP rate limit returns 429 once the (filtered) cap is hit.
	 */
	public function test_rate_limited_route_returns_429(): void {
		add_filter(
			'mission_rate_limit',
			static fn( int $limit, string $action ): int => 'p2p_send_code' === $action ? 1 : $limit,
			10,
			2
		);

		$first = $this->post( 'p2p/send-code', [ 'email' => 'one@example.com', 'purpose' => 'signup' ] );
		$this->assertSame( 200, $first->get_status() );

		$second = $this->post( 'p2p/send-code', [ 'email' => 'two@example.com', 'purpose' => 'signup' ] );

		$this->assertSame( 429, $second->get_status() );
		$this->assertSame( 'rate_limited', $second->get_data()['code'] );
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
