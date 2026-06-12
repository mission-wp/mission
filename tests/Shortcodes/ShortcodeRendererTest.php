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
use MissionDP\Models\Donor;
use MissionDP\Models\Transaction;
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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
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
	 * Create a completed donation attached to a campaign.
	 *
	 * @param Campaign $campaign The campaign.
	 * @param int      $amount   Donation amount in minor units.
	 * @return Transaction
	 */
	private function create_completed_donation( Campaign $campaign, int $amount = 2500 ): Transaction {
		static $counter = 0;
		++$counter;

		$donor = new Donor( [
			'email'      => "wall{$counter}@example.com",
			'first_name' => 'Wall',
			'last_name'  => 'Donor',
		] );
		$donor->save();

		$transaction = new Transaction( [
			'status'         => Transaction::STATUS_COMPLETED,
			'type'           => 'one_time',
			'donor_id'       => $donor->id,
			'campaign_id'    => $campaign->id,
			'amount'         => $amount,
			'total_amount'   => $amount,
			'currency'       => 'usd',
			'date_completed' => current_time( 'mysql', true ),
		] );
		$transaction->save_silent();

		return $transaction;
	}

	/**
	 * The donor wall output survives kses intact when donations exist.
	 *
	 * Regression test: kses stripped the data-wp-each template element (so no
	 * donor cards ever rendered) and escaped the footer tag into visible text
	 * because its directive held a "<" comparison instead of a state reference.
	 */
	public function test_donor_wall_with_donations_keeps_template_and_footer(): void {
		update_option( 'missiondp_settings', [ 'test_mode' => false ] );
		$campaign = $this->create_campaign();
		$this->create_completed_donation( $campaign );

		$output = do_shortcode( '[mission_donor_wall campaign_id="' . $campaign->id . '"]' );

		$this->assertStringContainsString( '<template data-wp-each--donor="context.items">', $output );
		$this->assertStringContainsString( 'data-wp-class--is-hidden="context.footerHidden"', $output );
		$this->assertStringNotContainsString( '&lt;div', $output );
		$this->assertStringNotContainsString( '&#8211;', $output );

		// One donation fits on a single page, so the footer starts hidden.
		$this->assertStringContainsString( 'mission-dw-footer is-hidden', $output );
	}

	/**
	 * The footer is visible when more donors exist than fit on one page.
	 */
	public function test_donor_wall_footer_visible_when_paginated(): void {
		update_option( 'missiondp_settings', [ 'test_mode' => false ] );
		$campaign = $this->create_campaign();
		$this->create_completed_donation( $campaign, 1000 );
		$this->create_completed_donation( $campaign, 2000 );

		$output = do_shortcode(
			'[mission_donor_wall campaign_id="' . $campaign->id . '" donors_per_page="1"]'
		);

		$this->assertStringContainsString( 'class="mission-dw-footer"', $output );
		$this->assertStringNotContainsString( 'mission-dw-footer is-hidden', $output );
	}

	/**
	 * The mission_shortcode_attributes filter can change what renders.
	 */
	public function test_attributes_filter_affects_output(): void {
		$force_text = static function ( array $attributes, string $block_name ): array {
			if ( 'mission-donation-platform/donate-button' === $block_name ) {
				$attributes['text'] = 'Filtered Label';
			}
			return $attributes;
		};

		add_filter( 'mission_shortcode_attributes', $force_text, 10, 2 );
		$output = do_shortcode( '[mission_donate_button]' );
		remove_filter( 'mission_shortcode_attributes', $force_text );

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

	// -------------------------------------------------------------------------
	// Campaign form inheritance tests.
	// -------------------------------------------------------------------------

	/**
	 * Create a campaign whose page contains a customized donation form block.
	 *
	 * @param array<string, mixed> $form_attrs Donation form block attributes.
	 * @return Campaign
	 */
	private function create_campaign_with_form( array $form_attrs ): Campaign {
		$campaign = $this->create_campaign();

		wp_update_post(
			[
				'ID'           => $campaign->post_id,
				'post_content' => '<!-- wp:mission-donation-platform/donation-form ' . wp_json_encode( $form_attrs ) . ' /-->',
			]
		);

		return $campaign;
	}

	/**
	 * The donation form shortcode inherits the form saved on the campaign page.
	 */
	public function test_donation_form_inherits_campaign_form_settings(): void {
		$campaign = $this->create_campaign_with_form(
			[
				'recurringEnabled'  => false,
				'chooseGiftHeading' => 'Support Our Cause',
				'stripeAccountId'   => 'acct_123',
			]
		);

		$output = do_shortcode( '[mission_donation_form campaign_id="' . $campaign->id . '"]' );

		$this->assertStringContainsString( 'Support Our Cause', $output );
		$this->assertStringContainsString( 'acct_123', $output );
		$this->assertStringNotContainsString( 'mission-df-frequency-toggle', $output );
	}

	/**
	 * Attributes set on the shortcode override inherited campaign values.
	 */
	public function test_donation_form_shortcode_attributes_override_inherited(): void {
		$campaign = $this->create_campaign_with_form(
			[
				'recurringEnabled'  => false,
				'chooseGiftHeading' => 'Support Our Cause',
			]
		);

		$output = do_shortcode(
			'[mission_donation_form campaign_id="' . $campaign->id . '" choose_gift_heading="Pick An Amount"]'
		);

		$this->assertStringContainsString( 'Pick An Amount', $output );
		$this->assertStringNotContainsString( 'Support Our Cause', $output );
		// Attributes the shortcode does not set are still inherited.
		$this->assertStringNotContainsString( 'mission-df-frequency-toggle', $output );
	}

	/**
	 * An unknown campaign ID falls back to the default form without erroring.
	 */
	public function test_donation_form_with_unknown_campaign_uses_defaults(): void {
		$output = do_shortcode( '[mission_donation_form campaign_id="999999"]' );

		$this->assertStringContainsString( 'data-wp-interactive', $output );
		$this->assertStringContainsString( 'mission-df-frequency-toggle', $output );
	}

	/**
	 * The donation form block does not inherit; only the shortcode does.
	 */
	public function test_donation_form_block_does_not_inherit(): void {
		$campaign = $this->create_campaign_with_form(
			[
				'recurringEnabled'  => false,
				'chooseGiftHeading' => 'Support Our Cause',
			]
		);

		$output = do_blocks( '<!-- wp:mission-donation-platform/donation-form {"campaignId":' . $campaign->id . '} /-->' );

		$this->assertStringNotContainsString( 'Support Our Cause', $output );
		$this->assertStringContainsString( 'mission-df-frequency-toggle', $output );
	}
}
