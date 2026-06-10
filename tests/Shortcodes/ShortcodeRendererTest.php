<?php
/**
 * Integration tests for shortcode rendering through the block pipeline.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Shortcodes;

use MissionDP\Campaigns\CampaignPostType;
use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * ShortcodeRenderer test class.
 */
class ShortcodeRendererTest extends WP_UnitTestCase {

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! WP_Block_Type_Registry::get_instance()->get_registered( 'mission-donation-platform/donate-button' ) ) {
			$this->markTestSkipped( 'Mission blocks are not registered; run npm run build first.' );
		}

		$cpt = new CampaignPostType();
		$cpt->register();
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

		delete_option( 'missiondp_settings' );
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

	/**
	 * String attributes reach the rendered block output.
	 */
	public function test_donate_button_renders_custom_text(): void {
		$output = do_shortcode( '[mission_donate_button text="Give Now"]' );

		$this->assertStringContainsString( 'Give Now', $output );
	}

	/**
	 * A campaign-scoped shortcode renders the block for that campaign.
	 */
	public function test_campaign_progress_renders_for_campaign(): void {
		$campaign = $this->create_campaign();

		$output = do_shortcode( '[mission_campaign_progress campaign_id="' . $campaign->id . '"]' );

		$this->assertStringContainsString( 'mission-cp-', $output );
	}

	/**
	 * An unknown campaign ID renders nothing rather than erroring.
	 */
	public function test_campaign_progress_with_unknown_campaign_is_empty(): void {
		$this->assertSame( '', trim( do_shortcode( '[mission_campaign_progress campaign_id="999999"]' ) ) );
	}

	/**
	 * The donor wall requires an explicit campaign and renders nothing without one.
	 */
	public function test_donor_wall_without_campaign_is_empty(): void {
		$this->assertSame( '', trim( do_shortcode( '[mission_donor_wall]' ) ) );
	}

	/**
	 * The missiondp_shortcode_attributes filter can change what renders.
	 */
	public function test_attributes_filter_affects_output(): void {
		$force_text = static function ( array $attributes, string $block_name ): array {
			if ( 'mission-donation-platform/donate-button' === $block_name ) {
				$attributes['text'] = 'Filtered Label';
			}
			return $attributes;
		};

		add_filter( 'missiondp_shortcode_attributes', $force_text, 10, 2 );
		$output = do_shortcode( '[mission_donate_button]' );
		remove_filter( 'missiondp_shortcode_attributes', $force_text );

		$this->assertStringContainsString( 'Filtered Label', $output );
	}

	/**
	 * Block comment markup never leaks through the_content.
	 */
	public function test_no_block_comment_leaks_through_the_content(): void {
		$output = apply_filters( 'the_content', '[mission_donate_button]' );

		$this->assertStringNotContainsString( '<!-- wp:', $output );
		$this->assertStringContainsString( 'mission', $output );
	}

	/**
	 * The donation form shortcode renders the interactive form wrapper.
	 */
	public function test_donation_form_renders(): void {
		$output = do_shortcode( '[mission_donation_form]' );

		$this->assertStringContainsString( 'data-wp-interactive', $output );
	}
}
