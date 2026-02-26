<?php
/**
 * Tests for the CampaignPostType class.
 *
 * @package Mission
 */

namespace Mission\Tests\Campaigns;

use Mission\Campaigns\CampaignPostType;
use Mission\Database\DatabaseModule;
use Mission\Database\DataStore\CampaignDataStore;
use WP_UnitTestCase;

/**
 * CampaignPostType test class.
 */
class CampaignPostTypeTest extends WP_UnitTestCase {

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
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

		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mission_campaigns" );

		parent::tear_down();
	}

	/**
	 * Test that the post type is registered.
	 */
	public function test_post_type_is_registered(): void {
		$this->assertTrue( post_type_exists( CampaignPostType::POST_TYPE ) );
	}

	/**
	 * Test that the post type supports expected features.
	 */
	public function test_post_type_supports_expected_features(): void {
		$this->assertTrue( post_type_supports( CampaignPostType::POST_TYPE, 'title' ) );
		$this->assertTrue( post_type_supports( CampaignPostType::POST_TYPE, 'editor' ) );
		$this->assertTrue( post_type_supports( CampaignPostType::POST_TYPE, 'thumbnail' ) );
		$this->assertTrue( post_type_supports( CampaignPostType::POST_TYPE, 'excerpt' ) );
		$this->assertTrue( post_type_supports( CampaignPostType::POST_TYPE, 'revisions' ) );
	}

	/**
	 * Test that the post type is public.
	 */
	public function test_post_type_is_public(): void {
		$post_type = get_post_type_object( CampaignPostType::POST_TYPE );

		$this->assertTrue( $post_type->public );
		$this->assertTrue( $post_type->has_archive );
	}

	/**
	 * Test that the post type shows in REST.
	 */
	public function test_post_type_shows_in_rest(): void {
		$post_type = get_post_type_object( CampaignPostType::POST_TYPE );

		$this->assertTrue( $post_type->show_in_rest );
	}

	/**
	 * Test that expected post meta keys are registered.
	 */
	public function test_post_meta_keys_registered(): void {
		$registered = get_registered_meta_keys( 'post', CampaignPostType::POST_TYPE );

		$spot_check = array(
			'_mission_campaign_amounts',
			'_mission_campaign_custom_amount',
			'_mission_campaign_recurring_enabled',
			'_mission_campaign_tip_enabled',
			'_mission_campaign_goal_amount',
		);

		foreach ( $spot_check as $key ) {
			$this->assertArrayHasKey( $key, $registered, "Meta key {$key} not registered." );
		}
	}

	/**
	 * Test that save_post syncs goal_amount to the campaigns table.
	 */
	public function test_save_post_syncs_goal_amount(): void {
		$cpt = new CampaignPostType();

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => CampaignPostType::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, '_mission_campaign_goal_amount', 50000 );

		// Simulate the save_post hook.
		$cpt->sync_goal_amount( $post_id, get_post( $post_id ) );

		$store    = new CampaignDataStore();
		$campaign = $store->find_by_post_id( $post_id );

		$this->assertNotNull( $campaign );
		$this->assertSame( 50000, $campaign->goal_amount );
	}

	/**
	 * Test that save_post updates existing campaign row.
	 */
	public function test_save_post_updates_existing_campaign(): void {
		$cpt = new CampaignPostType();

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => CampaignPostType::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, '_mission_campaign_goal_amount', 50000 );
		$cpt->sync_goal_amount( $post_id, get_post( $post_id ) );

		// Update the goal.
		update_post_meta( $post_id, '_mission_campaign_goal_amount', 75000 );
		$cpt->sync_goal_amount( $post_id, get_post( $post_id ) );

		$store    = new CampaignDataStore();
		$campaign = $store->find_by_post_id( $post_id );

		$this->assertSame( 75000, $campaign->goal_amount );
	}
}
