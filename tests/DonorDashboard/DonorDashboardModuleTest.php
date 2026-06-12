<?php
/**
 * Tests for the DonorDashboardModule.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\DonorDashboard;

use MissionDP\Database\DatabaseModule;
use MissionDP\DonorDashboard\DonorDashboardModule;
use MissionDP\Models\Donor;
use WP_UnitTestCase;

/**
 * Donor dashboard module test class.
 *
 * Handlers are called directly on a test-owned module instance (init() is not
 * called, so no duplicate hooks); the email-sync wiring tests go through the
 * real actions registered by the plugin at boot.
 */
class DonorDashboardModuleTest extends WP_UnitTestCase {

	/**
	 * Module under test.
	 *
	 * @var DonorDashboardModule
	 */
	private DonorDashboardModule $module;

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Set up a fresh module before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->module = new DonorDashboardModule();
		$this->module->ensure_donor_role();
	}

	/**
	 * Clean up tables and options after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		// phpcs:enable

		delete_option( 'missiondp_dashboard_page_id' );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a WordPress user with only the donor role and a linked donor row.
	 *
	 * @param array<string, mixed> $donor_overrides Donor column overrides.
	 * @return array{0: int, 1: Donor} User ID and donor model.
	 */
	private function create_donor_user( array $donor_overrides = [] ): array {
		static $counter = 0;
		++$counter;

		$email   = "donor-user{$counter}@example.com";
		$user_id = self::factory()->user->create( [
			'role'       => 'missiondp_donor',
			'user_email' => $email,
		] );

		$donor = new Donor( array_merge(
			[
				'email'      => $email,
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'user_id'    => $user_id,
			],
			$donor_overrides
		) );
		$donor->save();

		return [ $user_id, $donor ];
	}

	/**
	 * Create the dashboard page and store its ID in the option.
	 *
	 * @return int Page ID.
	 */
	private function create_dashboard_page_fixture(): int {
		$page_id = self::factory()->post->create( [
			'post_type'  => 'page',
			'post_title' => 'Donor Dashboard',
		] );

		update_option( 'missiondp_dashboard_page_id', $page_id );

		return $page_id;
	}

	/**
	 * Run a callback that redirects, capturing the location instead of exiting.
	 *
	 * @param callable $fn Callback expected to call wp_safe_redirect + exit.
	 * @return string Captured redirect location, or empty string.
	 */
	private function capture_redirect( callable $fn ): string {
		$location = '';
		$callback = static function ( $url ) use ( &$location ) {
			$location = $url;
			throw new \RuntimeException( 'redirect-intercepted' );
		};

		add_filter( 'wp_redirect', $callback );

		try {
			$fn();
		} catch ( \RuntimeException $e ) {
			// Expected: the interception prevents the exit after the redirect.
		} finally {
			remove_filter( 'wp_redirect', $callback );
		}

		return $location;
	}

	// -------------------------------------------------------------------------
	// Role tests.
	// -------------------------------------------------------------------------

	/**
	 * Test the donor role is recreated when missing.
	 */
	public function test_ensure_donor_role_recreates_missing_role(): void {
		remove_role( 'missiondp_donor' );
		$this->assertNull( get_role( 'missiondp_donor' ) );

		$this->module->ensure_donor_role();

		$role = get_role( 'missiondp_donor' );
		$this->assertNotNull( $role );
		$this->assertSame( [], $role->capabilities );
	}

	// -------------------------------------------------------------------------
	// Redirect tests.
	// -------------------------------------------------------------------------

	/**
	 * Test donors are redirected away from wp-admin to the dashboard.
	 */
	public function test_donor_redirected_from_admin(): void {
		$page_id = $this->create_dashboard_page_fixture();
		[ $user_id ] = $this->create_donor_user();

		wp_set_current_user( $user_id );

		$location = $this->capture_redirect( fn() => $this->module->redirect_donor_from_admin() );

		$this->assertSame( get_permalink( $page_id ), $location );
	}

	/**
	 * Test admins are not redirected from wp-admin.
	 */
	public function test_admin_not_redirected_from_admin(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$location = $this->capture_redirect( fn() => $this->module->redirect_donor_from_admin() );

		$this->assertSame( '', $location );
	}

	/**
	 * Test a user with the donor role plus another role is not treated as a donor.
	 */
	public function test_multi_role_user_not_redirected(): void {
		[ $user_id ] = $this->create_donor_user();
		$user        = get_userdata( $user_id );
		$user->add_role( 'editor' );

		wp_set_current_user( $user_id );

		$location = $this->capture_redirect( fn() => $this->module->redirect_donor_from_admin() );

		$this->assertSame( '', $location );
	}

	/**
	 * Test the login redirect filter sends donors to the dashboard.
	 */
	public function test_login_redirect_for_donor(): void {
		$page_id = $this->create_dashboard_page_fixture();
		[ $user_id ] = $this->create_donor_user();

		$redirect = $this->module->redirect_donor_after_login( admin_url(), '', get_userdata( $user_id ) );

		$this->assertSame( get_permalink( $page_id ), $redirect );
	}

	/**
	 * Test the login redirect filter leaves non-donors alone.
	 */
	public function test_login_redirect_untouched_for_admin(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$redirect = $this->module->redirect_donor_after_login( admin_url(), '', get_userdata( $admin_id ) );

		$this->assertSame( admin_url(), $redirect );
	}

	// -------------------------------------------------------------------------
	// Email sync tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a donor email change propagates to the linked WordPress user.
	 */
	public function test_donor_email_syncs_to_wp_user(): void {
		[ $user_id, $donor ] = $this->create_donor_user();

		$donor->email = 'new-address@example.com';
		$donor->save();

		$this->module->sync_donor_email_to_wp_user( $donor );

		$this->assertSame( 'new-address@example.com', get_userdata( $user_id )->user_email );

		// The wp_update_user above fired profile_update, whose registered
		// sync handler must settle without looping; donor email unchanged.
		$this->assertSame( 'new-address@example.com', Donor::find( $donor->id )->email );
	}

	/**
	 * Test a WordPress user email change propagates to the donor record.
	 */
	public function test_wp_user_email_syncs_to_donor(): void {
		[ $user_id, $donor ] = $this->create_donor_user();

		// The plugin's profile_update hook handles the sync.
		wp_update_user( [
			'ID'         => $user_id,
			'user_email' => 'changed@example.com',
		] );

		$this->assertSame( 'changed@example.com', Donor::find( $donor->id )->email );
	}

	/**
	 * Test non-donor user email changes do not touch donor records.
	 */
	public function test_non_donor_user_email_change_ignored(): void {
		$admin_id = self::factory()->user->create( [
			'role'       => 'administrator',
			'user_email' => 'admin-sync@example.com',
		] );

		$donor = new Donor( [
			'email'   => 'admin-sync@example.com',
			'user_id' => $admin_id,
		] );
		$donor->save();

		wp_update_user( [
			'ID'         => $admin_id,
			'user_email' => 'changed-admin@example.com',
		] );

		$this->assertSame( 'admin-sync@example.com', Donor::find( $donor->id )->email );
	}

	/**
	 * Test deleting the WordPress user unlinks but keeps the donor row.
	 */
	public function test_user_delete_unlinks_donor(): void {
		[ $user_id, $donor ] = $this->create_donor_user();

		$this->module->unlink_donor_on_user_delete( $user_id );

		$saved = Donor::find( $donor->id );
		$this->assertNotNull( $saved );
		$this->assertNull( $saved->user_id );
	}

	// -------------------------------------------------------------------------
	// Password reset URL test.
	// -------------------------------------------------------------------------

	/**
	 * Test the reset message rewrites wp-login.php URLs to the dashboard for donors.
	 */
	public function test_password_reset_url_rewritten_for_donor(): void {
		$page_id = $this->create_dashboard_page_fixture();
		[ $user_id ] = $this->create_donor_user();

		$user         = get_userdata( $user_id );
		$key          = 'testkey123';
		$wp_reset_url = network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $user->user_login ), 'login' );
		$message      = "Reset your password here: $wp_reset_url\n";

		$filtered = $this->module->filter_password_reset_url( $message, $key, $user->user_login, $user );

		$this->assertStringNotContainsString( 'wp-login.php', $filtered );
		$this->assertStringContainsString( get_permalink( $page_id ), $filtered );
		$this->assertStringContainsString( 'action=reset-password', $filtered );
		$this->assertStringContainsString( "key=$key", $filtered );
	}

	/**
	 * Test the reset message is untouched for non-donors.
	 */
	public function test_password_reset_url_untouched_for_admin(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user     = get_userdata( $admin_id );
		$message  = 'Reset your password here: ' . network_site_url( 'wp-login.php?action=rp&key=k&login=' . rawurlencode( $user->user_login ), 'login' );

		$filtered = $this->module->filter_password_reset_url( $message, 'k', $user->user_login, $user );

		$this->assertSame( $message, $filtered );
	}

	// -------------------------------------------------------------------------
	// Admin bar and post state tests.
	// -------------------------------------------------------------------------

	/**
	 * Test the admin bar is hidden for donors and untouched for others.
	 */
	public function test_admin_bar_hidden_for_donors(): void {
		[ $user_id ] = $this->create_donor_user();
		wp_set_current_user( $user_id );
		$this->assertFalse( $this->module->hide_admin_bar_for_donors( true ) );

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
		$this->assertTrue( $this->module->hide_admin_bar_for_donors( true ) );
	}

	/**
	 * Test the dashboard page gets a post state label and other pages do not.
	 */
	public function test_dashboard_post_state_label(): void {
		$page_id  = $this->create_dashboard_page_fixture();
		$other_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$states = $this->module->add_dashboard_post_state( [], get_post( $page_id ) );
		$this->assertArrayHasKey( 'missiondp_donor_dashboard', $states );

		$states = $this->module->add_dashboard_post_state( [], get_post( $other_id ) );
		$this->assertSame( [], $states );
	}

	// -------------------------------------------------------------------------
	// Portal toggle tests.
	// -------------------------------------------------------------------------

	/**
	 * Test disabling the portal deletes the dashboard page and option.
	 */
	public function test_portal_toggle_off_deletes_dashboard_page(): void {
		$page_id = $this->create_dashboard_page_fixture();

		$this->module->handle_portal_toggle(
			[ 'donor_portal_enabled' => false ],
			[ 'donor_portal_enabled' => false ],
			[ 'donor_portal_enabled' => true ]
		);

		$this->assertNull( get_post( $page_id ) );
		$this->assertSame( 0, (int) get_option( 'missiondp_dashboard_page_id', 0 ) );
	}

	/**
	 * Test enabling the portal creates the dashboard page.
	 */
	public function test_portal_toggle_on_creates_dashboard_page(): void {
		$this->module->handle_portal_toggle(
			[ 'donor_portal_enabled' => true ],
			[ 'donor_portal_enabled' => true ],
			[ 'donor_portal_enabled' => false ]
		);

		$page_id = (int) get_option( 'missiondp_dashboard_page_id', 0 );
		$this->assertGreaterThan( 0, $page_id );

		$page = get_post( $page_id );
		$this->assertSame( 'publish', $page->post_status );
		$this->assertStringContainsString( 'mission-donation-platform/donor-dashboard', $page->post_content );
	}

	/**
	 * Test the toggle handler ignores updates that do not change the portal setting.
	 */
	public function test_portal_toggle_ignores_unrelated_settings_updates(): void {
		$page_id = $this->create_dashboard_page_fixture();

		$this->module->handle_portal_toggle(
			[ 'org_name' => 'New Name', 'donor_portal_enabled' => true ],
			[ 'org_name' => 'New Name' ],
			[ 'donor_portal_enabled' => true ]
		);

		$this->assertNotNull( get_post( $page_id ) );
		$this->assertSame( $page_id, (int) get_option( 'missiondp_dashboard_page_id' ) );
	}

	/**
	 * Test create_dashboard_page does not duplicate an existing published page.
	 */
	public function test_create_dashboard_page_is_idempotent(): void {
		DonorDashboardModule::create_dashboard_page();
		$first_id = (int) get_option( 'missiondp_dashboard_page_id' );

		DonorDashboardModule::create_dashboard_page();

		$this->assertSame( $first_id, (int) get_option( 'missiondp_dashboard_page_id' ) );
	}
}
