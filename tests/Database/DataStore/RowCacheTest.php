<?php
/**
 * Tests for the CachesRows per-request row memoization.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Database\DataStore;

use MissionDP\Database\DatabaseModule;
use MissionDP\Database\DataStore\FundraiserDataStore;
use MissionDP\Database\DataStore\TeamDataStore;
use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\Transaction;
use WP_UnitTestCase;

/**
 * Row cache test class.
 */
class RowCacheTest extends WP_UnitTestCase {

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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
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
				'headline'    => 'Original headline',
			],
			$overrides
		) );
		$fundraiser->save();

		return $fundraiser;
	}

	/**
	 * Number of database queries run by a callback.
	 *
	 * @param callable $callback The code to measure.
	 * @return int
	 */
	private function count_queries( callable $callback ): int {
		global $wpdb;

		$before = $wpdb->num_queries;
		$callback();

		return $wpdb->num_queries - $before;
	}

	/**
	 * Test a second find() is served from the memo without a query.
	 */
	public function test_second_find_runs_no_queries(): void {
		$fundraiser = $this->create_fundraiser();

		$first = Fundraiser::find( $fundraiser->id );

		$queries = $this->count_queries(
			fn() => Fundraiser::find( $fundraiser->id )
		);

		$this->assertSame( 0, $queries );
		$this->assertSame( 'Original headline', $first->headline );
	}

	/**
	 * Test find() and find_by_post_id() share one memoized row, both ways.
	 */
	public function test_find_and_find_by_post_id_share_the_row(): void {
		$fundraiser = $this->create_fundraiser();

		Fundraiser::find( $fundraiser->id );
		$queries = $this->count_queries(
			fn() => Fundraiser::find_by_post_id( $fundraiser->post_id )
		);
		$this->assertSame( 0, $queries );

		wp_cache_flush();

		Fundraiser::find_by_post_id( $fundraiser->post_id );
		$queries = $this->count_queries(
			fn() => Fundraiser::find( $fundraiser->id )
		);
		$this->assertSame( 0, $queries );
	}

	/**
	 * Test memo hits hydrate fresh instances that don't share state.
	 */
	public function test_returned_models_are_fresh_instances(): void {
		$fundraiser = $this->create_fundraiser();

		$first  = Fundraiser::find( $fundraiser->id );
		$second = Fundraiser::find( $fundraiser->id );

		$this->assertNotSame( $first, $second );

		$first->headline = 'Mutated locally';

		$this->assertSame( 'Original headline', Fundraiser::find( $fundraiser->id )->headline );
	}

	/**
	 * Test save() invalidates the memo.
	 */
	public function test_save_invalidates_cache(): void {
		$fundraiser = $this->create_fundraiser();

		Fundraiser::find( $fundraiser->id );

		$fundraiser->headline = 'Updated headline';
		$fundraiser->save();

		$this->assertSame( 'Updated headline', Fundraiser::find( $fundraiser->id )->headline );
	}

	/**
	 * Test delete() invalidates both lookup paths.
	 */
	public function test_delete_invalidates_cache(): void {
		$fundraiser = $this->create_fundraiser();
		$post_id    = $fundraiser->post_id;

		Fundraiser::find( $fundraiser->id );
		Fundraiser::find_by_post_id( $post_id );

		$fundraiser->delete();

		$this->assertNull( Fundraiser::find( $fundraiser->id ) );
		$this->assertNull( Fundraiser::find_by_post_id( $post_id ) );
	}

	/**
	 * Test a post_id change on update drops the old post mapping.
	 */
	public function test_post_id_change_invalidates_old_post_key(): void {
		$fundraiser = $this->create_fundraiser();
		$old_post   = $fundraiser->post_id;
		$new_post   = self::factory()->post->create();

		Fundraiser::find_by_post_id( $old_post );

		$fundraiser->post_id = $new_post;
		$fundraiser->save();

		// Re-prime the row under the new post_id, then make sure the stale
		// mapping can't resolve it.
		Fundraiser::find( $fundraiser->id );

		$this->assertNull( Fundraiser::find_by_post_id( $old_post ) );
		$this->assertSame( $fundraiser->id, Fundraiser::find_by_post_id( $new_post )->id );
	}

	/**
	 * Test misses are not cached: a row created after a miss is found.
	 */
	public function test_no_negative_caching(): void {
		global $wpdb;

		// A post with no fundraiser row yet.
		$post_id = self::factory()->post->create();

		$this->assertNull( Fundraiser::find_by_post_id( $post_id ) );

		$fundraiser = $this->create_fundraiser( [ 'post_id' => $post_id ] );
		// save() may have replaced the shell post; read the actual value.
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}missiondp_fundraisers WHERE id = %d", $fundraiser->id ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);

		$this->assertNotNull( Fundraiser::find_by_post_id( $post_id ) );
	}

	/**
	 * Test recompute_aggregates() invalidates the fundraiser memo.
	 */
	public function test_fundraiser_recompute_aggregates_invalidates(): void {
		$fundraiser = $this->create_fundraiser();

		$this->assertSame( 0, Fundraiser::find( $fundraiser->id )->total_raised );

		$transaction = new Transaction(
			[
				'status'        => 'pending',
				'donor_id'      => 1,
				'campaign_id'   => 1,
				'fundraiser_id' => $fundraiser->id,
				'amount'        => 5000,
			]
		);
		$transaction->save();
		$transaction->status = 'completed';
		$transaction->save();

		$this->assertSame( 5000, Fundraiser::find( $fundraiser->id )->total_raised );
	}

	/**
	 * Test the campaign aggregates action invalidates the campaign memo.
	 */
	public function test_campaign_aggregates_action_invalidates(): void {
		$campaign = new Campaign( [ 'title' => 'Drive' ] );
		$campaign->save();

		$this->assertSame( 0, Campaign::find( $campaign->id )->total_raised );

		$transaction = new Transaction(
			[
				'status'      => 'pending',
				'donor_id'    => 1,
				'campaign_id' => $campaign->id,
				'amount'      => 7500,
			]
		);
		$transaction->save();
		$transaction->status = 'completed';
		$transaction->save();

		$this->assertSame( 7500, Campaign::find( $campaign->id )->total_raised );
	}

	/**
	 * Test deleting a captain's fundraiser flushes memoized team rows.
	 */
	public function test_fundraiser_delete_flushes_team_cache(): void {
		$team = new Team(
			[
				'campaign_id' => 1,
				'name'        => 'Trail Blazers',
			]
		);
		$team->save();

		$captain = $this->create_fundraiser( [ 'team_id' => $team->id ] );

		$team->captain_id = $captain->id;
		$team->save();

		$this->assertSame( $captain->id, Team::find( $team->id )->captain_id );

		( new FundraiserDataStore() )->delete( $captain->id );

		$this->assertNull( Team::find( $team->id )->captain_id );
	}

	/**
	 * Test deleting a team flushes memoized member fundraiser rows.
	 */
	public function test_team_delete_flushes_fundraiser_cache(): void {
		$team = new Team(
			[
				'campaign_id' => 1,
				'name'        => 'Trail Blazers',
			]
		);
		$team->save();

		$member = $this->create_fundraiser( [ 'team_id' => $team->id ] );

		$this->assertSame( $team->id, Fundraiser::find( $member->id )->team_id );

		( new TeamDataStore() )->delete( $team->id );

		$this->assertNull( Fundraiser::find( $member->id )->team_id );
	}

	/**
	 * Test warm_by_post_ids() serves later fundraiser post-ID lookups from the memo.
	 */
	public function test_warm_by_post_ids_serves_fundraiser_lookups(): void {
		$fundraisers = [
			$this->create_fundraiser( [ 'donor_id' => 1 ] ),
			$this->create_fundraiser( [ 'donor_id' => 2 ] ),
			$this->create_fundraiser( [ 'donor_id' => 3 ] ),
		];

		$post_ids = wp_list_pluck( $fundraisers, 'post_id' );

		wp_cache_flush();
		Fundraiser::warm_by_post_ids( $post_ids );

		$found   = [];
		$queries = $this->count_queries(
			function () use ( $post_ids, &$found ): void {
				foreach ( $post_ids as $post_id ) {
					$found[] = Fundraiser::find_by_post_id( $post_id );
				}
			}
		);

		$this->assertSame( 0, $queries );
		$this->assertSame( wp_list_pluck( $fundraisers, 'id' ), wp_list_pluck( $found, 'id' ) );
	}

	/**
	 * Test warm_by_post_ids() serves later team post-ID lookups from the memo.
	 */
	public function test_warm_by_post_ids_serves_team_lookups(): void {
		$teams = [];

		foreach ( [ 'Alpha', 'Bravo', 'Charlie' ] as $name ) {
			$team = new Team(
				[
					'campaign_id' => 1,
					'name'        => $name,
				]
			);
			$team->save();
			$teams[] = $team;
		}

		$post_ids = wp_list_pluck( $teams, 'post_id' );

		wp_cache_flush();
		Team::warm_by_post_ids( $post_ids );

		$found   = [];
		$queries = $this->count_queries(
			function () use ( $post_ids, &$found ): void {
				foreach ( $post_ids as $post_id ) {
					$found[] = Team::find_by_post_id( $post_id );
				}
			}
		);

		$this->assertSame( 0, $queries );
		$this->assertSame( wp_list_pluck( $teams, 'id' ), wp_list_pluck( $found, 'id' ) );
	}

	/**
	 * Test warm_by_post_ids() with no valid post IDs runs no queries.
	 */
	public function test_warm_by_post_ids_empty_is_a_no_op(): void {
		$queries = $this->count_queries(
			fn() => Fundraiser::warm_by_post_ids( [ 0, -5 ] )
		);

		$this->assertSame( 0, $queries );
	}

	/**
	 * Test the entity groups are isolated from each other.
	 */
	public function test_cache_isolation_between_entity_types(): void {
		$campaign = new Campaign( [ 'title' => 'Drive' ] );
		$campaign->save();
		$team = new Team(
			[
				'campaign_id' => $campaign->id,
				'name'        => 'Trail Blazers',
			]
		);
		$team->save();
		$fundraiser = $this->create_fundraiser( [ 'campaign_id' => $campaign->id ] );

		$this->assertSame( 'Drive', Campaign::find( $campaign->id )->title );
		$this->assertSame( 'Trail Blazers', Team::find( $team->id )->name );
		$this->assertSame( 'Original headline', Fundraiser::find( $fundraiser->id )->headline );

		// Repeat from the memo.
		$this->assertSame( 'Drive', Campaign::find( $campaign->id )->title );
		$this->assertSame( 'Trail Blazers', Team::find( $team->id )->name );
		$this->assertSame( 'Original headline', Fundraiser::find( $fundraiser->id )->headline );
	}
}
