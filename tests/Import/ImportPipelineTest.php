<?php
/**
 * End-to-end tests for the import pipeline.
 *
 * These drive the real job lifecycle: validate_file() -> start_import() ->
 * manual missiondp_import_tick firing (Action Scheduler actions are queued
 * but never auto-run inside PHPUnit), asserting against the resulting
 * database state rather than internals.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Import;

use MissionDP\Database\DatabaseModule;
use MissionDP\Export\ExportService;
use MissionDP\Import\ColumnMapper;
use MissionDP\Import\ImportService;
use MissionDP\Import\Validators\RowValidator;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\ImportJob;
use MissionDP\Models\Transaction;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * Import pipeline test class.
 */
class ImportPipelineTest extends WP_UnitTestCase {

	/**
	 * Small batch size so multi-batch paths are exercised by small files.
	 */
	private const BATCH_SIZE = 3;

	/**
	 * Service under test.
	 *
	 * @var ImportService
	 */
	private ImportService $service;

	/**
	 * Admin user running the imports.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Temp files created during a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $temp_files = [];

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_import_jobs" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_activity_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transaction_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_notes" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_tributes" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_subscriptionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_campaigns" );

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$export        = new ExportService( new SettingsService() );
		$this->service = new ImportService( $export, new ColumnMapper( $export ), new RowValidator() );

		update_option( SettingsService::OPTION_NAME, [ 'test_mode' => false ] );

		add_filter( 'missiondp_import_batch_size', [ $this, 'filter_batch_size' ] );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		remove_filter( 'missiondp_import_batch_size', [ $this, 'filter_batch_size' ] );

		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = [];

		$uploads = wp_get_upload_dir();
		$stored  = glob( trailingslashit( $uploads['basedir'] ) . 'mission-imports/mdp_job_*' );
		foreach ( $stored ?: [] as $file ) {
			unlink( $file );
		}

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_import_jobs" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_activity_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transaction_history" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_tributes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );

		delete_option( SettingsService::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * Filter callback pinning the batch size.
	 *
	 * @return int
	 */
	public function filter_batch_size(): int {
		return self::BATCH_SIZE;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Write a CSV file and return its path.
	 *
	 * @param string[] $headers Header row.
	 * @param array    $rows    Data rows.
	 * @param string   $raw_suffix Raw content appended verbatim (e.g. blank lines).
	 * @return string
	 */
	private function write_csv( array $headers, array $rows, string $raw_suffix = '' ): string {
		$path = wp_tempnam( 'mission-test-import' );
		// wp_tempnam creates a .tmp file; validate_file() routes on extension.
		$csv_path = $path . '.csv';
		rename( $path, $csv_path );

		$handle = fopen( $csv_path, 'w' );
		fputcsv( $handle, $headers );
		foreach ( $rows as $row ) {
			fputcsv( $handle, $row );
		}
		if ( '' !== $raw_suffix ) {
			fwrite( $handle, $raw_suffix );
		}
		fclose( $handle );

		$this->temp_files[] = $csv_path;

		return $csv_path;
	}

	/**
	 * Run validate -> start for a CSV and return the started job payload.
	 *
	 * @param string $path     CSV path.
	 * @param string $type     Import type.
	 * @param string $strategy Duplicate strategy.
	 * @return array Started job payload.
	 */
	private function start_import_for( string $path, string $type, string $strategy = 'skip' ): array {
		$preview = $this->service->validate_file(
			[
				'tmp_name' => $path,
				'name'     => basename( $path ),
				'size'     => filesize( $path ),
				'type'     => 'text/csv',
				'error'    => 0,
			],
			$type
		);

		$this->assertIsArray( $preview, 'validate_file failed: ' . ( is_wp_error( $preview ) ? $preview->get_error_message() : '' ) );

		$job = $this->service->start_import( $preview['file_id'], $strategy, $this->user_id );

		$this->assertIsArray( $job, 'start_import failed: ' . ( is_wp_error( $job ) ? $job->get_error_message() : '' ) );

		return $job;
	}

	/**
	 * Fire missiondp_import_tick until the job reaches a terminal state.
	 *
	 * @param string $job_id    Public job token.
	 * @param int    $max_ticks Safety cap; fails the test if exceeded.
	 * @return ImportJob Final job state.
	 */
	private function run_job_to_completion( string $job_id, int $max_ticks = 25 ): ImportJob {
		for ( $i = 0; $i < $max_ticks; $i++ ) {
			do_action( 'missiondp_import_tick', $job_id );

			$job = ImportJob::find_by_job_id( $job_id );
			$this->assertNotNull( $job, 'Job disappeared mid-run.' );

			if ( in_array( $job->status, [ ImportJob::STATUS_COMPLETED, ImportJob::STATUS_FAILED, ImportJob::STATUS_CANCELLED ], true ) ) {
				return $job;
			}
		}

		$this->fail( "Job {$job_id} did not reach a terminal state within {$max_ticks} ticks (status: {$job->status})." );
	}

	/**
	 * Run a full import of a CSV and return the completed job.
	 *
	 * @param string $path     CSV path.
	 * @param string $type     Import type.
	 * @param string $strategy Duplicate strategy.
	 * @return ImportJob
	 */
	private function import_csv( string $path, string $type, string $strategy = 'skip' ): ImportJob {
		$started = $this->start_import_for( $path, $type, $strategy );

		return $this->run_job_to_completion( $started['job_id'] );
	}

	/**
	 * Build N donor rows.
	 *
	 * @param int    $count  Row count.
	 * @param string $prefix Email prefix for uniqueness.
	 * @return array{0: string[], 1: array}
	 */
	private function donor_csv_data( int $count, string $prefix = 'batch' ): array {
		$headers = [ 'Email', 'First Name', 'Last Name' ];
		$rows    = [];

		for ( $i = 1; $i <= $count; $i++ ) {
			$rows[] = [ "{$prefix}{$i}@example.com", "First{$i}", "Last{$i}" ];
		}

		return [ $headers, $rows ];
	}

	// -------------------------------------------------------------------------
	// Batching / resume mechanics
	// -------------------------------------------------------------------------

	/**
	 * Test a donor import spanning multiple batches imports every row exactly once.
	 */
	public function test_donor_import_spans_multiple_batches_without_loss_or_duplication(): void {
		[ $headers, $rows ] = $this->donor_csv_data( 10 );
		$path               = $this->write_csv( $headers, $rows );

		$job = $this->import_csv( $path, 'donors' );

		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertSame( 10, $job->imported );
		$this->assertSame( 10, $job->processed_rows );
		$this->assertSame( 0, $job->errors );
		$this->assertSame( 10, Donor::count() );

		// Every row landed exactly once — batch boundaries didn't drop or repeat rows.
		for ( $i = 1; $i <= 10; $i++ ) {
			$donor = Donor::find_by_email( "batch{$i}@example.com" );
			$this->assertNotNull( $donor, "Donor row {$i} was not imported." );
			$this->assertSame( "First{$i}", $donor->first_name );
		}

		// The uploaded file is removed once the job completes.
		$this->assertFileDoesNotExist( $job->file_path );
	}

	/**
	 * Test a file whose row count is an exact multiple of the batch size.
	 */
	public function test_import_completes_when_row_count_is_exact_batch_multiple(): void {
		[ $headers, $rows ] = $this->donor_csv_data( self::BATCH_SIZE * 2, 'exact' );
		$path               = $this->write_csv( $headers, $rows );

		$job = $this->import_csv( $path, 'donors' );

		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertSame( self::BATCH_SIZE * 2, $job->imported );
		$this->assertSame( self::BATCH_SIZE * 2, Donor::count() );
	}

	/**
	 * Test a quoted multi-line field doesn't break batch offset arithmetic.
	 *
	 * The CSV slice reader skips `processed_rows` rows on every tick. A quoted
	 * field containing newlines must count as one row, or every batch after it
	 * reads from the wrong offset.
	 */
	public function test_quoted_multiline_field_spans_batches_safely(): void {
		$headers = [ 'Email', 'First Name', 'Address 1' ];
		$rows    = [];

		for ( $i = 1; $i <= 7; $i++ ) {
			// Row 2 (inside batch 1) carries an embedded newline and comma.
			$address = ( 2 === $i ) ? "Apt 4,\nSecond Floor" : "{$i} Main St";
			$rows[]  = [ "multiline{$i}@example.com", "First{$i}", $address ];
		}

		$path = $this->write_csv( $headers, $rows );
		$job  = $this->import_csv( $path, 'donors' );

		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertSame( 7, $job->imported );
		$this->assertSame( 7, Donor::count() );

		// The multi-line value itself round-tripped.
		$donor = Donor::find_by_email( 'multiline2@example.com' );
		$this->assertSame( "Apt 4,\nSecond Floor", $donor->address_1 );

		// Rows after the multi-line one didn't shift.
		$last = Donor::find_by_email( 'multiline7@example.com' );
		$this->assertNotNull( $last );
		$this->assertSame( 'First7', $last->first_name );
	}

	/**
	 * Test blank lines in the file don't shift rows or stall completion.
	 */
	public function test_blank_lines_are_skipped_without_misalignment(): void {
		[ $headers, $rows ] = $this->donor_csv_data( 5, 'blank' );
		$path               = $this->write_csv( $headers, $rows, "\n\n" );

		$job = $this->import_csv( $path, 'donors' );

		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertSame( 5, $job->imported );
		$this->assertSame( 5, Donor::count() );
	}

	// -------------------------------------------------------------------------
	// Transactions: resolution, provenance, aggregates
	// -------------------------------------------------------------------------

	/**
	 * Test transaction import resolves donors/campaigns and stamps provenance.
	 */
	public function test_transaction_import_resolves_references_and_stamps_import_job_id(): void {
		$donor = new Donor( [ 'email' => 'jane@example.com', 'first_name' => 'Jane' ] );
		$donor->save();

		$campaign = new Campaign( [ 'title' => 'General Fund', 'goal_amount' => 100000 ] );
		$campaign->save();

		$headers = [ 'Donor Email', 'Amount', 'Status', 'Charge ID', 'Campaign Title' ];
		$rows    = [
			// Mixed-case email must resolve to the same donor.
			[ 'Jane@Example.COM', '50.00', 'completed', 'ch_import_1', 'General Fund' ],
			[ 'jane@example.com', '25.00', 'completed', 'ch_import_2', 'General Fund' ],
		];

		$path = $this->write_csv( $headers, $rows );
		$job  = $this->import_csv( $path, 'transactions' );

		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertSame( 2, $job->imported );
		$this->assertSame( 0, $job->errors );

		$imported = Transaction::find_by_gateway_transaction_id( 'ch_import_1' );
		$this->assertNotNull( $imported );
		$this->assertSame( $donor->id, $imported->donor_id );
		$this->assertSame( $campaign->id, $imported->campaign_id );
		$this->assertSame( 5000, $imported->amount );

		// Provenance: imported rows carry the numeric import job ID.
		$this->assertSame( $job->id, $imported->import_job_id );
		$this->assertSame( $job->id, Transaction::find_by_gateway_transaction_id( 'ch_import_2' )->import_job_id );
	}

	/**
	 * Test donor and campaign aggregates are recomputed after an import.
	 *
	 * Imports write via create_silent() (no per-row aggregate hooks); the
	 * post-import recompute pass is the only thing keeping dashboard numbers
	 * correct on migrated sites.
	 */
	public function test_aggregates_recomputed_after_transaction_import(): void {
		$donor = new Donor( [ 'email' => 'aggregate@example.com', 'first_name' => 'Agg' ] );
		$donor->save();

		$campaign = new Campaign( [ 'title' => 'Aggregate Fund', 'goal_amount' => 100000 ] );
		$campaign->save();

		$headers = [ 'Donor Email', 'Amount', 'Status', 'Charge ID', 'Campaign Title' ];
		$rows    = [
			[ 'aggregate@example.com', '10.00', 'completed', 'ch_agg_1', 'Aggregate Fund' ],
			[ 'aggregate@example.com', '15.00', 'completed', 'ch_agg_2', 'Aggregate Fund' ],
			[ 'aggregate@example.com', '99.00', 'pending', 'ch_agg_3', 'Aggregate Fund' ],
		];

		$path = $this->write_csv( $headers, $rows );
		$job  = $this->import_csv( $path, 'transactions' );

		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertSame( 3, $job->imported );

		$campaign = Campaign::find( $campaign->id );
		$donor    = Donor::find( $donor->id );

		// Only the two completed transactions count toward aggregates.
		$this->assertSame( 2500, $campaign->total_raised );
		$this->assertSame( 2, $campaign->transaction_count );
		$this->assertSame( 1, $campaign->donor_count );
		$this->assertSame( 2500, $donor->total_donated );
		$this->assertSame( 2, $donor->transaction_count );
	}

	/**
	 * Test a row with an unresolvable donor errors without aborting the batch.
	 */
	public function test_unresolvable_donor_row_is_recorded_and_skipped_not_fatal(): void {
		$donor = new Donor( [ 'email' => 'known@example.com', 'first_name' => 'Known' ] );
		$donor->save();

		$headers = [ 'Donor Email', 'Amount', 'Status', 'Charge ID' ];
		$rows    = [
			[ 'known@example.com', '10.00', 'completed', 'ch_ok_1' ],
			[ 'ghost@example.com', '20.00', 'completed', 'ch_bad_1' ],
			[ 'known@example.com', '30.00', 'completed', 'ch_ok_2' ],
		];

		$path = $this->write_csv( $headers, $rows );
		$job  = $this->import_csv( $path, 'transactions' );

		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertSame( 2, $job->imported );
		$this->assertSame( 1, $job->errors );

		$details = json_decode( $job->error_details, true );
		$this->assertCount( 1, $details );
		$this->assertSame( 2, $details[0]['row'] );

		// The rows around the bad one still imported.
		$this->assertNotNull( Transaction::find_by_gateway_transaction_id( 'ch_ok_1' ) );
		$this->assertNull( Transaction::find_by_gateway_transaction_id( 'ch_bad_1' ) );
		$this->assertNotNull( Transaction::find_by_gateway_transaction_id( 'ch_ok_2' ) );
	}

	// -------------------------------------------------------------------------
	// Duplicate strategies
	// -------------------------------------------------------------------------

	/**
	 * Test re-importing the same file with the skip strategy is idempotent.
	 */
	public function test_skip_strategy_reimport_is_idempotent(): void {
		[ $headers, $rows ] = $this->donor_csv_data( 4, 'skip' );

		$first = $this->import_csv( $this->write_csv( $headers, $rows ), 'donors', 'skip' );
		$this->assertSame( 4, $first->imported );

		$second = $this->import_csv( $this->write_csv( $headers, $rows ), 'donors', 'skip' );

		$this->assertSame( ImportJob::STATUS_COMPLETED, $second->status );
		$this->assertSame( 0, $second->imported );
		$this->assertSame( 4, $second->skipped );
		$this->assertSame( 4, Donor::count() );
	}

	/**
	 * Test the update strategy updates matched rows without creating new ones,
	 * and never claims provenance over organic transactions.
	 */
	public function test_update_strategy_updates_in_place_and_preserves_organic_provenance(): void {
		$donor = new Donor( [ 'email' => 'update@example.com', 'first_name' => 'Up' ] );
		$donor->save();

		// An organic transaction that the import file also references.
		$organic = new Transaction(
			[
				'status'                 => 'completed',
				'donor_id'               => $donor->id,
				'amount'                 => 1000,
				'total_amount'           => 1000,
				'payment_gateway'        => 'stripe',
				'gateway_transaction_id' => 'ch_organic_1',
				'date_completed'         => current_time( 'mysql', true ),
			]
		);
		$organic->save();

		$headers = [ 'Donor Email', 'Amount', 'Status', 'Charge ID' ];
		$rows    = [
			[ 'update@example.com', '20.00', 'completed', 'ch_organic_1' ],
			[ 'update@example.com', '30.00', 'completed', 'ch_fresh_1' ],
		];

		$path = $this->write_csv( $headers, $rows );
		$job  = $this->import_csv( $path, 'transactions', 'update' );

		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertSame( 1, $job->imported );
		$this->assertSame( 1, $job->updated );
		$this->assertSame( 2, Transaction::count() );

		// The organic row was updated in place but keeps organic provenance.
		$updated = Transaction::find( $organic->id );
		$this->assertSame( 2000, $updated->amount );
		$this->assertSame( 0, $updated->import_job_id );

		// The new row is stamped as imported.
		$this->assertSame( $job->id, Transaction::find_by_gateway_transaction_id( 'ch_fresh_1' )->import_job_id );
	}

	/**
	 * Test the update strategy never mutates the dedupe key itself.
	 *
	 * Duplicate detection matches on gateway_transaction_id and the update
	 * branch writes every prepared field back, so this property currently
	 * holds only because the matched key and the written key come from the
	 * same CSV cell. This pins that invariant: if a refactor ever lets an
	 * update change the key, re-imports would stop deduplicating and
	 * silently create duplicates.
	 */
	public function test_update_strategy_never_mutates_the_dedupe_key(): void {
		$donor = new Donor( [ 'email' => 'pin@example.com', 'first_name' => 'Pin' ] );
		$donor->save();

		$organic = new Transaction(
			[
				'status'                 => 'completed',
				'donor_id'               => $donor->id,
				'amount'                 => 1000,
				'total_amount'           => 1000,
				'payment_gateway'        => 'stripe',
				'gateway_transaction_id' => 'ch_pin_1',
				'date_completed'         => current_time( 'mysql', true ),
			]
		);
		$organic->save();

		$headers = [ 'Donor Email', 'Amount', 'Status', 'Charge ID' ];
		$rows    = [ [ 'pin@example.com', '20.00', 'completed', 'ch_pin_1' ] ];

		// Two consecutive update imports of the same row. If the first run
		// mutated the key, the second run would fail to match and insert a
		// duplicate instead of updating.
		foreach ( [ 'first', 'second' ] as $run ) {
			$job = $this->import_csv( $this->write_csv( $headers, $rows ), 'transactions', 'update' );

			$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status, "{$run} run did not complete." );
			$this->assertSame( 1, $job->updated, "{$run} run should update exactly one row." );
			$this->assertSame( 0, $job->imported, "{$run} run should create nothing." );
		}

		$this->assertSame( 1, Transaction::count() );

		$row = Transaction::find( $organic->id );
		$this->assertSame( 'ch_pin_1', $row->gateway_transaction_id );
		$this->assertSame( 2000, $row->amount );
	}

	// -------------------------------------------------------------------------
	// Cancellation
	// -------------------------------------------------------------------------

	/**
	 * Test cancelling mid-job stops further processing and cleans up the file.
	 */
	public function test_cancel_mid_job_stops_processing(): void {
		[ $headers, $rows ] = $this->donor_csv_data( 10, 'cancel' );
		$path               = $this->write_csv( $headers, $rows );

		$started = $this->start_import_for( $path, 'donors' );
		$job_id  = $started['job_id'];

		// Without Action Scheduler, start_import() processes the first batch
		// synchronously, so measure from wherever start left off.
		$mid = ImportJob::find_by_job_id( $job_id );
		$this->assertSame( ImportJob::STATUS_PROCESSING, $mid->status );
		$this->assertLessThan( 10, $mid->imported, 'Job unexpectedly finished before it could be cancelled.' );

		do_action( 'missiondp_import_tick', $job_id );

		$mid_after_tick = ImportJob::find_by_job_id( $job_id );
		$this->assertSame( $mid->imported + self::BATCH_SIZE, $mid_after_tick->imported );

		$cancelled = $this->service->cancel_job( $job_id, $this->user_id );
		$this->assertIsArray( $cancelled );

		// Further ticks must not import more rows.
		do_action( 'missiondp_import_tick', $job_id );
		do_action( 'missiondp_import_tick', $job_id );

		$final = ImportJob::find_by_job_id( $job_id );
		$this->assertSame( ImportJob::STATUS_CANCELLED, $final->status );
		$this->assertSame( $mid_after_tick->imported, $final->imported );
		$this->assertSame( $mid_after_tick->imported, Donor::count() );
		$this->assertFileDoesNotExist( $final->file_path );
	}
}
