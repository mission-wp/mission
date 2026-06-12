<?php
/**
 * Tests for the Activator class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Activator;

use MissionDP\Activator;
use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use WP_UnitTestCase;

/**
 * Activator test class.
 */
class ActivatorTest extends WP_UnitTestCase {

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable

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
	 * Test that a fresh install with no conflicting content uses the default campaign slug.
	 */
	public function test_fresh_install_uses_default_campaign_slug(): void {
		Activator::activate();

		$settings = get_option( 'missiondp_settings' );

		$this->assertSame( 'campaigns', $settings['campaign_url_slug'] );
	}

	/**
	 * Test that a fresh install picks the next candidate when "campaigns" is taken.
	 */
	public function test_fresh_install_avoids_existing_campaigns_page(): void {
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'campaigns',
				'post_status' => 'publish',
			]
		);

		Activator::activate();

		$settings = get_option( 'missiondp_settings' );

		$this->assertSame( 'giving', $settings['campaign_url_slug'] );
	}

	/**
	 * Test that a fresh install falls back to a numbered slug when every candidate is taken.
	 */
	public function test_fresh_install_falls_back_to_numbered_slug(): void {
		foreach ( [ 'campaigns', 'giving', 'fundraisers', 'mission-campaigns' ] as $slug ) {
			self::factory()->post->create(
				[
					'post_type'   => 'page',
					'post_name'   => $slug,
					'post_status' => 'publish',
				]
			);
		}

		Activator::activate();

		$settings = get_option( 'missiondp_settings' );

		$this->assertSame( 'campaigns-2', $settings['campaign_url_slug'] );
	}

	/**
	 * Test that reactivating an existing install does not add the slug setting,
	 * and that it resolves to the default through the settings service.
	 */
	public function test_existing_install_keeps_resolving_default_slug(): void {
		update_option( 'missiondp_settings', [ 'currency' => 'EUR' ] );
		update_option( 'missiondp_version', '1.2.0' );

		Activator::activate();

		$stored = get_option( 'missiondp_settings' );

		$this->assertArrayNotHasKey( 'campaign_url_slug', $stored );
		$this->assertSame(
			'campaigns',
			( new \MissionDP\Settings\SettingsService() )->get( 'campaign_url_slug' )
		);
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
	 * Test that activation backfills milestones only for campaigns missing them.
	 */
	public function test_backfills_milestones_only_for_campaigns_missing_them(): void {
		$missing = new Campaign( [ 'title' => 'Needs Backfill' ] );
		$missing->save();
		$missing->delete_meta( 'milestones' );

		$sentinel = [
			[
				'id'      => 'created',
				'reached' => true,
			],
		];
		$existing = new Campaign( [ 'title' => 'Already Has Milestones' ] );
		$existing->save();
		$existing->update_meta( 'milestones', $sentinel );

		Activator::activate();

		$backfilled = $missing->get_meta( 'milestones' );
		$this->assertNotEmpty( $backfilled );
		$this->assertSame( 'created', $backfilled[0]['id'] );

		// Untouched: a recompile would have replaced the sentinel.
		$this->assertSame( $sentinel, $existing->get_meta( 'milestones' ) );
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
