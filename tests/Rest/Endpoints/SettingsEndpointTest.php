<?php
/**
 * Tests for the SettingsEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * SettingsEndpoint test class.
 */
class SettingsEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private int $subscriber_id;

	/**
	 * Set up before class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		delete_option( SettingsService::OPTION_NAME );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		delete_option( SettingsService::OPTION_NAME );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Test GET requires manage_options capability.
	 */
	public function test_get_requires_manage_options(): void {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/settings' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test GET returns all settings for admin.
	 */
	public function test_get_returns_all_settings(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/settings' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'currency', $data );
		$this->assertArrayHasKey( 'stripe_site_id', $data );
		$this->assertArrayHasKey( 'stripe_connection_status', $data );
		$this->assertArrayHasKey( 'stripe_display_name', $data );
		$this->assertArrayHasKey( 'email_from_name', $data );
		$this->assertArrayHasKey( 'test_mode', $data );
	}

	/**
	 * Test GET excludes stripe_site_token from response.
	 */
	public function test_get_excludes_site_token(): void {
		update_option(
			SettingsService::OPTION_NAME,
			array( 'stripe_site_token' => 'tok_secret_abc123' )
		);

		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/settings' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayNotHasKey( 'stripe_site_token', $data );
	}

	/**
	 * Test POST updates settings values.
	 */
	public function test_post_updates_values(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'currency' => 'eur' ) ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'EUR', $data['currency'] );
	}

	/**
	 * Test POST clamps the fixed fee to the currency's sanity cap.
	 */
	public function test_post_clamps_fixed_fee(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'stripe_fee_fixed' => 50000 ) ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 1000, $response->get_data()['stripe_fee_fixed'] );

		$request2 = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/settings' );
		$request2->set_header( 'Content-Type', 'application/json' );
		$request2->set_body( wp_json_encode( array( 'stripe_fee_fixed' => -5 ) ) );

		$response2 = $this->server->dispatch( $request2 );

		$this->assertSame( 0, $response2->get_data()['stripe_fee_fixed'] );
	}

	/**
	 * Test POST allows fixed fees above one major unit (e.g. MXN's $3.00 fee).
	 */
	public function test_post_allows_fixed_fee_above_one_major_unit(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'currency'         => 'mxn',
					'stripe_fee_fixed' => 300,
				)
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 300, $response->get_data()['stripe_fee_fixed'] );
	}

	/**
	 * Test POST currency change without a fee resets it to the new default.
	 */
	public function test_post_currency_change_resets_fixed_fee(): void {
		update_option(
			SettingsService::OPTION_NAME,
			array(
				'currency'         => 'USD',
				'stripe_fee_fixed' => 30,
			)
		);

		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'currency' => 'jpy' ) ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 'JPY', $data['currency'] );
		$this->assertSame( 0, $data['stripe_fee_fixed'] );
	}

	/**
	 * Test POST rejects unauthorized user.
	 */
	public function test_post_rejects_unauthorized_user(): void {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'currency' => 'GBP' ) ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test POST cannot set stripe_site_token via settings endpoint.
	 */
	public function test_post_cannot_set_site_token(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'stripe_site_token' => 'tok_evil' ) ) );

		$this->server->dispatch( $request );

		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertTrue(
			! isset( $stored['stripe_site_token'] ) || '' === $stored['stripe_site_token']
		);
	}

	/**
	 * Test POST updates test_mode as a boolean.
	 */
	public function test_post_updates_test_mode(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'test_mode' => false ) ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['test_mode'] );

		// Toggle back on.
		$request2 = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/settings' );
		$request2->set_header( 'Content-Type', 'application/json' );
		$request2->set_body( wp_json_encode( array( 'test_mode' => true ) ) );

		$response2 = $this->server->dispatch( $request2 );
		$data2     = $response2->get_data();

		$this->assertTrue( $data2['test_mode'] );
	}

	/**
	 * Test POST updates the campaign URL slug.
	 */
	public function test_post_updates_campaign_url_slug(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->post_settings( array( 'campaign_url_slug' => 'giving' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'giving', $response->get_data()['campaign_url_slug'] );
		$this->assertSame( 'giving', get_option( SettingsService::OPTION_NAME )['campaign_url_slug'] );
	}

	/**
	 * Test POST sanitizes the campaign URL slug.
	 */
	public function test_post_sanitizes_campaign_url_slug(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->post_settings( array( 'campaign_url_slug' => 'Giving Page!' ) );

		$this->assertSame( 'giving-page', $response->get_data()['campaign_url_slug'] );
	}

	/**
	 * Test POST rejects a campaign slug used by an existing page, saving nothing.
	 */
	public function test_post_rejects_conflicting_campaign_slug(): void {
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'giving',
				'post_title'  => 'Giving',
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( $this->admin_id );

		$response = $this->post_settings(
			array(
				'campaign_url_slug' => 'giving',
				'currency'          => 'eur',
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'missiondp_campaign_slug_conflict', $response->get_data()['code'] );

		// The whole save is rejected atomically.
		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertFalse( $stored );
	}

	/**
	 * Test POST accepts re-saving the current slug even when other content uses it.
	 */
	public function test_post_allows_resaving_current_campaign_slug(): void {
		update_option( SettingsService::OPTION_NAME, array( 'campaign_url_slug' => 'giving' ) );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'giving',
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( $this->admin_id );

		$response = $this->post_settings( array( 'campaign_url_slug' => 'giving' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test POST rejects an empty campaign slug.
	 */
	public function test_post_rejects_empty_campaign_slug(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->post_settings( array( 'campaign_url_slug' => '!!!' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'missiondp_invalid_campaign_slug', $response->get_data()['code'] );
	}

	/**
	 * Test POST rejects a reserved campaign slug.
	 */
	public function test_post_rejects_reserved_campaign_slug(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->post_settings( array( 'campaign_url_slug' => 'category' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'missiondp_reserved_campaign_slug', $response->get_data()['code'] );
	}

	/**
	 * Dispatch a settings POST with a JSON body.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return \WP_REST_Response
	 */
	private function post_settings( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Test POST ignores unknown keys.
	 */
	public function test_post_ignores_unknown_keys(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'unknown_key' => 'value' ) ) );

		$this->server->dispatch( $request );

		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertArrayNotHasKey( 'unknown_key', is_array( $stored ) ? $stored : array() );
	}
}
