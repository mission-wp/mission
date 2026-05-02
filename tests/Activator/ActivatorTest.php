<?php
/**
 * Tests for the Activator class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Activator;

use MissionDP\Activator;
use MissionDP\Database\DatabaseModule;
use WP_UnitTestCase;

/**
 * Activator test class.
 */
class ActivatorTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( 'missiondp_version' );
		delete_option( 'missiondp_settings' );
		delete_option( DatabaseModule::DB_VERSION_OPTION );
		delete_transient( 'missiondp_activated' );

		// Remove capabilities.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( 'manage_missiondp' );
			$admin->remove_cap( 'view_missiondp_reports' );
			$admin->remove_cap( 'edit_missiondp_transactions' );
		}

		parent::tear_down();
	}

	/**
	 * Test that activation stores the plugin version option.
	 */
	public function test_stores_plugin_version(): void {
		Activator::activate();

		$this->assertSame( MISSIONDP_VERSION, get_option( 'missiondp_version' ) );
	}

	/**
	 * Test that activation sets the activated transient.
	 */
	public function test_sets_activated_transient(): void {
		Activator::activate();

		$this->assertTrue( (bool) get_transient( 'missiondp_activated' ) );
	}

	/**
	 * Test that activation adds custom capabilities to administrator role.
	 */
	public function test_adds_capabilities_to_administrator(): void {
		Activator::activate();

		$admin = get_role( 'administrator' );

		$this->assertTrue( $admin->has_cap( 'manage_missiondp' ) );
		$this->assertTrue( $admin->has_cap( 'view_missiondp_reports' ) );
		$this->assertTrue( $admin->has_cap( 'edit_missiondp_transactions' ) );
	}

	/**
	 * Test that activation sets default settings on fresh install.
	 */
	public function test_sets_default_settings_on_fresh_install(): void {
		Activator::activate();

		$settings = get_option( 'missiondp_settings' );

		$this->assertIsArray( $settings );
		$this->assertSame( 'USD', $settings['currency'] );
		$this->assertSame( '', $settings['stripe_site_id'] );
		$this->assertSame( '', $settings['stripe_site_token'] );
		$this->assertSame( '', $settings['stripe_account_id'] );
		$this->assertSame( 'disconnected', $settings['stripe_connection_status'] );
		$this->assertSame( '', $settings['stripe_display_name'] );
		$this->assertArrayHasKey( 'email_from_name', $settings );
		$this->assertArrayHasKey( 'email_from_address', $settings );
	}

	/**
	 * Test that activation does not overwrite existing settings.
	 */
	public function test_does_not_overwrite_existing_settings(): void {
		$custom_settings = array(
			'currency'    => 'EUR',
			'tip_enabled' => false,
		);
		update_option( 'missiondp_settings', $custom_settings );

		Activator::activate();

		$settings = get_option( 'missiondp_settings' );
		$this->assertSame( 'EUR', $settings['currency'] );
		$this->assertFalse( $settings['tip_enabled'] );
	}

	/**
	 * Test that activation stores the database version.
	 */
	public function test_stores_db_version(): void {
		Activator::activate();

		$this->assertSame(
			DatabaseModule::DB_VERSION,
			get_option( DatabaseModule::DB_VERSION_OPTION )
		);
	}
}
