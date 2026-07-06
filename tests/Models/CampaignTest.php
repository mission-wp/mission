<?php
/**
 * Tests for the Campaign model.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Models;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\Transaction;
use WP_UnitTestCase;

/**
 * Campaign model test class.
 */
class CampaignTest extends WP_UnitTestCase {

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Clean up campaign tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable

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
	 * Create a fake image attachment.
	 *
	 * @return int Attachment ID.
	 */
	private function create_attachment(): int {
		return self::factory()->attachment->create( [
			'post_mime_type' => 'image/jpeg',
			'file'          => 'test-image.jpg',
		] );
	}

	// -------------------------------------------------------------------------
	// Construction tests.
	// -------------------------------------------------------------------------

	/**
	 * Test default values on empty construction.
	 */
	public function test_defaults_on_empty_construction(): void {
		$campaign = new Campaign();

		$this->assertNull( $campaign->id );
		$this->assertSame( 0, $campaign->post_id );
		$this->assertSame( 0, $campaign->goal_amount );
		$this->assertSame( 0, $campaign->total_raised );
		$this->assertSame( 0, $campaign->transaction_count );
		$this->assertSame( 'usd', $campaign->currency );
		$this->assertSame( 'active', $campaign->status );
		$this->assertTrue( $campaign->show_in_listings );
	}

	/**
	 * Test full construction from array.
	 */
	public function test_full_construction_from_array(): void {
		$campaign = new Campaign(
			array(
				'id'          => 3,
				'post_id'     => 42,
				'goal_amount' => 100000,
				'currency'    => 'eur',
			)
		);

		$this->assertSame( 3, $campaign->id );
		$this->assertSame( 42, $campaign->post_id );
		$this->assertSame( 100000, $campaign->goal_amount );
		$this->assertSame( 'eur', $campaign->currency );
	}

	/**
	 * Test show_in_listings can be set to false.
	 */
	public function test_show_in_listings_can_be_set_false(): void {
		$campaign = new Campaign( [ 'show_in_listings' => 0 ] );

		$this->assertFalse( $campaign->show_in_listings );
	}

	/**
	 * Test nullable fields are null when omitted.
	 */
	public function test_nullable_fields_are_null_when_omitted(): void {
		$campaign = new Campaign();

		$this->assertNull( $campaign->id );
		$this->assertNull( $campaign->date_start );
		$this->assertNull( $campaign->date_end );
	}

	// -------------------------------------------------------------------------
	// Status property tests.
	// -------------------------------------------------------------------------

	/**
	 * Test status defaults to "active".
	 */
	public function test_status_defaults_to_active(): void {
		$campaign = new Campaign();

		$this->assertSame( 'active', $campaign->status );
	}

	/**
	 * Test status is hydrated from data array.
	 */
	public function test_status_hydrated_from_data(): void {
		$campaign = new Campaign( [ 'status' => 'scheduled' ] );
		$this->assertSame( 'scheduled', $campaign->status );

		$campaign = new Campaign( [ 'status' => 'ended' ] );
		$this->assertSame( 'ended', $campaign->status );
	}

	/**
	 * Test status persists through save and find.
	 */
	public function test_status_persists_through_save_and_find(): void {
		$campaign = $this->create_campaign( [ 'status' => 'scheduled' ] );

		$found = Campaign::find( $campaign->id );
		$this->assertSame( 'scheduled', $found->status );
	}

	// -------------------------------------------------------------------------
	// save() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test save() creates a WP post on first insert.
	 */
	public function test_save_creates_wp_post_on_insert(): void {
		$campaign = new Campaign( [
			'title'       => 'Save Test',
			'description' => 'Testing save.',
		] );

		$result = $campaign->save();

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $campaign->post_id );

		$post = get_post( $campaign->post_id );
		$this->assertSame( 'Save Test', $post->post_title );
		$this->assertSame( 'Testing save.', $post->post_excerpt );
		$this->assertSame( 'publish', $post->post_status );
	}

	/**
	 * Test save() on an existing campaign updates without creating a new post.
	 */
	public function test_save_updates_without_creating_new_post(): void {
		$campaign = $this->create_campaign();
		$post_id  = $campaign->post_id;

		$campaign->goal_amount = 50000;
		$result                = $campaign->save();

		$this->assertTrue( $result );
		$this->assertSame( $post_id, $campaign->post_id );

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 50000, $fresh->goal_amount );
	}

	// -------------------------------------------------------------------------
	// set_campaign_page_enabled() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test set_campaign_page_enabled(true) publishes the campaign post.
	 */
	public function test_set_campaign_page_enabled_true_publishes_post(): void {
		$campaign = $this->create_campaign();

		// Draft the post first so we can verify the toggle.
		wp_update_post( [
			'ID'          => $campaign->post_id,
			'post_status' => 'draft',
		] );

		$campaign->set_campaign_page_enabled( true );

		$this->assertSame( 'publish', get_post_status( $campaign->post_id ) );
		$this->assertTrue( $campaign->has_campaign_page() );
	}

	/**
	 * Test set_campaign_page_enabled(false) drafts the campaign post.
	 */
	public function test_set_campaign_page_enabled_false_drafts_post(): void {
		$campaign = $this->create_campaign();

		$campaign->set_campaign_page_enabled( false );

		$this->assertSame( 'draft', get_post_status( $campaign->post_id ) );
		$this->assertFalse( $campaign->has_campaign_page() );
	}

	// -------------------------------------------------------------------------
	// trash() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test trash() trashes the post and deletes the custom table row.
	 */
	public function test_trash_trashes_post_and_deletes_row(): void {
		$campaign = $this->create_campaign();
		$post_id  = $campaign->post_id;
		$id       = $campaign->id;

		$campaign->trash();

		$this->assertSame( 'trash', get_post_status( $post_id ) );
		$this->assertNull( Campaign::find( $id ) );
	}

	// -------------------------------------------------------------------------
	// Image method tests.
	// -------------------------------------------------------------------------

	/**
	 * Test get_image_url returns a URL for an attached image.
	 */
	public function test_get_image_url_returns_url_for_attached_image(): void {
		$campaign      = $this->create_campaign();
		$attachment_id = $this->create_attachment();

		$campaign->set_image( $attachment_id );

		$url = $campaign->get_image_url();

		$this->assertNotNull( $url );
		$this->assertStringContainsString( 'test-image', $url );
	}

	/**
	 * Test set_image stores the attachment ID in meta.
	 */
	public function test_set_image_attaches_image(): void {
		$campaign      = $this->create_campaign();
		$attachment_id = $this->create_attachment();

		$campaign->set_image( $attachment_id );

		$this->assertSame( $attachment_id, $campaign->get_image_id() );
	}

	/**
	 * Test remove_image detaches the image.
	 */
	public function test_remove_image_detaches_image(): void {
		$campaign      = $this->create_campaign();
		$attachment_id = $this->create_attachment();

		$campaign->set_image( $attachment_id );
		$campaign->remove_image();

		$this->assertNull( $campaign->get_image_id() );
	}

	// -------------------------------------------------------------------------
	// has_campaign_page() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test has_campaign_page defaults to true when no meta is set.
	 */
	public function test_has_campaign_page_defaults_true(): void {
		$campaign = $this->create_campaign();

		$this->assertTrue( $campaign->has_campaign_page() );
	}

	// -------------------------------------------------------------------------
	// URL method tests.
	// -------------------------------------------------------------------------

	/**
	 * Test get_url returns a frontend URL.
	 */
	public function test_get_url_returns_frontend_url(): void {
		$campaign = $this->create_campaign();

		$url = $campaign->get_url();

		$this->assertNotNull( $url );
		$this->assertStringContainsString( home_url(), $url );
	}

	/**
	 * Test get_edit_url returns an admin edit URL.
	 */
	public function test_get_edit_url_returns_admin_edit_url(): void {
		// get_edit_post_link() returns null without a logged-in user who can edit.
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$campaign = $this->create_campaign();
		$url      = $campaign->get_edit_url();

		$this->assertNotNull( $url );
		$this->assertStringContainsString( 'post.php', $url );
		$this->assertStringContainsString( 'action=edit', $url );
	}

	// -------------------------------------------------------------------------
	// find_by_post_id() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test find_by_post_id returns the campaign for a valid post ID.
	 */
	public function test_find_by_post_id_returns_campaign(): void {
		$campaign = $this->create_campaign();

		$found = Campaign::find_by_post_id( $campaign->post_id );

		$this->assertNotNull( $found );
		$this->assertSame( $campaign->id, $found->id );
	}

	/**
	 * Test find_by_post_id returns null for an invalid post ID.
	 */
	public function test_find_by_post_id_returns_null_for_invalid(): void {
		$this->assertNull( Campaign::find_by_post_id( 99999 ) );
	}

	/**
	 * Test find_many() honors id__in and returns models keyed by ID.
	 */
	public function test_find_many_returns_only_requested_ids(): void {
		$first  = $this->create_campaign( [ 'title' => 'First' ] );
		$second = $this->create_campaign( [ 'title' => 'Second' ] );
		$third  = $this->create_campaign( [ 'title' => 'Third' ] );

		$found = Campaign::find_many( [ $first->id, $third->id ] );

		$this->assertCount( 2, $found );
		$this->assertArrayHasKey( $first->id, $found );
		$this->assertArrayHasKey( $third->id, $found );
		$this->assertArrayNotHasKey( $second->id, $found );
	}

	// -------------------------------------------------------------------------
	// __get() proxy tests.
	// -------------------------------------------------------------------------

	/**
	 * Test __get proxy reads slug from WP_Post->post_name.
	 */
	public function test_get_proxy_slug_reads_from_wp_post(): void {
		$campaign = $this->create_campaign( [ 'title' => 'My Slug Test' ] );

		// Update the post slug directly.
		wp_update_post( [
			'ID'        => $campaign->post_id,
			'post_name' => 'custom-slug',
		] );

		// Re-fetch to clear cached WP_Post.
		$fresh = Campaign::find( $campaign->id );

		$this->assertSame( 'custom-slug', $fresh->slug );
	}

	/**
	 * Test __get proxy reads page_content from WP_Post->post_content.
	 */
	public function test_get_proxy_page_content_reads_from_wp_post(): void {
		$campaign = $this->create_campaign();

		wp_update_post( [
			'ID'           => $campaign->post_id,
			'post_content' => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
		] );

		$fresh = Campaign::find( $campaign->id );

		$this->assertStringContainsString( 'Hello world', $fresh->page_content );
	}

	/**
	 * Test __get returns null for an unknown property.
	 */
	public function test_get_proxy_returns_null_for_unknown_property(): void {
		$campaign = $this->create_campaign();

		$this->assertNull( $campaign->nonexistent_property );
	}

	// -------------------------------------------------------------------------
	// __isset() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test __isset returns true for mapped post properties.
	 */
	public function test_isset_returns_true_for_post_properties(): void {
		$campaign = $this->create_campaign();

		$this->assertTrue( isset( $campaign->slug ) );
		$this->assertTrue( isset( $campaign->page_content ) );
	}

	/**
	 * Test __isset returns false for unknown properties.
	 */
	public function test_isset_returns_false_for_unknown_properties(): void {
		$campaign = $this->create_campaign();

		$this->assertFalse( isset( $campaign->nonexistent_property ) );
	}

	// -------------------------------------------------------------------------
	// Null-guard edge cases.
	// -------------------------------------------------------------------------

	/**
	 * Test get_url returns null when post_id is 0.
	 */
	public function test_get_url_returns_null_without_post(): void {
		$campaign = new Campaign();

		$this->assertNull( $campaign->get_url() );
	}

	/**
	 * Test get_edit_url returns null when post_id is 0.
	 */
	public function test_get_edit_url_returns_null_without_post(): void {
		$campaign = new Campaign();

		$this->assertNull( $campaign->get_edit_url() );
	}

	/**
	 * Test get_image_url returns null when no image is set.
	 */
	public function test_get_image_url_returns_null_without_image(): void {
		$campaign = $this->create_campaign();

		$this->assertNull( $campaign->get_image_url() );
	}

	/**
	 * Test get_image_id returns null when no image is set.
	 */
	public function test_get_image_id_returns_null_without_image(): void {
		$campaign = $this->create_campaign();

		$this->assertNull( $campaign->get_image_id() );
	}

	// -------------------------------------------------------------------------
	// query() and count() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test query() returns all campaigns.
	 */
	public function test_query_returns_all_campaigns(): void {
		$this->create_campaign( [ 'title' => 'Campaign A' ] );
		$this->create_campaign( [ 'title' => 'Campaign B' ] );
		$this->create_campaign( [ 'title' => 'Campaign C' ] );

		$campaigns = Campaign::query();

		$this->assertCount( 3, $campaigns );
		$this->assertContainsOnlyInstancesOf( Campaign::class, $campaigns );
	}

	/**
	 * Test count() returns the correct total.
	 */
	public function test_count_returns_total(): void {
		$this->create_campaign( [ 'title' => 'One' ] );
		$this->create_campaign( [ 'title' => 'Two' ] );

		$this->assertSame( 2, Campaign::count() );
	}

	/**
	 * Test query() pagination.
	 */
	public function test_query_pagination(): void {
		$this->create_campaign( [ 'title' => 'A' ] );
		$this->create_campaign( [ 'title' => 'B' ] );
		$this->create_campaign( [ 'title' => 'C' ] );

		$page1 = Campaign::query( [ 'per_page' => 2, 'page' => 1, 'orderby' => 'id', 'order' => 'ASC' ] );
		$page2 = Campaign::query( [ 'per_page' => 2, 'page' => 2, 'orderby' => 'id', 'order' => 'ASC' ] );

		$this->assertCount( 2, $page1 );
		$this->assertCount( 1, $page2 );

		$page1_ids = array_map( fn( $c ) => $c->id, $page1 );
		$page2_ids = array_map( fn( $c ) => $c->id, $page2 );
		$this->assertEmpty( array_intersect( $page1_ids, $page2_ids ) );
	}

	/**
	 * Test query() filters by status (active campaigns).
	 */
	public function test_query_filters_active_campaigns(): void {
		$this->create_campaign( [ 'title' => 'Active' ] );
		$this->create_campaign( [
			'title'  => 'Ended',
			'status' => 'ended',
		] );

		$results = Campaign::query( [ 'status' => 'active' ] );

		$this->assertCount( 1, $results );
		$this->assertSame( 'active', $results[0]->status );
	}

	// -------------------------------------------------------------------------
	// Hook tests.
	// -------------------------------------------------------------------------

	/**
	 * Test mission_campaign_created action fires on insert.
	 */
	public function test_mission_campaign_created_action_fires(): void {
		$fired = false;

		add_action( 'mission_campaign_created', function () use ( &$fired ) {
			$fired = true;
		} );

		$this->create_campaign();

		$this->assertTrue( $fired );
	}

	// -------------------------------------------------------------------------
	// Donation form attributes tests.
	// -------------------------------------------------------------------------

	/**
	 * Test get_donation_form_attributes returns the form block's attributes.
	 */
	public function test_get_donation_form_attributes_returns_block_attrs(): void {
		$campaign = $this->create_campaign();

		wp_update_post(
			[
				'ID'           => $campaign->post_id,
				'post_content' => '<!-- wp:mission-donation-platform/donation-form {"campaignId":' . $campaign->id . ',"recurringEnabled":false,"donateButtonText":"Give Now"} /-->',
			]
		);

		$attrs = $campaign->get_donation_form_attributes();

		$this->assertSame( $campaign->id, $attrs['campaignId'] );
		$this->assertFalse( $attrs['recurringEnabled'] );
		$this->assertSame( 'Give Now', $attrs['donateButtonText'] );
	}

	/**
	 * Test get_donation_form_attributes finds a form block nested in other blocks.
	 */
	public function test_get_donation_form_attributes_finds_nested_block(): void {
		$campaign = $this->create_campaign();

		wp_update_post(
			[
				'ID'           => $campaign->post_id,
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:column --><!-- wp:mission-donation-platform/donation-form {"tipEnabled":false} /--><!-- /wp:column --></div><!-- /wp:group -->',
			]
		);

		$attrs = $campaign->get_donation_form_attributes();

		$this->assertFalse( $attrs['tipEnabled'] );
	}

	/**
	 * Test get_donation_form_attributes returns empty array when the page has no form block.
	 */
	public function test_get_donation_form_attributes_empty_without_form_block(): void {
		$campaign = $this->create_campaign();

		wp_update_post(
			[
				'ID'           => $campaign->post_id,
				'post_content' => '<!-- wp:paragraph --><p>No form here.</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertSame( [], $campaign->get_donation_form_attributes() );
	}

	// -------------------------------------------------------------------------
	// recompute_aggregates() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test recompute_aggregates rebuilds totals from completed transactions.
	 */
	public function test_recompute_aggregates_rebuilds_totals_from_transactions(): void {
		$campaign = $this->create_campaign();

		// Silent writes don't touch aggregates, so the campaign row stays at
		// zero until the recompute runs. Two donors, one with two donations.
		$rows = [
			[ 'donor_id' => 1, 'amount' => 5000 ],
			[ 'donor_id' => 1, 'amount' => 2500 ],
			[ 'donor_id' => 2, 'amount' => 1000 ],
			[ 'donor_id' => 3, 'amount' => 9999, 'is_test' => true ],
			[ 'donor_id' => 2, 'amount' => 1234, 'status' => 'pending' ],
		];

		foreach ( $rows as $row ) {
			$transaction = new Transaction( array_merge(
				[
					'status'       => 'completed',
					'campaign_id'  => $campaign->id,
					'total_amount' => $row['amount'],
				],
				$row
			) );
			$transaction->save_silent();
		}

		$this->assertSame( 0, $campaign->fresh()->transaction_count );

		Campaign::recompute_aggregates( $campaign->id );

		$fresh = $campaign->fresh();
		$this->assertSame( 8500, $fresh->total_raised );
		$this->assertSame( 3, $fresh->transaction_count );
		$this->assertSame( 2, $fresh->donor_count );
		$this->assertSame( 9999, $fresh->test_total_raised );
		$this->assertSame( 1, $fresh->test_transaction_count );
		$this->assertSame( 1, $fresh->test_donor_count );
	}

	// -------------------------------------------------------------------------
	// Campaign type tests.
	// -------------------------------------------------------------------------

	/**
	 * Test type defaults to "standard".
	 */
	public function test_type_defaults_to_standard(): void {
		$campaign = new Campaign();

		$this->assertSame( 'standard', $campaign->type );
		$this->assertFalse( $campaign->is_p2p() );
		$this->assertFalse( $campaign->is_event() );
	}

	/**
	 * Test type persists through save and find.
	 */
	public function test_type_persists_through_save_and_find(): void {
		$campaign = $this->create_campaign( [ 'type' => 'p2p' ] );

		$found = Campaign::find( $campaign->id );
		$this->assertSame( 'p2p', $found->type );
		$this->assertTrue( $found->is_p2p() );
		$this->assertFalse( $found->is_event() );
	}

	/**
	 * Test query() filters by type.
	 */
	public function test_query_filters_by_type(): void {
		$this->create_campaign( [ 'title' => 'Standard' ] );
		$this->create_campaign( [ 'title' => 'P2P', 'type' => 'p2p' ] );

		$p2p = Campaign::query( [ 'type' => 'p2p' ] );

		$this->assertCount( 1, $p2p );
		$this->assertSame( 'p2p', $p2p[0]->type );
	}

	/**
	 * Test p2p_settings() returns the documented defaults, with native types,
	 * when no settings meta has ever been saved.
	 */
	public function test_p2p_settings_returns_typed_defaults_when_unset(): void {
		$campaign = $this->create_campaign( [ 'title' => 'P2P', 'type' => 'p2p' ] );

		// assertSame on the full array checks values, types, and key order.
		$this->assertSame( Campaign::P2P_DEFAULT_SETTINGS, $campaign->p2p_settings() );
	}

	/**
	 * Test p2p_settings() casts stored string meta back to native types.
	 */
	public function test_p2p_settings_casts_stored_string_meta(): void {
		$campaign = $this->create_campaign( [ 'title' => 'P2P', 'type' => 'p2p' ] );

		// Meta round-trips as strings; p2p_settings() must cast them back.
		$campaign->update_meta( 'registration_open', '0' );
		$campaign->update_meta( 'approval_required', '1' );
		$campaign->update_meta( 'default_fundraiser_goal', '75000' );
		$campaign->update_meta( 'story_placeholder', 'Tell your story' );

		$settings = $campaign->p2p_settings();

		$this->assertFalse( $settings['registration_open'] );
		$this->assertTrue( $settings['approval_required'] );
		$this->assertSame( 75000, $settings['default_fundraiser_goal'] );
		$this->assertSame( 'Tell your story', $settings['story_placeholder'] );

		// Keys never saved still resolve to their typed defaults.
		$this->assertSame( 200000, $settings['default_team_goal'] );
		$this->assertFalse( $settings['teams_enabled'] );
	}

	/**
	 * Test fundraisers() and teams() return records scoped to the campaign.
	 */
	public function test_fundraisers_and_teams_relationships(): void {
		$campaign = $this->create_campaign( [ 'type' => 'p2p' ] );
		$other    = $this->create_campaign( [ 'title' => 'Other', 'type' => 'p2p' ] );

		$fundraiser = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => 1 ] );
		$fundraiser->save();
		$team = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Team A' ] );
		$team->save();

		// Records on another campaign must not leak in.
		$other_fundraiser = new Fundraiser( [ 'campaign_id' => $other->id, 'donor_id' => 1 ] );
		$other_fundraiser->save();

		$this->assertCount( 1, $campaign->fundraisers() );
		$this->assertCount( 1, $campaign->teams() );
		$this->assertSame( $fundraiser->id, $campaign->fundraisers()[0]->id );
	}

	/**
	 * Test deleting a P2P campaign removes its fundraisers, teams, and their
	 * shell posts — no orphaned fundraising pages left live on the site.
	 */
	public function test_delete_cascades_p2p_children(): void {
		$campaign = $this->create_campaign( [ 'type' => 'p2p' ] );
		$survivor = $this->create_campaign( [ 'title' => 'Other', 'type' => 'p2p' ] );

		$fundraiser = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => 1, 'status' => 'active' ] );
		$fundraiser->save();
		$team = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Team A', 'status' => 'active' ] );
		$team->save();

		$other_fundraiser = new Fundraiser( [ 'campaign_id' => $survivor->id, 'donor_id' => 1 ] );
		$other_fundraiser->save();

		$fundraiser_post = $fundraiser->post_id;
		$team_post       = $team->post_id;

		$campaign->trash();

		$this->assertNull( Fundraiser::find( $fundraiser->id ) );
		$this->assertNull( Team::find( $team->id ) );
		$this->assertNull( get_post( $fundraiser_post ) );
		$this->assertNull( get_post( $team_post ) );

		// Another campaign's records are untouched.
		$this->assertNotNull( Fundraiser::find( $other_fundraiser->id ) );
	}

	// -------------------------------------------------------------------------
	// Lifecycle helpers.
	// -------------------------------------------------------------------------

	/**
	 * Test has_ended() reflects the ended status only.
	 */
	public function test_has_ended_reflects_status(): void {
		$active = $this->create_campaign();
		$ended  = $this->create_campaign(
			[
				'title'  => 'Done',
				'status' => Campaign::STATUS_ENDED,
			]
		);

		$this->assertFalse( $active->has_ended() );
		$this->assertTrue( $ended->has_ended() );
	}

	/**
	 * Test is_registration_open() requires an active P2P campaign with the toggle on.
	 */
	public function test_is_registration_open_requires_active_p2p_with_toggle(): void {
		$active = $this->create_campaign( [ 'type' => Campaign::TYPE_P2P ] );
		$this->assertTrue( $active->is_registration_open() );

		$toggled_off = $this->create_campaign( [ 'type' => Campaign::TYPE_P2P ] );
		$toggled_off->update_meta( 'registration_open', '0' );
		$this->assertFalse( $toggled_off->is_registration_open() );

		$scheduled = $this->create_campaign(
			[
				'type'   => Campaign::TYPE_P2P,
				'status' => Campaign::STATUS_SCHEDULED,
			]
		);
		$this->assertFalse( $scheduled->is_registration_open() );

		$ended = $this->create_campaign(
			[
				'type'   => Campaign::TYPE_P2P,
				'status' => Campaign::STATUS_ENDED,
			]
		);
		$this->assertFalse( $ended->is_registration_open() );

		$standard = $this->create_campaign();
		$this->assertFalse( $standard->is_registration_open() );
	}

	/**
	 * Test days_left() counts whole days to the end date.
	 */
	public function test_days_left_counts_days_to_end_date(): void {
		$campaign = $this->create_campaign(
			[ 'date_end' => wp_date( 'Y-m-d', strtotime( '+10 days' ) ) . ' 00:00:00' ]
		);

		$this->assertSame( 10, $campaign->days_left() );
	}

	/**
	 * Test days_left() is null without an end date or once the end date has passed.
	 */
	public function test_days_left_null_without_end_date_or_after_end(): void {
		$open = $this->create_campaign();
		$past = $this->create_campaign(
			[
				'title'    => 'Past',
				'date_end' => '2020-01-01 00:00:00',
			]
		);
		$today = $this->create_campaign(
			[
				'title'    => 'Ends today',
				'date_end' => wp_date( 'Y-m-d' ) . ' 23:59:59',
			]
		);

		$this->assertNull( $open->days_left() );
		$this->assertNull( $past->days_left() );
		$this->assertSame( 0, $today->days_left() );
	}
}
