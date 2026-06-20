<?php
/**
 * Tests for the Fundraiser model.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Models;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\Transaction;
use WP_UnitTestCase;

/**
 * Fundraiser model test class.
 */
class FundraiserTest extends WP_UnitTestCase {

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Clean up tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisermeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Create and save a fundraiser.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Fundraiser
	 */
	private function create_fundraiser( array $overrides = [] ): Fundraiser {
		$fundraiser = new Fundraiser( array_merge(
			[
				'campaign_id' => 1,
				'donor_id'    => 1,
				'headline'    => 'Help me help them',
			],
			$overrides
		) );
		$fundraiser->save();

		return $fundraiser;
	}

	// -------------------------------------------------------------------------
	// Construction tests.
	// -------------------------------------------------------------------------

	/**
	 * Test default values on empty construction.
	 */
	public function test_defaults_on_empty_construction(): void {
		$fundraiser = new Fundraiser();

		$this->assertNull( $fundraiser->id );
		$this->assertSame( 0, $fundraiser->campaign_id );
		$this->assertSame( 0, $fundraiser->donor_id );
		$this->assertNull( $fundraiser->team_id );
		$this->assertSame( 0, $fundraiser->post_id );
		$this->assertFalse( $fundraiser->is_team_captain );
		$this->assertSame( 'pending', $fundraiser->status );
		$this->assertSame( 0, $fundraiser->goal );
		$this->assertSame( 0, $fundraiser->total_raised );
		$this->assertSame( 0, $fundraiser->test_total_raised );
	}

	/**
	 * Test full construction from array with casts.
	 */
	public function test_full_construction_from_array(): void {
		$fundraiser = new Fundraiser( [
			'id'              => 7,
			'campaign_id'     => 3,
			'donor_id'        => 12,
			'team_id'         => 4,
			'is_team_captain' => 1,
			'goal'            => 50000,
			'status'          => 'active',
		] );

		$this->assertSame( 7, $fundraiser->id );
		$this->assertSame( 3, $fundraiser->campaign_id );
		$this->assertSame( 12, $fundraiser->donor_id );
		$this->assertSame( 4, $fundraiser->team_id );
		$this->assertTrue( $fundraiser->is_team_captain );
		$this->assertSame( 50000, $fundraiser->goal );
		$this->assertSame( 'active', $fundraiser->status );
	}

	// -------------------------------------------------------------------------
	// save() / find() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test save() inserts and find() round-trips the record.
	 */
	public function test_save_and_find_round_trip(): void {
		$fundraiser = $this->create_fundraiser( [
			'goal'     => 25000,
			'headline' => 'Round trip',
			'status'   => 'active',
		] );

		$this->assertIsInt( $fundraiser->id );
		$this->assertGreaterThan( 0, $fundraiser->id );

		$found = Fundraiser::find( $fundraiser->id );
		$this->assertNotNull( $found );
		$this->assertSame( 25000, $found->goal );
		$this->assertSame( 'Round trip', $found->headline );
		$this->assertSame( 'active', $found->status );
		$this->assertNotEmpty( $found->date_created );
	}

	/**
	 * Test mission_fundraiser_created action fires on insert.
	 */
	public function test_mission_fundraiser_created_action_fires(): void {
		$fired = false;

		add_action( 'mission_fundraiser_created', function () use ( &$fired ) {
			$fired = true;
		} );

		$this->create_fundraiser();

		$this->assertTrue( $fired );
	}

	/**
	 * Test save_silent() does not fire the created action.
	 */
	public function test_save_silent_does_not_fire_created_action(): void {
		$fired = false;

		add_action( 'mission_fundraiser_created', function () use ( &$fired ) {
			$fired = true;
		} );

		$fundraiser = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1 ] );
		$fundraiser->save_silent();

		$this->assertFalse( $fired );
		$this->assertNotNull( Fundraiser::find( $fundraiser->id ) );
	}

	/**
	 * Test the unique (campaign_id, donor_id) constraint blocks a duplicate.
	 */
	public function test_unique_campaign_donor_constraint(): void {
		global $wpdb;

		$this->create_fundraiser( [ 'campaign_id' => 5, 'donor_id' => 9 ] );

		// Second fundraiser for the same campaign/donor pair must not create a row.
		// The duplicate-key INSERT fails by design; silence its expected error log.
		$suppress = $wpdb->suppress_errors( true );
		$dup      = new Fundraiser( [ 'campaign_id' => 5, 'donor_id' => 9 ] );
		$dup->save();
		$wpdb->suppress_errors( $suppress );

		$this->assertSame( 1, Fundraiser::count( [ 'campaign_id' => 5 ] ) );
	}

	// -------------------------------------------------------------------------
	// Registration tests.
	// -------------------------------------------------------------------------

	/**
	 * Test register() creates a solo, active fundraiser.
	 */
	public function test_register_creates_solo_fundraiser(): void {
		$fundraiser = Fundraiser::register( 1, 1, 25000, 'My story', 'My headline' );

		$this->assertNotNull( $fundraiser->id );
		$this->assertNull( $fundraiser->team_id );
		$this->assertFalse( $fundraiser->is_team_captain );
		$this->assertSame( 'active', $fundraiser->status );
		$this->assertSame( 25000, $fundraiser->goal );
		$this->assertSame( 'My story', $fundraiser->story );
		$this->assertSame( 'My headline', $fundraiser->headline );
	}

	/**
	 * Test join_team() sets the team, captain flag, and fires mission_team_joined.
	 */
	public function test_join_team_sets_team_and_fires_event(): void {
		$team = new Team( [ 'campaign_id' => 1, 'name' => 'Runners' ] );
		$team->save();

		$fired = [];
		add_action(
			'mission_team_joined',
			function ( $fundraiser, $joined ) use ( &$fired ) {
				$fired = [ $fundraiser->id, $joined->id ];
			},
			10,
			2
		);

		$fundraiser = Fundraiser::register( 1, 1, 25000 );
		$this->assertTrue( $fundraiser->join_team( $team, true ) );

		$this->assertSame( $team->id, $fundraiser->team_id );
		$this->assertTrue( $fundraiser->is_team_captain );
		$this->assertSame( $team->id, Fundraiser::find( $fundraiser->id )->team_id );
		$this->assertSame( [ $fundraiser->id, $team->id ], $fired );
	}

	/**
	 * Test leave_team() clears the team, captain flag, and fires mission_team_left.
	 */
	public function test_leave_team_clears_team_and_fires_event(): void {
		$team = new Team( [ 'campaign_id' => 1, 'name' => 'Runners' ] );
		$team->save();

		$fired = [];
		add_action(
			'mission_team_left',
			function ( $fundraiser, $left ) use ( &$fired ) {
				$fired = [ $fundraiser->id, $left->id ];
			},
			10,
			2
		);

		$fundraiser = Fundraiser::register( 1, 1, 25000 );
		$fundraiser->join_team( $team, true );

		$this->assertTrue( $fundraiser->leave_team() );

		$this->assertNull( $fundraiser->team_id );
		$this->assertFalse( $fundraiser->is_team_captain );
		$this->assertNull( Fundraiser::find( $fundraiser->id )->team_id );
		$this->assertSame( [ $fundraiser->id, $team->id ], $fired );
	}

	// -------------------------------------------------------------------------
	// query() / count() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test query() filters by campaign, team, status, and donor.
	 */
	public function test_query_filters(): void {
		$this->create_fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1, 'team_id' => 10, 'status' => 'active' ] );
		$this->create_fundraiser( [ 'campaign_id' => 1, 'donor_id' => 2, 'status' => 'pending' ] );
		$this->create_fundraiser( [ 'campaign_id' => 2, 'donor_id' => 3, 'status' => 'active' ] );

		$this->assertCount( 2, Fundraiser::query( [ 'campaign_id' => 1 ] ) );
		$this->assertCount( 1, Fundraiser::query( [ 'campaign_id' => 1, 'status' => 'active' ] ) );
		$this->assertCount( 1, Fundraiser::query( [ 'team_id' => 10 ] ) );
		$this->assertCount( 1, Fundraiser::query( [ 'donor_id' => 3 ] ) );
		$this->assertCount( 2, Fundraiser::query( [ 'status__in' => [ 'active' ] ] ) );
	}

	/**
	 * Test query() pagination.
	 */
	public function test_query_pagination(): void {
		$this->create_fundraiser( [ 'donor_id' => 1 ] );
		$this->create_fundraiser( [ 'donor_id' => 2 ] );
		$this->create_fundraiser( [ 'donor_id' => 3 ] );

		$page1 = Fundraiser::query( [ 'per_page' => 2, 'page' => 1, 'orderby' => 'id', 'order' => 'ASC' ] );
		$page2 = Fundraiser::query( [ 'per_page' => 2, 'page' => 2, 'orderby' => 'id', 'order' => 'ASC' ] );

		$this->assertCount( 2, $page1 );
		$this->assertCount( 1, $page2 );
	}

	/**
	 * Test count() respects filters.
	 */
	public function test_count_with_filter(): void {
		$this->create_fundraiser( [ 'donor_id' => 1, 'status' => 'active' ] );
		$this->create_fundraiser( [ 'donor_id' => 2, 'status' => 'pending' ] );

		$this->assertSame( 2, Fundraiser::count() );
		$this->assertSame( 1, Fundraiser::count( [ 'status' => 'active' ] ) );
	}

	// -------------------------------------------------------------------------
	// Relationship tests.
	// -------------------------------------------------------------------------

	/**
	 * Test campaign(), donor(), and team() relationships resolve.
	 */
	public function test_relationships_resolve(): void {
		$campaign = new Campaign( [ 'title' => 'P2P', 'type' => 'p2p' ] );
		$campaign->save();

		$donor = new Donor( [ 'email' => 'fr@example.com', 'first_name' => 'Fred' ] );
		$donor->save();

		$team = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Runners' ] );
		$team->save();

		$fundraiser = $this->create_fundraiser( [
			'campaign_id' => $campaign->id,
			'donor_id'    => $donor->id,
			'team_id'     => $team->id,
		] );

		$this->assertSame( $campaign->id, $fundraiser->campaign()->id );
		$this->assertSame( $donor->id, $fundraiser->donor()->id );
		$this->assertSame( $team->id, $fundraiser->team()->id );
	}

	/**
	 * Test Donor::fundraisers() returns only that donor's fundraisers.
	 */
	public function test_donor_fundraisers_relationship(): void {
		$campaign_a = new Campaign( [ 'title' => 'A', 'type' => 'p2p' ] );
		$campaign_a->save();
		$campaign_b = new Campaign( [ 'title' => 'B', 'type' => 'p2p' ] );
		$campaign_b->save();

		$donor = new Donor( [ 'email' => 'multi@example.com', 'first_name' => 'Mary' ] );
		$donor->save();
		$other = new Donor( [ 'email' => 'other@example.com', 'first_name' => 'Otto' ] );
		$other->save();

		$this->create_fundraiser( [ 'campaign_id' => $campaign_a->id, 'donor_id' => $donor->id ] );
		$this->create_fundraiser( [ 'campaign_id' => $campaign_b->id, 'donor_id' => $donor->id ] );
		$this->create_fundraiser( [ 'campaign_id' => $campaign_a->id, 'donor_id' => $other->id ] );

		$fundraisers = $donor->fundraisers();

		$this->assertCount( 2, $fundraisers );
		foreach ( $fundraisers as $fundraiser ) {
			$this->assertSame( $donor->id, $fundraiser->donor_id );
		}
	}

	/**
	 * Test team() returns null when the fundraiser has no team.
	 */
	public function test_team_returns_null_without_team(): void {
		$fundraiser = $this->create_fundraiser();

		$this->assertNull( $fundraiser->team() );
	}

	/**
	 * Test transactions() returns transactions attributed to this fundraiser.
	 */
	public function test_transactions_relationship(): void {
		$fundraiser = $this->create_fundraiser();

		$attributed = new Transaction( [
			'status'        => 'completed',
			'donor_id'      => 1,
			'fundraiser_id' => $fundraiser->id,
			'amount'        => 1000,
			'total_amount'  => 1000,
		] );
		$attributed->save_silent();

		// A transaction for a different fundraiser must not appear.
		$other = new Transaction( [
			'status'        => 'completed',
			'donor_id'      => 1,
			'fundraiser_id' => $fundraiser->id + 999,
			'amount'        => 2000,
			'total_amount'  => 2000,
		] );
		$other->save_silent();

		$transactions = $fundraiser->transactions();
		$this->assertCount( 1, $transactions );
		$this->assertSame( $attributed->id, $transactions[0]->id );
	}

	// -------------------------------------------------------------------------
	// Meta tests.
	// -------------------------------------------------------------------------

	/**
	 * Test meta get/update/delete via HasMeta.
	 */
	public function test_meta_crud(): void {
		$fundraiser = $this->create_fundraiser();

		$fundraiser->update_meta( 'why_i_fundraise', 'For the cause' );
		$this->assertSame( 'For the cause', $fundraiser->get_meta( 'why_i_fundraise' ) );

		$fundraiser->delete_meta( 'why_i_fundraise' );
		$this->assertSame( '', $fundraiser->get_meta( 'why_i_fundraise' ) );
	}

	// -------------------------------------------------------------------------
	// recompute_aggregates() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test recompute_aggregates rebuilds totals from attributed transactions.
	 */
	public function test_recompute_aggregates_rebuilds_totals(): void {
		$fundraiser = $this->create_fundraiser();

		// Silent writes don't touch fundraiser aggregates until recompute runs.
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
					'status'        => 'completed',
					'fundraiser_id' => $fundraiser->id,
					'total_amount'  => $row['amount'],
				],
				$row
			) );
			$transaction->save_silent();
		}

		$this->assertSame( 0, $fundraiser->fresh()->transaction_count );

		Fundraiser::recompute_aggregates( $fundraiser->id );

		$fresh = $fundraiser->fresh();
		$this->assertSame( 8500, $fresh->total_raised );
		$this->assertSame( 3, $fresh->transaction_count );
		$this->assertSame( 2, $fresh->donor_count );
		$this->assertSame( 9999, $fresh->test_total_raised );
		$this->assertSame( 1, $fresh->test_transaction_count );
		$this->assertSame( 1, $fresh->test_donor_count );
	}

	// -------------------------------------------------------------------------
	// Accessor tests.
	// -------------------------------------------------------------------------

	/**
	 * Test amount_raised reads the stored column for live and test modes.
	 */
	public function test_amount_raised(): void {
		$fundraiser = $this->create_fundraiser( [ 'total_raised' => 7500, 'test_total_raised' => 200 ] );

		$this->assertSame( 7500, $fundraiser->amount_raised() );
		$this->assertSame( 200, $fundraiser->amount_raised( true ) );
	}

	/**
	 * Test progress is 0 without a goal and capped at 100.
	 */
	public function test_progress(): void {
		$no_goal = $this->create_fundraiser( [ 'donor_id' => 1, 'goal' => 0, 'total_raised' => 5000 ] );
		$this->assertSame( 0.0, $no_goal->progress() );

		$partial = $this->create_fundraiser( [ 'donor_id' => 2, 'goal' => 10000, 'total_raised' => 2500 ] );
		$this->assertSame( 25.0, $partial->progress() );

		$over = $this->create_fundraiser( [ 'donor_id' => 3, 'goal' => 1000, 'total_raised' => 5000 ] );
		$this->assertSame( 100.0, $over->progress() );
	}

	/**
	 * Test is_captain reflects the is_team_captain flag.
	 */
	public function test_is_captain(): void {
		$captain = $this->create_fundraiser( [ 'donor_id' => 1, 'is_team_captain' => true ] );
		$member  = $this->create_fundraiser( [ 'donor_id' => 2, 'is_team_captain' => false ] );

		$this->assertTrue( $captain->is_captain() );
		$this->assertFalse( $member->is_captain() );
	}

	// -------------------------------------------------------------------------
	// Shell post (HasShellPost) tests.
	// -------------------------------------------------------------------------

	/**
	 * Test save() creates a shell post and maps a pending fundraiser to a pending post.
	 */
	public function test_save_creates_shell_post(): void {
		$fundraiser = $this->create_fundraiser( [ 'status' => 'pending' ] );

		$this->assertGreaterThan( 0, $fundraiser->post_id );

		$post = get_post( $fundraiser->post_id );
		$this->assertSame( 'missiondp_fundraiser', $post->post_type );
		$this->assertSame( 'pending', $post->post_status );
	}

	/**
	 * Test the shell post title comes from the participant's donor name.
	 */
	public function test_shell_post_title_uses_donor_name(): void {
		$donor = new Donor( [ 'email' => 'jane@example.com', 'first_name' => 'Jane', 'last_name' => 'Doe' ] );
		$donor->save();

		$fundraiser = $this->create_fundraiser( [ 'donor_id' => $donor->id ] );

		$this->assertSame( 'Jane Doe', get_post( $fundraiser->post_id )->post_title );
	}

	/**
	 * Test approving publishes the shell post and deactivating drafts it.
	 */
	public function test_status_changes_sync_post_status(): void {
		$fundraiser = $this->create_fundraiser( [ 'status' => 'pending' ] );
		$post_id    = $fundraiser->post_id;

		$fundraiser->approve();
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		$fundraiser->deactivate();
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * Test find_by_post_id() resolves the fundraiser from its shell post.
	 */
	public function test_find_by_post_id(): void {
		$fundraiser = $this->create_fundraiser();

		$found = Fundraiser::find_by_post_id( $fundraiser->post_id );
		$this->assertNotNull( $found );
		$this->assertSame( $fundraiser->id, $found->id );

		$this->assertNull( Fundraiser::find_by_post_id( 0 ) );
	}

	/**
	 * Test the slug proxy reads post_name from the shell post.
	 */
	public function test_slug_proxy_reads_post_name(): void {
		$donor = new Donor( [ 'email' => 'jane@example.com', 'first_name' => 'Jane', 'last_name' => 'Doe' ] );
		$donor->save();

		// An active fundraiser publishes its shell post, so WP generates the slug from the title.
		$fundraiser = $this->create_fundraiser( [ 'donor_id' => $donor->id, 'status' => 'active' ] );

		$this->assertSame( 'jane-doe', Fundraiser::find( $fundraiser->id )->slug );
	}

	/**
	 * Test trash() trashes the post and removes the row.
	 */
	public function test_trash_trashes_post_and_deletes_row(): void {
		$fundraiser = $this->create_fundraiser();
		$post_id    = $fundraiser->post_id;
		$id         = $fundraiser->id;

		$this->assertTrue( $fundraiser->trash() );
		$this->assertSame( 'trash', get_post_status( $post_id ) );
		$this->assertNull( Fundraiser::find( $id ) );
	}

	/**
	 * Test delete() permanently removes the shell post and the row.
	 */
	public function test_delete_removes_shell_post(): void {
		$fundraiser = $this->create_fundraiser();
		$post_id    = $fundraiser->post_id;
		$id         = $fundraiser->id;

		$this->assertTrue( $fundraiser->delete() );
		$this->assertNull( get_post( $post_id ) );
		$this->assertNull( Fundraiser::find( $id ) );
	}

	/**
	 * Test a failed duplicate insert does not orphan a shell post.
	 */
	public function test_duplicate_save_does_not_orphan_post(): void {
		global $wpdb;

		$this->create_fundraiser( [ 'campaign_id' => 5, 'donor_id' => 9 ] );

		$suppress = $wpdb->suppress_errors( true );
		$dup      = new Fundraiser( [ 'campaign_id' => 5, 'donor_id' => 9 ] );
		$dup->save();
		$wpdb->suppress_errors( $suppress );

		$this->assertSame( 0, $dup->post_id );

		// Only the first fundraiser's shell post should exist; the duplicate's was cleaned up.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'missiondp_fundraiser' ) );
		$this->assertSame( 1, $count );
	}
}
