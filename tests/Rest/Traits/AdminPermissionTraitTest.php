<?php
/**
 * Tests for the AdminPermissionTrait.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Traits;

use MissionDP\Rest\Traits\AdminPermissionTrait;
use WP_UnitTestCase;

/**
 * AdminPermissionTrait test class.
 */
class AdminPermissionTraitTest extends WP_UnitTestCase {

	/**
	 * Test double using the trait with the default message.
	 *
	 * @var object
	 */
	private object $endpoint;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->endpoint = new class() {
			use AdminPermissionTrait;
		};
	}

	/**
	 * Test administrators pass the check.
	 */
	public function test_admin_passes(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertTrue( $this->endpoint->check_admin_permission() );
	}

	/**
	 * Test subscribers get a rest_forbidden 403 with the default message.
	 */
	public function test_subscriber_is_forbidden(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = $this->endpoint->check_admin_permission();

		$this->assertWPError( $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'You do not have permission to perform this action.', $result->get_error_message() );
	}

	/**
	 * Test logged-out users are forbidden.
	 */
	public function test_logged_out_is_forbidden(): void {
		wp_set_current_user( 0 );

		$result = $this->endpoint->check_admin_permission();

		$this->assertWPError( $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test endpoints can override the denial message.
	 */
	public function test_message_override(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$endpoint = new class() {
			use AdminPermissionTrait;

			/**
			 * Override the denial message.
			 *
			 * @return string
			 */
			protected function permission_denied_message(): string {
				return 'Custom denial message.';
			}
		};

		$this->assertSame( 'Custom denial message.', $endpoint->check_admin_permission()->get_error_message() );
	}
}
