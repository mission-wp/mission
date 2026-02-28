<?php
/**
 * Tests for the SettingsEndpoint class.
 *
 * @package Mission
 */

namespace Mission\Tests\Rest\Endpoints;

use Mission\Settings\SettingsService;
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

		$request  = new WP_REST_Request( 'GET', '/mission/v1/settings' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test GET returns all settings for admin.
	 */
	public function test_get_returns_all_settings(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/mission/v1/settings' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'currency', $data );
		$this->assertArrayHasKey( 'stripe_site_id', $data );
		$this->assertArrayHasKey( 'stripe_connection_status', $data );
		$this->assertArrayHasKey( 'stripe_display_name', $data );
		$this->assertArrayHasKey( 'email_from_name', $data );
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

		$request  = new WP_REST_Request( 'GET', '/mission/v1/settings' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayNotHasKey( 'stripe_site_token', $data );
	}

	/**
	 * Test POST updates settings values.
	 */
	public function test_post_updates_values(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mission/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'currency' => 'eur' ) ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'EUR', $data['currency'] );
	}

	/**
	 * Test POST rejects unauthorized user.
	 */
	public function test_post_rejects_unauthorized_user(): void {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/mission/v1/settings' );
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

		$request = new WP_REST_Request( 'POST', '/mission/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'stripe_site_token' => 'tok_evil' ) ) );

		$this->server->dispatch( $request );

		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertTrue(
			! isset( $stored['stripe_site_token'] ) || '' === $stored['stripe_site_token']
		);
	}

	/**
	 * Test POST ignores unknown keys.
	 */
	public function test_post_ignores_unknown_keys(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mission/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'unknown_key' => 'value' ) ) );

		$this->server->dispatch( $request );

		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertArrayNotHasKey( 'unknown_key', is_array( $stored ) ? $stored : array() );
	}
}
