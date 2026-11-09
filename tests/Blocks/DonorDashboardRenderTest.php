<?php
/**
 * Tests for the Account (donor dashboard) block render.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Blocks;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Donor;
use WP_UnitTestCase;

/**
 * Renders the block with the query strings carried by activation and reset emails.
 */
class DonorDashboardRenderTest extends WP_UnitTestCase {

	private const BLOCK = 'mission-donation-platform/donor-dashboard';

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Clean up query vars and plugin tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		$_GET = [];
		wp_set_current_user( 0 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * A valid activation link opens the set-password view.
	 */
	public function test_valid_activation_link_renders_set_password_view(): void {
		$this->create_donor_with_token( 'activate@example.com', 'valid-token' );

		$_GET = [
			'activation_token' => 'valid-token',
			'email'            => 'activate@example.com',
		];

		$state = $this->render_and_get_state();

		$this->assertTrue( $state['isSetPasswordView'] );
	}

	/**
	 * A valid activation link for an email that already has a WP account asks for that account's password.
	 */
	public function test_activation_link_for_existing_account_renders_link_account_view(): void {
		self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'member@example.com' ] );
		$this->create_donor_with_token( 'member@example.com', 'valid-token' );

		$_GET = [
			'activation_token' => 'valid-token',
			'email'            => 'member@example.com',
		];

		$state = $this->render_and_get_state();

		$this->assertTrue( $state['isLinkAccountView'] );
		$this->assertFalse( $state['isSetPasswordView'] );
	}

	/**
	 * A logged-in user linked to a donor record sees the dashboard whatever their role.
	 */
	public function test_linked_non_donor_user_sees_dashboard(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator', 'user_email' => 'admin-donor@example.com' ] );
		( new Donor( [ 'email' => 'admin-donor@example.com', 'user_id' => $admin_id ] ) )->save();
		wp_set_current_user( $admin_id );

		$html = do_blocks( '<!-- wp:' . self::BLOCK . ' /-->' );

		$this->assertStringNotContainsString( 'callbacks.initAuth', $html );
	}

	/**
	 * A bad activation token falls back to the activate view with an error.
	 */
	public function test_invalid_activation_link_renders_activate_view(): void {
		$this->create_donor_with_token( 'activate@example.com', 'valid-token' );

		$_GET = [
			'activation_token' => 'wrong-token',
			'email'            => 'activate@example.com',
		];

		$state = $this->render_and_get_state();

		$this->assertTrue( $state['isActivateView'] );
	}

	/**
	 * A valid password reset link opens the reset-password view.
	 */
	public function test_valid_reset_link_renders_reset_password_view(): void {
		$user = self::factory()->user->create_and_get( [ 'role' => 'missiondp_donor' ] );
		$key  = get_password_reset_key( $user );

		$_GET = [
			'action' => 'reset-password',
			'key'    => $key,
			'login'  => $user->user_login,
		];

		$state = $this->render_and_get_state();

		$this->assertTrue( $state['isResetPasswordView'] );
	}

	/**
	 * Store a donor with an unexpired activation token.
	 *
	 * @param string $email Donor email.
	 * @param string $token Plain-text token.
	 * @return void
	 */
	private function create_donor_with_token( string $email, string $token ): void {
		$donor = new Donor( [ 'email' => $email ] );
		$donor->save();
		$donor->update_meta( 'activation_token', wp_hash_password( $token ) );
		$donor->update_meta( 'activation_token_expires', gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
	}

	/**
	 * Render the block and return its server-side interactivity state.
	 *
	 * @return array<string, mixed>
	 */
	private function render_and_get_state(): array {
		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) );

		$html = do_blocks( '<!-- wp:' . self::BLOCK . ' /-->' );

		$this->assertNotEmpty( $html );

		return wp_interactivity_state( self::BLOCK );
	}
}
