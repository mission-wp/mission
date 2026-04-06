<?php
/**
 * Tests for the DonationFormSettings class.
 *
 * @package Mission
 */

namespace Mission\Tests\Blocks;

use Mission\Blocks\DonationFormSettings;
use Mission\Campaigns\CampaignPostType;
use Mission\Database\DatabaseModule;
use Mission\Models\Campaign;
use WP_UnitTestCase;

/**
 * DonationFormSettings test class.
 */
class DonationFormSettingsTest extends WP_UnitTestCase {

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaignmeta" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaigns" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$cpt = new CampaignPostType();
		$cpt->register();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );
		// phpcs:enable

		delete_option( 'mission_settings' );
		wp_reset_postdata();

		parent::tear_down();
	}

	/**
	 * Create and save a campaign.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Campaign
	 */
	private function create_campaign( array $overrides = [] ): Campaign {
		$campaign = new Campaign( array_merge(
			[
				'title'       => 'Test Campaign',
				'description' => 'A test campaign.',
			],
			$overrides
		) );
		$campaign->save();

		return $campaign;
	}

	// -------------------------------------------------------------------------
	// Default resolution tests.
	// -------------------------------------------------------------------------

	/**
	 * Test resolve returns all default values when no attributes are passed.
	 */
	public function test_resolve_returns_all_default_values_when_no_attributes(): void {
		$result = DonationFormSettings::resolve( [] );

		// Amount defaults.
		$this->assertSame(
			[ 'one_time' => [ 1000, 2500, 5000, 10000 ], 'monthly' => [ 1000, 2500, 5000, 10000 ] ],
			$result['amountsByFrequency']
		);
		$this->assertSame( [], $result['defaultAmounts'] );
		$this->assertTrue( $result['customAmount'] );
		$this->assertSame( 500, $result['minimumAmount'] );

		// Recurring defaults.
		$this->assertTrue( $result['recurringEnabled'] );
		$this->assertSame( [ 'monthly', 'quarterly', 'annually' ], $result['recurringFrequencies'] );
		$this->assertSame( 'one_time', $result['recurringDefault'] );

		// Fee/tip defaults.
		$this->assertTrue( $result['feeRecovery'] );
		$this->assertSame( 'optional', $result['feeMode'] );
		$this->assertTrue( $result['tipEnabled'] );
		$this->assertSame( [ 5, 10, 15, 20 ], $result['tipPercentages'] );

		// Form field defaults.
		$this->assertTrue( $result['collectAddress'] );
		$this->assertFalse( $result['anonymousEnabled'] );
		$this->assertFalse( $result['tributeEnabled'] );
		$this->assertFalse( $result['commentsEnabled'] );
		$this->assertFalse( $result['phoneRequired'] );

		// Confirmation defaults.
		$this->assertSame( 'message', $result['confirmationType'] );
		$this->assertSame( '', $result['confirmationRedirectUrl'] );

		// Display defaults.
		$this->assertSame( [], $result['amountDescriptions'] );
		$this->assertSame( '', $result['primaryColor'] );
		$this->assertSame( '', $result['continueButtonText'] );
		$this->assertSame( '', $result['donateButtonText'] );
		$this->assertSame( '', $result['chooseGiftHeading'] );
		$this->assertSame( '', $result['summaryHeading'] );
		$this->assertSame( '', $result['additionalInfoHeading'] );
		$this->assertSame( [], $result['customFields'] );

		// Runtime values.
		$this->assertSame( 'USD', $result['currency'] );
		$this->assertSame( '#2fa36b', $result['globalPrimaryColor'] );
		$this->assertIsString( $result['siteName'] );
	}

	// -------------------------------------------------------------------------
	// Attribute override tests.
	// -------------------------------------------------------------------------

	/**
	 * Test resolve merges block attributes over defaults.
	 */
	public function test_resolve_block_attributes_override_defaults(): void {
		$overrides = [
			'minimumAmount'        => 1000,
			'customAmount'         => false,
			'recurringEnabled'     => false,
			'recurringDefault'     => 'monthly',
			'feeRecovery'          => false,
			'feeMode'              => 'always',
			'tipEnabled'           => false,
			'tipPercentages'       => [ 10, 20 ],
			'collectAddress'       => false,
			'anonymousEnabled'     => true,
			'tributeEnabled'       => true,
			'commentsEnabled'      => true,
			'phoneRequired'        => true,
			'confirmationType'     => 'redirect',
			'donateButtonText'     => 'Give Now',
		];

		$result = DonationFormSettings::resolve( $overrides );

		// Overridden values.
		$this->assertSame( 1000, $result['minimumAmount'] );
		$this->assertFalse( $result['customAmount'] );
		$this->assertFalse( $result['recurringEnabled'] );
		$this->assertSame( 'monthly', $result['recurringDefault'] );
		$this->assertFalse( $result['feeRecovery'] );
		$this->assertSame( 'always', $result['feeMode'] );
		$this->assertFalse( $result['tipEnabled'] );
		$this->assertSame( [ 10, 20 ], $result['tipPercentages'] );
		$this->assertFalse( $result['collectAddress'] );
		$this->assertTrue( $result['anonymousEnabled'] );
		$this->assertTrue( $result['tributeEnabled'] );
		$this->assertTrue( $result['commentsEnabled'] );
		$this->assertTrue( $result['phoneRequired'] );
		$this->assertSame( 'redirect', $result['confirmationType'] );
		$this->assertSame( 'Give Now', $result['donateButtonText'] );

		// Non-overridden keys keep defaults.
		$this->assertSame(
			[ 'one_time' => [ 1000, 2500, 5000, 10000 ], 'monthly' => [ 1000, 2500, 5000, 10000 ] ],
			$result['amountsByFrequency']
		);
		$this->assertSame( [], $result['defaultAmounts'] );
		$this->assertSame( [ 'monthly', 'quarterly', 'annually' ], $result['recurringFrequencies'] );
		$this->assertSame( '', $result['confirmationRedirectUrl'] );
		$this->assertSame( '', $result['chooseGiftHeading'] );
	}

	// -------------------------------------------------------------------------
	// Custom fields tests.
	// -------------------------------------------------------------------------

	/**
	 * Test resolve includes custom fields from attributes as a passthrough.
	 */
	public function test_resolve_includes_custom_fields_from_attributes(): void {
		$custom_fields = [
			[
				'type'     => 'text',
				'label'    => 'Company Name',
				'required' => true,
			],
			[
				'type'     => 'select',
				'label'    => 'How did you hear about us?',
				'required' => false,
				'options'  => [ 'Search', 'Social Media', 'Friend' ],
			],
		];

		$result = DonationFormSettings::resolve( [ 'customFields' => $custom_fields ] );

		$this->assertSame( $custom_fields, $result['customFields'] );
	}

	// -------------------------------------------------------------------------
	// Currency tests.
	// -------------------------------------------------------------------------

	/**
	 * Test resolve includes currency from settings option.
	 */
	public function test_resolve_includes_currency_from_settings(): void {
		update_option( 'mission_settings', [ 'currency' => 'EUR' ] );

		$result = DonationFormSettings::resolve( [] );

		$this->assertSame( 'EUR', $result['currency'] );
	}

	/**
	 * Test resolve defaults currency to USD when no option is set.
	 */
	public function test_resolve_defaults_currency_to_usd(): void {
		$result = DonationFormSettings::resolve( [] );

		$this->assertSame( 'USD', $result['currency'] );
	}

	// -------------------------------------------------------------------------
	// Color and branding tests.
	// -------------------------------------------------------------------------

	/**
	 * Test resolve includes color and branding tokens.
	 */
	public function test_resolve_includes_color_and_branding_tokens(): void {
		update_option( 'mission_settings', [ 'primary_color' => '#ff5500' ] );
		update_option( 'blogname', 'My Nonprofit' );

		$result = DonationFormSettings::resolve( [ 'primaryColor' => '#003366' ] );

		$this->assertSame( '#ff5500', $result['globalPrimaryColor'] );
		$this->assertSame( '#003366', $result['primaryColor'] );
		$this->assertSame( 'My Nonprofit', $result['siteName'] );
	}

	/**
	 * Test resolve uses default global color when no option is set.
	 */
	public function test_resolve_default_global_color(): void {
		$result = DonationFormSettings::resolve( [] );

		$this->assertSame( '#2fa36b', $result['globalPrimaryColor'] );
	}

	// -------------------------------------------------------------------------
	// Sanitization tests.
	// -------------------------------------------------------------------------

	/**
	 * Test resolve sanitizes invalid hex color to empty string.
	 */
	public function test_resolve_sanitizes_invalid_hex_color_to_empty(): void {
		$result = DonationFormSettings::resolve( [ 'primaryColor' => 'not-a-color' ] );

		$this->assertSame( '', $result['primaryColor'] );
	}

	/**
	 * Test resolve preserves valid hex colors.
	 */
	public function test_resolve_preserves_valid_hex_color(): void {
		$result = DonationFormSettings::resolve( [ 'primaryColor' => '#abc' ] );

		$this->assertSame( '#abc', $result['primaryColor'] );
	}

	// -------------------------------------------------------------------------
	// Unknown attributes test.
	// -------------------------------------------------------------------------

	/**
	 * Test resolve ignores unknown attributes not in DEFAULTS.
	 */
	public function test_resolve_unknown_attributes_are_ignored(): void {
		$result = DonationFormSettings::resolve( [
			'unknownKey'  => 'should not appear',
			'anotherFake' => 42,
		] );

		$this->assertArrayNotHasKey( 'unknownKey', $result );
		$this->assertArrayNotHasKey( 'anotherFake', $result );
	}

	// -------------------------------------------------------------------------
	// Campaign ID resolution tests.
	// -------------------------------------------------------------------------

	/**
	 * Test resolve uses explicit campaignId attribute.
	 */
	public function test_resolve_campaign_id_from_explicit_attribute(): void {
		$result = DonationFormSettings::resolve( [ 'campaignId' => 42 ] );

		$this->assertSame( 42, $result['campaignId'] );
	}

	/**
	 * Test resolve auto-detects campaign from current campaign post.
	 */
	public function test_resolve_campaign_id_auto_detects_from_campaign_post(): void {
		$campaign = $this->create_campaign();

		// Simulate being on a campaign post page.
		$GLOBALS['post'] = get_post( $campaign->post_id );
		setup_postdata( $GLOBALS['post'] );

		$result = DonationFormSettings::resolve( [] );

		$this->assertSame( $campaign->id, $result['campaignId'] );
	}

	/**
	 * Test resolve returns 0 campaign ID when no context is available.
	 */
	public function test_resolve_campaign_id_zero_when_no_context(): void {
		$result = DonationFormSettings::resolve( [] );

		$this->assertSame( 0, $result['campaignId'] );
	}

	// -------------------------------------------------------------------------
	// Filter hook test.
	// -------------------------------------------------------------------------

	/**
	 * Test resolve settings are filterable via mission_donation_form_settings.
	 */
	public function test_resolve_settings_are_filterable(): void {
		$filter_args = [];

		add_filter(
			'mission_donation_form_settings',
			function ( $settings, $attributes, $campaign_id ) use ( &$filter_args ) {
				$filter_args = [
					'settings'    => $settings,
					'attributes'  => $attributes,
					'campaign_id' => $campaign_id,
				];
				$settings['customKey'] = 'injected';
				return $settings;
			},
			10,
			3
		);

		$attrs  = [ 'minimumAmount' => 999 ];
		$result = DonationFormSettings::resolve( $attrs );

		// Filter received correct params.
		$this->assertSame( 999, $filter_args['settings']['minimumAmount'] );
		$this->assertSame( $attrs, $filter_args['attributes'] );
		$this->assertIsInt( $filter_args['campaign_id'] );

		// Filter modified the output.
		$this->assertSame( 'injected', $result['customKey'] );

		remove_all_filters( 'mission_donation_form_settings' );
	}
}
