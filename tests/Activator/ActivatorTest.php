<?php
/**
 * Tests for the Activator class.
 *
 * @package Mission
 */

namespace Mission\Tests\Activator;

use Mission\Activator;
use Mission\Database\DatabaseModule;
use WP_UnitTestCase;

/**
 * Activator test class.
 */
class ActivatorTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( 'mission_version' );
		delete_option( 'mission_settings' );
		delete_option( DatabaseModule::DB_VERSION_OPTION );
		delete_transient( 'mission_activated' );

		// Remove capabilities.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( 'manage_mission' );
			$admin->remove_cap( 'view_mission_reports' );
			$admin->remove_cap( 'edit_mission_transactions' );
		}

		parent::tear_down();
	}

	/**
	 * Test that activation stores the plugin version option.
	 */
	public function test_stores_plugin_version(): void {
		Activator::activate();

		$this->assertSame( MISSION_VERSION, get_option( 'mission_version' ) );
	}

	/**
	 * Test that activation sets the activated transient.
	 */
	public function test_sets_activated_transient(): void {
		Activator::activate();

		$this->assertTrue( (bool) get_transient( 'mission_activated' ) );
	}

	/**
	 * Test that activation adds custom capabilities to administrator role.
	 */
	public function test_adds_capabilities_to_administrator(): void {
		Activator::activate();

		$admin = get_role( 'administrator' );

		$this->assertTrue( $admin->has_cap( 'manage_mission' ) );
		$this->assertTrue( $admin->has_cap( 'view_mission_reports' ) );
		$this->assertTrue( $admin->has_cap( 'edit_mission_transactions' ) );
	}

	/**
	 * Test that activation sets default settings on fresh install.
	 */
	public function test_sets_default_settings_on_fresh_install(): void {
		Activator::activate();

		$settings = get_option( 'mission_settings' );

		$this->assertIsArray( $settings );
		$this->assertSame( 'USD', $settings['currency'] );
		$this->assertTrue( $settings['tip_enabled'] );
		$this->assertSame( 15, $settings['tip_default_percentage'] );
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
		update_option( 'mission_settings', $custom_settings );

		Activator::activate();

		$settings = get_option( 'mission_settings' );
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
