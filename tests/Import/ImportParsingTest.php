<?php
/**
 * Tests for import value parsing, header mapping, and row validation.
 *
 * Amount/date parsing is exercised through real imports (the helpers are
 * private and the import is their only consumer), so these tests survive
 * refactors of the internals.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Import;

use MissionDP\Database\DatabaseModule;
use MissionDP\Export\ExportService;
use MissionDP\Import\ColumnMapper;
use MissionDP\Import\ImportService;
use MissionDP\Import\Validators\RowValidator;
use MissionDP\Models\Donor;
use MissionDP\Models\ImportJob;
use MissionDP\Models\Transaction;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * Import parsing test class.
 */
class ImportParsingTest extends WP_UnitTestCase {

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
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transactions" );
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
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );

		delete_option( SettingsService::OPTION_NAME );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Write a CSV file and return its path.
	 *
	 * @param string[] $headers Header row.
	 * @param array    $rows    Data rows.
	 * @param string   $prefix  Raw bytes written before the header (e.g. a BOM).
	 * @return string
	 */
	private function write_csv( array $headers, array $rows, string $prefix = '' ): string {
		$path     = wp_tempnam( 'mission-test-import' );
		$csv_path = $path . '.csv';
		rename( $path, $csv_path );

		$handle = fopen( $csv_path, 'w' );
		if ( '' !== $prefix ) {
			fwrite( $handle, $prefix );
		}
		fputcsv( $handle, $headers );
		foreach ( $rows as $row ) {
			fputcsv( $handle, $row );
		}
		fclose( $handle );

		$this->temp_files[] = $csv_path;

		return $csv_path;
	}

	/**
	 * Validate a CSV and return the preview payload.
	 *
	 * @param string $path CSV path.
	 * @param string $type Import type.
	 * @return array
	 */
	private function validate_csv( string $path, string $type ): array {
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

		return $preview;
	}

	/**
	 * Run a full import of a CSV and return the terminal job.
	 *
	 * @param string $path CSV path.
	 * @param string $type Import type.
	 * @return ImportJob
	 */
	private function import_csv( string $path, string $type ): ImportJob {
		$preview = $this->validate_csv( $path, $type );
		$started = $this->service->start_import( $preview['file_id'], 'skip', $this->user_id );

		$this->assertIsArray( $started, 'start_import failed: ' . ( is_wp_error( $started ) ? $started->get_error_message() : '' ) );

		for ( $i = 0; $i < 25; $i++ ) {
			$job = ImportJob::find_by_job_id( $started['job_id'] );

			if ( in_array( $job->status, [ ImportJob::STATUS_COMPLETED, ImportJob::STATUS_FAILED, ImportJob::STATUS_CANCELLED ], true ) ) {
				return $job;
			}

			do_action( 'missiondp_import_tick', $started['job_id'] );
		}

		$this->fail( 'Import did not reach a terminal state.' );
	}

	// -------------------------------------------------------------------------
	// Amount parsing
	// -------------------------------------------------------------------------

	/**
	 * Test common amount formats convert to minor units on a two-decimal site.
	 */
	public function test_amount_formats_parse_to_minor_units(): void {
		$cases = [
			'amt1@example.com' => [ '50', 5000 ],
			'amt2@example.com' => [ '50.5', 5050 ],
			'amt3@example.com' => [ '$1,500.50', 150050 ],
			'amt4@example.com' => [ '1,000', 100000 ],
			'amt5@example.com' => [ '$0.99', 99 ],
			'amt6@example.com' => [ ' 25.00 ', 2500 ],
			// Both separators present is unambiguous in any locale: the last
			// one is the decimal separator.
			'amt7@example.com' => [ '1.500,50', 150050 ],
			'amt8@example.com' => [ '€1.234.567,89', 123456789 ],
		];

		$rows = [];
		foreach ( $cases as $email => [ $raw ] ) {
			$rows[] = [ $email, $raw ];
		}

		$job = $this->import_csv( $this->write_csv( [ 'Email', 'Total Given' ], $rows ), 'donors' );
		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertSame( count( $cases ), $job->imported );

		foreach ( $cases as $email => [ $raw, $expected ] ) {
			$donor = Donor::find_by_email( $email );
			$this->assertNotNull( $donor, "Donor {$email} missing." );
			$this->assertSame( $expected, $donor->total_donated, "Amount '{$raw}' parsed wrong." );
		}
	}

	/**
	 * Test amounts respect the site currency's decimals (zero-decimal JPY).
	 *
	 * A yen amount of 1500 is 1500 minor units, not 150000.
	 */
	public function test_amounts_respect_zero_decimal_site_currency(): void {
		update_option( SettingsService::OPTION_NAME, [ 'test_mode' => false, 'currency' => 'JPY' ] );

		$path = $this->write_csv( [ 'Email', 'Total Given' ], [ [ 'yen@example.com', '1500' ] ] );
		$job  = $this->import_csv( $path, 'donors' );

		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertSame( 1500, Donor::find_by_email( 'yen@example.com' )->total_donated );
	}

	/**
	 * Test a transaction row's own currency column drives its amount parsing.
	 */
	public function test_transaction_amount_respects_row_currency(): void {
		$donor = new Donor( [ 'email' => 'rowcur@example.com', 'first_name' => 'Row' ] );
		$donor->save();

		$headers = [ 'Donor Email', 'Amount', 'Currency', 'Status', 'Charge ID' ];
		$rows    = [
			[ 'rowcur@example.com', '1500', 'jpy', 'completed', 'ch_jpy_1' ],
			[ 'rowcur@example.com', '15.00', 'usd', 'completed', 'ch_usd_1' ],
		];

		$job = $this->import_csv( $this->write_csv( $headers, $rows ), 'transactions' );
		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertSame( 2, $job->imported );

		$jpy = Transaction::find_by_gateway_transaction_id( 'ch_jpy_1' );
		$this->assertSame( 1500, $jpy->amount );
		$this->assertSame( 'jpy', $jpy->currency );

		$usd = Transaction::find_by_gateway_transaction_id( 'ch_usd_1' );
		$this->assertSame( 1500, $usd->amount );
		$this->assertSame( 'usd', $usd->currency );
	}

	// -------------------------------------------------------------------------
	// Date parsing
	// -------------------------------------------------------------------------

	/**
	 * Test accepted date formats normalize to MySQL datetimes and garbage
	 * dates warn without blocking the row.
	 */
	public function test_date_formats_normalize_and_garbage_warns_not_blocks(): void {
		$headers = [ 'Email', 'Last Donation' ];
		$rows    = [
			[ 'date1@example.com', '2024-01-15' ],
			[ 'date2@example.com', '01/15/2024' ],
			[ 'date3@example.com', 'Jan 15 2024 13:45' ],
			[ 'date4@example.com', 'not-a-date' ],
		];

		$path    = $this->write_csv( $headers, $rows );
		$preview = $this->validate_csv( $path, 'donors' );

		// The garbage date is a warning for donors, not a skipped row.
		$this->assertSame( 4, $preview['rows_importable'] );
		$this->assertNotEmpty( $preview['warnings'] );

		$job = $this->import_csv( $path, 'donors' );
		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertSame( 4, $job->imported );

		$this->assertSame( '2024-01-15 00:00:00', Donor::find_by_email( 'date1@example.com' )->last_transaction );
		$this->assertSame( '2024-01-15 00:00:00', Donor::find_by_email( 'date2@example.com' )->last_transaction );
		$this->assertSame( '2024-01-15 13:45:00', Donor::find_by_email( 'date3@example.com' )->last_transaction );
		$this->assertNull( Donor::find_by_email( 'date4@example.com' )->last_transaction );
	}

	// -------------------------------------------------------------------------
	// CSV encoding
	// -------------------------------------------------------------------------

	/**
	 * Test a UTF-8 BOM doesn't break first-column header mapping.
	 *
	 * Excel prepends a BOM when saving "CSV UTF-8"; without stripping it the
	 * first header reads as "\xEF\xBB\xBFEmail" and silently fails to map.
	 */
	public function test_utf8_bom_does_not_break_header_mapping(): void {
		$path = $this->write_csv(
			[ 'Email', 'First Name' ],
			[ [ 'bom@example.com', 'Bom' ] ],
			"\xEF\xBB\xBF"
		);

		$preview = $this->validate_csv( $path, 'donors' );

		$this->assertSame( [], $preview['columns_unmatched'] );
		$this->assertSame( 1, $preview['rows_importable'] );

		$job = $this->import_csv( $path, 'donors' );
		$this->assertSame( ImportJob::STATUS_COMPLETED, $job->status );
		$this->assertNotNull( Donor::find_by_email( 'bom@example.com' ) );
	}

	// -------------------------------------------------------------------------
	// Header mapping (ColumnMapper)
	// -------------------------------------------------------------------------

	/**
	 * Test header aliases and formatting variants map to canonical keys.
	 */
	public function test_header_variants_map_to_canonical_keys(): void {
		$export = new ExportService( new SettingsService() );
		$mapper = new ColumnMapper( $export );

		$resolved = $mapper->resolve_headers(
			[ 'E-mail Address', 'FIRST_NAME', 'last-name', 'Zip Code' ],
			'donors'
		);

		$this->assertSame( [ 'email', 'first_name', 'last_name', 'zip' ], $resolved['matched'] );
		$this->assertSame( [], $resolved['unmatched'] );
	}

	/**
	 * Test meta: headers pass through preserving the key, and unknown headers
	 * are reported rather than silently dropped.
	 */
	public function test_meta_headers_pass_through_and_unknown_headers_are_reported(): void {
		$export = new ExportService( new SettingsService() );
		$mapper = new ColumnMapper( $export );

		$resolved = $mapper->resolve_headers(
			[ 'Email', 'meta:Church Group', 'Mystery Column' ],
			'donors'
		);

		$this->assertSame( 'email', $resolved['matched'][0] );
		$this->assertSame( 'meta:Church Group', $resolved['matched'][1] );
		$this->assertNull( $resolved['matched'][2] );
		$this->assertSame( [ 'Mystery Column' ], $resolved['unmatched'] );
	}

	// -------------------------------------------------------------------------
	// Row validation severity
	// -------------------------------------------------------------------------

	/**
	 * Test the error/warning asymmetries that decide whether a row imports.
	 */
	public function test_validator_severity_rules(): void {
		$validator = new RowValidator();

		// Invalid transaction status blocks the row.
		$warnings = $validator->validate( [ 'amount' => '10', 'donor_email' => 'a@b.com', 'status' => 'bogus' ], 'transactions', 1 );
		$this->assertSame( 'error', $warnings[0]['severity'] );

		// Invalid campaign status only warns.
		$warnings = $validator->validate( [ 'title' => 'Fund', 'status' => 'bogus' ], 'campaigns', 1 );
		$this->assertNotEmpty( $warnings );
		$this->assertSame( 'warning', $warnings[0]['severity'] );

		// Non-numeric primary amount blocks the row.
		$warnings = $validator->validate( [ 'amount' => 'ten dollars', 'donor_email' => 'a@b.com' ], 'transactions', 1 );
		$this->assertSame( 'error', $warnings[0]['severity'] );

		// Missing required field blocks the row.
		$warnings = $validator->validate( [ 'first_name' => 'NoEmail' ], 'donors', 1 );
		$this->assertSame( 'error', $warnings[0]['severity'] );

		// A fully valid row produces no noise.
		$this->assertSame( [], $validator->validate( [ 'email' => 'ok@example.com' ], 'donors', 1 ) );
	}

	/**
	 * Test negative amounts block primary fields and warn on secondary ones.
	 *
	 * Mission models refunds as a refunded status, not negative rows, so a
	 * negative donation amount would silently corrupt aggregates.
	 */
	public function test_validator_flags_negative_amounts(): void {
		$validator = new RowValidator();

		$warnings = $validator->validate( [ 'amount' => '-10.00', 'donor_email' => 'a@b.com' ], 'transactions', 1 );
		$this->assertSame( 'error', $warnings[0]['severity'] );
		$this->assertSame( 'amount', $warnings[0]['column'] );

		// Subscriptions also warn about the missing gateway ID, so find the
		// amount issue by column instead of position.
		$warnings = $validator->validate( [ 'amount' => '-25', 'status' => 'active', 'donor_email' => 'a@b.com' ], 'subscriptions', 1 );
		$amount_warnings = array_values( array_filter( $warnings, static fn( $w ) => 'amount' === $w['column'] ) );
		$this->assertCount( 1, $amount_warnings );
		$this->assertSame( 'error', $amount_warnings[0]['severity'] );

		// Secondary amounts warn but do not block.
		$warnings = $validator->validate( [ 'amount' => '10', 'fee_amount' => '-0.50', 'donor_email' => 'a@b.com' ], 'transactions', 1 );
		$this->assertSame( 'warning', $warnings[0]['severity'] );
		$this->assertSame( 'fee_amount', $warnings[0]['column'] );

		$warnings = $validator->validate( [ 'email' => 'a@b.com', 'total_donated' => '-100' ], 'donors', 1 );
		$this->assertSame( 'warning', $warnings[0]['severity'] );
	}

	/**
	 * Test likely decimal-comma values warn while thousands separators don't.
	 */
	public function test_validator_warns_on_likely_decimal_comma(): void {
		$validator = new RowValidator();

		// "1,50" is probably €1.50, but will be read as 150.
		$warnings = $validator->validate( [ 'email' => 'a@b.com', 'total_donated' => '1,50' ], 'donors', 1 );
		$this->assertCount( 1, $warnings );
		$this->assertSame( 'warning', $warnings[0]['severity'] );

		// "1,500" reads as a thousands separator; no warning.
		$this->assertSame( [], $validator->validate( [ 'email' => 'a@b.com', 'total_donated' => '1,500' ], 'donors', 1 ) );

		// Both separators are unambiguous and parsed correctly; no warning.
		$this->assertSame( [], $validator->validate( [ 'email' => 'a@b.com', 'total_donated' => '1.500,50' ], 'donors', 1 ) );
	}
}
