<?php
/**
 * Tests for the MigrationPhase model and its datastore.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Migration;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\MigrationPhase;
use WP_UnitTestCase;

/**
 * Covers atomic counters, the forward-only cursor guard, terminal
 * transitions, and the job lookups.
 */
class MigrationPhaseTest extends WP_UnitTestCase {

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_migration_phases" );
		DatabaseModule::create_tables();
	}

	/**
	 * Clean up rows after each test.
	 */
	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_migration_phases" );

		parent::tear_down();
	}

	/**
	 * Build and save a phase row.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 */
	private function create_phase( array $overrides = [] ): MigrationPhase {
		$phase = new MigrationPhase(
			array_merge(
				[
					'job_id'      => 'job-1',
					'user_id'     => 1,
					'source'      => 'givewp',
					'entity'      => 'donors',
					'phase_order' => 0,
					'total_items' => 10,
				],
				$overrides
			)
		);
		$phase->save();

		return $phase;
	}

	/**
	 * increment_counts applies deltas without read-modify-write.
	 */
	public function test_increment_counts_is_cumulative(): void {
		$phase = $this->create_phase();

		$phase->increment_counts(
			[
				'processed_items' => 3,
				'imported'        => 2,
				'skipped'         => 1,
			]
		);
		$phase->increment_counts(
			[
				'processed_items' => 2,
				'imported'        => 1,
				'errors'          => 1,
			]
		);

		$fresh = $phase->fresh();
		$this->assertSame( 5, $fresh->processed_items );
		$this->assertSame( 3, $fresh->imported );
		$this->assertSame( 1, $fresh->skipped );
		$this->assertSame( 1, $fresh->errors );
	}

	/**
	 * The cursor only ever moves forward, so a stale tick cannot rewind it.
	 */
	public function test_advance_cursor_never_moves_backwards(): void {
		$phase = $this->create_phase();

		$phase->advance_cursor( 50 );
		$this->assertSame( 50, $phase->fresh()->last_source_id );

		$phase->advance_cursor( 20 );
		$this->assertSame( 50, $phase->fresh()->last_source_id );

		$phase->advance_cursor( 80 );
		$this->assertSame( 80, $phase->fresh()->last_source_id );
	}

	/**
	 * Terminal transitions stamp timestamps and report is_terminal().
	 */
	public function test_terminal_transitions(): void {
		$phase = $this->create_phase();
		$this->assertFalse( $phase->is_terminal() );

		$phase->mark_processing();
		$this->assertSame( MigrationPhase::STATUS_PROCESSING, $phase->fresh()->status );
		$this->assertNotNull( $phase->fresh()->started_at );
		$this->assertFalse( $phase->fresh()->is_terminal() );

		$phase->mark_completed();
		$fresh = $phase->fresh();
		$this->assertSame( MigrationPhase::STATUS_COMPLETED, $fresh->status );
		$this->assertNotNull( $fresh->completed_at );
		$this->assertTrue( $fresh->is_terminal() );

		$failed = $this->create_phase( [ 'entity' => 'transactions' ] );
		$failed->mark_failed( 'boom' );
		$this->assertSame( MigrationPhase::STATUS_FAILED, $failed->fresh()->status );
		$this->assertSame( 'boom', $failed->fresh()->last_error );
		$this->assertTrue( $failed->fresh()->is_terminal() );
	}

	/**
	 * error_details append and cap at 25 entries.
	 */
	public function test_error_details_append_and_cap(): void {
		$phase = $this->create_phase();

		$first = [];
		for ( $i = 1; $i <= 20; $i++ ) {
			$first[] = [
				'source_id' => $i,
				'message'   => "error {$i}",
			];
		}
		$phase->append_error_details( $first );

		$fresh = $phase->fresh();
		$this->assertCount( 20, $fresh->decode_error_details() );

		$fresh->append_error_details(
			[
				[
					'source_id' => 21,
					'message'   => 'error 21',
				],
				[
					'source_id' => 22,
					'message'   => 'error 22',
				],
				[
					'source_id' => 23,
					'message'   => 'error 23',
				],
				[
					'source_id' => 24,
					'message'   => 'error 24',
				],
				[
					'source_id' => 25,
					'message'   => 'error 25',
				],
				[
					'source_id' => 26,
					'message'   => 'error 26',
				],
			]
		);

		$this->assertCount( 25, $fresh->fresh()->decode_error_details() );
	}

	/**
	 * Job lookups: active prefers running jobs; latest returns the newest.
	 */
	public function test_job_lookups(): void {
		$this->assertNull( MigrationPhase::find_active_job_id() );
		$this->assertNull( MigrationPhase::find_latest_job_id() );

		$done = $this->create_phase( [ 'job_id' => 'job-done' ] );
		$done->mark_completed();

		$this->assertNull( MigrationPhase::find_active_job_id() );
		$this->assertSame( 'job-done', MigrationPhase::find_latest_job_id() );

		$this->create_phase( [ 'job_id' => 'job-active' ] );

		$this->assertSame( 'job-active', MigrationPhase::find_active_job_id() );
		$this->assertSame( 'job-active', MigrationPhase::find_latest_job_id() );

		$phases = MigrationPhase::find_for_job( 'job-active' );
		$this->assertCount( 1, $phases );
		$this->assertSame( 'donors', $phases[0]->entity );
	}
}
