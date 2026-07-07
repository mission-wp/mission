<?php
/**
 * Tests for the EmailTestEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Email\EmailModule;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * EmailTestEndpoint test class.
 */
class EmailTestEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Arguments captured from the last intercepted wp_mail() call.
	 *
	 * @var array|null
	 */
	private ?array $sent_mail = null;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->sent_mail = null;
		add_filter( 'pre_wp_mail', [ $this, 'intercept_mail' ], 10, 2 );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'intercept_mail' ] );

		global $wp_rest_server;
		$wp_rest_server = null;

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Short-circuit wp_mail() and capture its arguments.
	 *
	 * @param null|bool $return Short-circuit value.
	 * @param array     $atts   wp_mail() arguments.
	 * @return bool
	 */
	public function intercept_mail( $return, array $atts ): bool {
		$this->sent_mail = $atts;

		return true;
	}

	/**
	 * Send a test email for a type.
	 *
	 * @param string $type Email type key.
	 * @return \WP_REST_Response
	 */
	private function send_test( string $type ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/email/test' );
		$request->set_param( 'email_type', $type );

		return $this->server->dispatch( $request );
	}

	/**
	 * Every email type offered by the settings editor, including the P2P,
	 * donor-note, and tribute emails, must send a test email.
	 */
	public function test_all_editor_types_send(): void {
		foreach ( array_keys( EmailModule::TEMPLATE_MAP ) as $type ) {
			$this->sent_mail = null;

			$response = $this->send_test( $type );

			$this->assertSame( 200, $response->get_status(), "Type {$type} did not return 200." );
			$this->assertNotNull( $this->sent_mail, "Type {$type} did not send an email." );
			$this->assertNotSame( '', $this->sent_mail['subject'], "Type {$type} sent an empty subject." );
			$this->assertNotSame( '', $this->sent_mail['message'], "Type {$type} sent an empty body." );
		}
	}

	/**
	 * Team test emails render the sample team name, not an empty merge value.
	 */
	public function test_team_email_renders_sample_team_name(): void {
		$this->send_test( 'p2p_team_invitation' );

		$this->assertStringContainsString( 'Team Sunshine', $this->sent_mail['message'] );
	}

	/**
	 * The test email goes to the current user when no recipient is given.
	 */
	public function test_defaults_to_current_user_email(): void {
		$this->send_test( 'p2p_fundraiser_approved' );

		$this->assertSame( wp_get_current_user()->user_email, $this->sent_mail['to'] );
	}

	/**
	 * Test an unknown type returns 400.
	 */
	public function test_unknown_type_returns_400(): void {
		$response = $this->send_test( 'p2p_otp_code' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( $this->sent_mail );
	}

	/**
	 * Test non-admins are rejected.
	 */
	public function test_requires_admin_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->send_test( 'donation_receipt' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertNull( $this->sent_mail );
	}
}
