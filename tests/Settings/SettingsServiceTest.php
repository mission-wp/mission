<?php
/**
 * Tests for the SettingsService class.
 *
 * @package Mission
 */

namespace Mission\Tests\Settings;

use Mission\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * SettingsService test class.
 */
class SettingsServiceTest extends WP_UnitTestCase {

	/**
	 * Service instance.
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->service = new SettingsService();
		delete_option( SettingsService::OPTION_NAME );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( SettingsService::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Test get_all returns defaults when no option exists.
	 */
	public function test_get_all_returns_defaults_when_no_option(): void {
		$all = $this->service->get_all();

		$this->assertSame( 'USD', $all['currency'] );
		$this->assertTrue( $all['tip_enabled'] );
		$this->assertSame( 15, $all['tip_default_percentage'] );
		$this->assertSame( '', $all['stripe_site_id'] );
		$this->assertSame( '', $all['stripe_site_token'] );
		$this->assertSame( 'disconnected', $all['stripe_connection_status'] );
		$this->assertSame( '', $all['stripe_display_name'] );
	}

	/**
	 * Test get_all merges stored settings with defaults.
	 */
	public function test_get_all_merges_stored_with_defaults(): void {
		update_option( SettingsService::OPTION_NAME, array( 'currency' => 'EUR' ) );

		$all = $this->service->get_all();

		$this->assertSame( 'EUR', $all['currency'] );
		// Defaults still present for keys not in stored option.
		$this->assertTrue( $all['tip_enabled'] );
		$this->assertSame( '', $all['stripe_site_id'] );
	}

	/**
	 * Test get returns a single setting value.
	 */
	public function test_get_returns_single_setting(): void {
		update_option( SettingsService::OPTION_NAME, array( 'currency' => 'GBP' ) );

		$this->assertSame( 'GBP', $this->service->get( 'currency' ) );
	}

	/**
	 * Test get returns default for unknown key.
	 */
	public function test_get_returns_default_for_unknown_key(): void {
		$this->assertSame( 'fallback', $this->service->get( 'nonexistent', 'fallback' ) );
	}

	/**
	 * Test update merges and saves settings.
	 */
	public function test_update_merges_and_saves(): void {
		$result = $this->service->update( array( 'currency' => 'CAD' ) );

		$this->assertSame( 'CAD', $result['currency'] );
		$this->assertTrue( $result['tip_enabled'] );

		// Verify persisted to database.
		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertSame( 'CAD', $stored['currency'] );
	}

	/**
	 * Test update fires action hook.
	 */
	public function test_update_fires_action_hook(): void {
		$fired = false;

		add_action(
			'mission_settings_updated',
			static function ( $updated, $values, $previous ) use ( &$fired ) {
				$fired = true;
			},
			10,
			3
		);

		$this->service->update( array( 'currency' => 'JPY' ) );

		$this->assertTrue( $fired );
	}

	/**
	 * Test get_defaults is filterable.
	 */
	public function test_get_defaults_is_filterable(): void {
		add_filter(
			'mission_settings_defaults',
			static function ( $defaults ) {
				$defaults['custom_setting'] = 'custom_value';
				return $defaults;
			}
		);

		$defaults = $this->service->get_defaults();

		$this->assertSame( 'custom_value', $defaults['custom_setting'] );

		remove_all_filters( 'mission_settings_defaults' );
	}
}
