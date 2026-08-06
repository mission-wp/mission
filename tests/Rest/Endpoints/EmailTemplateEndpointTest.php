<?php
/**
 * Tests for the EmailTemplateEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * EmailTemplateEndpoint test class.
 */
class EmailTemplateEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
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
	 * Fetch a template by type.
	 *
	 * @param string $type Email type key.
	 * @return \WP_REST_Response
	 */
	private function get_template( string $type ): \WP_REST_Response {
		return $this->server->dispatch(
			new WP_REST_Request( 'GET', "/mission-donation-platform/v1/email/template/{$type}" )
		);
	}

	/**
	 * Every email type offered by the settings editor, including the P2P
	 * fundraiser and team emails, must render a default template.
	 */
	public function test_all_editor_types_return_default_template(): void {
		$types = [
			'donation_receipt',
			'subscription_activated',
			'renewal_receipt',
			'payment_failed',
			'subscription_cancelled',
			'account_activation',
			'password_reset',
			'email_change_verification',
			'donor_note',
			'tribute_notification',
			'p2p_fundraiser_approved',
			'p2p_fundraiser_received_donation',
			'p2p_fundraiser_milestone',
			'p2p_team_invitation',
			'p2p_team_member_joined',
			'p2p_team_approved',
		];

		foreach ( $types as $type ) {
			$response = $this->get_template( $type );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status(), "Type {$type} did not return 200." );
			$this->assertNotSame( '', $data['body'], "Type {$type} rendered an empty body." );
			$this->assertNotSame( '', $data['subject'], "Type {$type} has no default subject." );
		}
	}

	/**
	 * Test team templates render with the editor's merge tags as literals.
	 */
	public function test_team_templates_render_merge_tags(): void {
		$invitation = $this->get_template( 'p2p_team_invitation' )->get_data();
		$this->assertStringContainsString( '{team_name}', $invitation['body'] );
		$this->assertStringContainsString( '{accept_url}', $invitation['body'] );

		$joined = $this->get_template( 'p2p_team_member_joined' )->get_data();
		$this->assertStringContainsString( '{captain_name}', $joined['body'] );
		$this->assertStringContainsString( '{member_name}', $joined['body'] );

		$approved = $this->get_template( 'p2p_team_approved' )->get_data();
		$this->assertStringContainsString( '{captain_name}', $approved['body'] );
	}

	/**
	 * Test an unknown type returns 400.
	 */
	public function test_unknown_type_returns_400(): void {
		$response = $this->get_template( 'not_a_real_type' );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test non-admins are rejected.
	 */
	public function test_requires_admin_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->get_template( 'donation_receipt' );

		$this->assertSame( 403, $response->get_status() );
	}
}
