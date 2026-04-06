<?php
/**
 * Tests for the CampaignPostType class.
 *
 * @package Mission
 */

namespace Mission\Tests\Campaigns;

use Mission\Campaigns\CampaignPostType;
use Mission\Database\DatabaseModule;
use Mission\Models\Campaign;
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

		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );

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
	 * Test that a disabled campaign page returns 404 on the frontend.
	 */
	public function test_disabled_campaign_page_returns_404(): void {
		$campaign = $this->create_campaign();

		// Disable the campaign page (sets meta to false and drafts the post).
		$campaign->set_campaign_page_enabled( false );

		// Re-publish so go_to() can resolve the URL to the campaign post.
		wp_update_post( [
			'ID'          => $campaign->post_id,
			'post_status' => 'publish',
		] );

		$this->go_to( get_permalink( $campaign->post_id ) );

		$cpt = new CampaignPostType();
		$cpt->block_disabled_campaign_pages();

		global $wp_query;
		$this->assertTrue( $wp_query->is_404() );
	}

	/**
	 * Test that an enabled campaign page does not return 404.
	 */
	public function test_enabled_campaign_page_does_not_return_404(): void {
		$campaign = $this->create_campaign();

		// has_campaign_page defaults to true — no need to explicitly enable.
		$this->go_to( get_permalink( $campaign->post_id ) );

		$cpt = new CampaignPostType();
		$cpt->block_disabled_campaign_pages();

		global $wp_query;
		$this->assertFalse( $wp_query->is_404() );
	}

	/**
	 * Test that slug is locked on an existing campaign post.
	 */
	public function test_slug_is_locked_on_existing_post(): void {
		$campaign      = $this->create_campaign();
		$original_post = get_post( $campaign->post_id );

		$prepared              = new \stdClass();
		$prepared->ID          = $campaign->post_id;
		$prepared->post_name   = 'hacked-slug';
		$prepared->post_status = 'draft';

		$cpt    = new CampaignPostType();
		$result = $cpt->lock_slug_and_status( $prepared );

		$this->assertSame( $original_post->post_name, $result->post_name );
		$this->assertSame( $original_post->post_status, $result->post_status );
	}

	/**
	 * Test that slug and status are not locked for new posts (no ID).
	 */
	public function test_slug_not_locked_for_new_posts(): void {
		$prepared              = new \stdClass();
		$prepared->post_name   = 'brand-new-slug';
		$prepared->post_status = 'publish';

		$cpt    = new CampaignPostType();
		$result = $cpt->lock_slug_and_status( $prepared );

		$this->assertSame( 'brand-new-slug', $result->post_name );
		$this->assertSame( 'publish', $result->post_status );
	}

	/**
	 * Test that campaign posts cannot be deleted through the editor.
	 */
	public function test_editor_cannot_delete_campaign_post(): void {
		$campaign = $this->create_campaign();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$cpt    = new CampaignPostType();
		$result = $cpt->restrict_editor_delete(
			[ 'delete_posts' ],
			'delete_post',
			$admin_id,
			[ $campaign->post_id ]
		);

		$this->assertSame( [ 'do_not_allow' ], $result );
	}

	/**
	 * Test that non-campaign posts are not affected by the editor delete restriction.
	 */
	public function test_editor_can_delete_non_campaign_post(): void {
		$post_id  = self::factory()->post->create();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$cpt    = new CampaignPostType();
		$result = $cpt->restrict_editor_delete(
			[ 'delete_posts' ],
			'delete_post',
			$admin_id,
			[ $post_id ]
		);

		$this->assertSame( [ 'delete_posts' ], $result );
	}

	/**
	 * Test that non-delete capabilities pass through unrestricted.
	 */
	public function test_non_delete_cap_passes_through(): void {
		$campaign = $this->create_campaign();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$cpt    = new CampaignPostType();
		$result = $cpt->restrict_editor_delete(
			[ 'edit_posts' ],
			'edit_post',
			$admin_id,
			[ $campaign->post_id ]
		);

		$this->assertSame( [ 'edit_posts' ], $result );
	}

	/**
	 * Create a campaign for testing.
	 *
	 * @param array $overrides Optional field overrides.
	 *
	 * @return Campaign
	 */
	private function create_campaign( array $overrides = [] ): Campaign {
		$campaign = new Campaign( array_merge(
			[ 'title' => 'Test Campaign' ],
			$overrides
		) );
		$campaign->save();

		return $campaign;
	}
}
