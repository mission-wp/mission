<?php
/**
 * End-to-end tests for the GiveWP migration pipeline.
 *
 * These drive the real job lifecycle: scan() -> start() -> manual
 * missiondp_migration_tick firing (Action Scheduler actions are queued but
 * never auto-run inside PHPUnit), asserting against the resulting database
 * state. Fixture GiveWP tables are created with just the columns the readers
 * use.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Migration;

use MissionDP\Database\DatabaseModule;
use MissionDP\Migration\MigrationJobHandler;
use MissionDP\Migration\MigrationService;
use MissionDP\Migration\MigratorRegistry;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\MigrationPhase;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use WP_UnitTestCase;

/**
 * GiveWP migration pipeline test class.
 */
class GiveWPMigrationPipelineTest extends WP_UnitTestCase {

	/**
	 * Small batch size so multi-batch paths are exercised by small fixtures.
	 */
	private const BATCH_SIZE = 2;

	/**
	 * Service under test.
	 *
	 * @var MigrationService
	 */
	private MigrationService $service;

	/**
	 * Admin user running the migration.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Create Mission and fixture GiveWP tables once for the class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;

		DatabaseModule::create_tables();

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}give_donors (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				user_id bigint(20) NOT NULL DEFAULT 0,
				name mediumtext,
				email varchar(255) NOT NULL DEFAULT '',
				purchase_value mediumtext,
				purchase_count bigint(20) NOT NULL DEFAULT 0,
				date_created datetime NOT NULL DEFAULT '2024-01-01 00:00:00',
				PRIMARY KEY (id)
			)"
		);

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}give_donormeta (
				meta_id bigint(20) NOT NULL AUTO_INCREMENT,
				donor_id bigint(20) NOT NULL DEFAULT 0,
				meta_key varchar(255) DEFAULT NULL,
				meta_value longtext,
				PRIMARY KEY (meta_id)
			)"
		);

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}give_donationmeta (
				meta_id bigint(20) NOT NULL AUTO_INCREMENT,
				donation_id bigint(20) NOT NULL DEFAULT 0,
				meta_key varchar(255) DEFAULT NULL,
				meta_value longtext,
				PRIMARY KEY (meta_id)
			)"
		);

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}give_subscriptions (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				customer_id bigint(20) NOT NULL DEFAULT 0,
				period varchar(20) NOT NULL DEFAULT 'month',
				frequency bigint(20) NOT NULL DEFAULT 1,
				initial_amount decimal(18,10) NOT NULL DEFAULT 0,
				recurring_amount decimal(18,10) NOT NULL DEFAULT 0,
				bill_times bigint(20) NOT NULL DEFAULT 0,
				parent_payment_id bigint(20) NOT NULL DEFAULT 0,
				payment_mode varchar(20) NOT NULL DEFAULT 'live',
				product_id bigint(20) NOT NULL DEFAULT 0,
				created datetime NOT NULL DEFAULT '2024-01-01 00:00:00',
				expiration datetime NOT NULL DEFAULT '2030-01-01 00:00:00',
				status varchar(20) NOT NULL DEFAULT 'active',
				profile_id varchar(60) NOT NULL DEFAULT '',
				campaign_id int(10) DEFAULT NULL,
				PRIMARY KEY (id)
			)"
		);

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}give_campaigns (
				id int(10) NOT NULL AUTO_INCREMENT,
				form_id int(10) NOT NULL DEFAULT 0,
				campaign_type varchar(12) NOT NULL DEFAULT 'core',
				campaign_title text,
				short_desc text,
				long_desc text,
				campaign_goal int(10) NOT NULL DEFAULT 0,
				goal_type varchar(24) NOT NULL DEFAULT 'amount',
				status varchar(12) NOT NULL DEFAULT 'active',
				start_date datetime DEFAULT NULL,
				end_date datetime DEFAULT NULL,
				date_created datetime NOT NULL DEFAULT '2024-01-01 00:00:00',
				PRIMARY KEY (id)
			)"
		);

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}give_campaign_forms (
				campaign_id int(10) NOT NULL,
				form_id int(10) NOT NULL
			)"
		);
	}

	/**
	 * Drop the fixture GiveWP tables when the class finishes.
	 */
	public static function tear_down_after_class(): void {
		global $wpdb;

		foreach ( [ 'give_donors', 'give_donormeta', 'give_donationmeta', 'give_subscriptions', 'give_campaigns', 'give_campaign_forms' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		}

		parent::tear_down_after_class();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->service = new MigrationService( new MigratorRegistry() );

		add_filter( 'mission_migration_batch_size', [ $this, 'filter_batch_size' ] );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		remove_filter( 'mission_migration_batch_size', [ $this, 'filter_batch_size' ] );

		global $wpdb;

		foreach ( [ 'give_donors', 'give_donormeta', 'give_donationmeta', 'give_subscriptions', 'give_campaigns', 'give_campaign_forms' ] as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}

		foreach ( [ 'migration_phases', 'activity_log', 'transaction_history', 'notes', 'tributes', 'transactionmeta', 'transactions', 'subscriptionmeta', 'subscriptions', 'donormeta', 'donors', 'campaignmeta', 'campaigns' ] as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_{$table}" );
		}

		delete_option( MigrationService::LOCK_OPTION );

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
	 * Insert a GiveWP donor row (+ optional donormeta).
	 *
	 * @param string               $email Donor email.
	 * @param string               $name  Full name.
	 * @param array<string, string> $meta  Donor meta rows.
	 * @return int Donor ID.
	 */
	private function give_donor( string $email, string $name, array $meta = [] ): int {
		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}give_donors",
			[
				'email'        => $email,
				'name'         => $name,
				'date_created' => '2024-02-01 10:00:00',
			]
		);
		$donor_id = (int) $wpdb->insert_id;

		foreach ( $meta as $key => $value ) {
			$wpdb->insert(
				"{$wpdb->prefix}give_donormeta",
				[
					'donor_id'   => $donor_id,
					'meta_key'   => $key,
					'meta_value' => $value,
				]
			);
		}

		return $donor_id;
	}

	/**
	 * Insert a GiveWP donation (give_payment post + donationmeta).
	 *
	 * @param string               $status GiveWP post status.
	 * @param array<string, string> $meta   Donation meta rows.
	 * @param int                  $parent Parent payment post ID (renewals).
	 * @return int Payment post ID.
	 */
	private function give_payment( string $status, array $meta, int $parent = 0 ): int {
		global $wpdb;

		$payment_id = self::factory()->post->create(
			[
				'post_type'   => 'give_payment',
				'post_status' => $status,
				'post_parent' => $parent,
			]
		);

		foreach ( $meta as $key => $value ) {
			$wpdb->insert(
				"{$wpdb->prefix}give_donationmeta",
				[
					'donation_id' => $payment_id,
					'meta_key'    => $key,
					'meta_value'  => $value,
				]
			);
		}

		return $payment_id;
	}

	/**
	 * Build the standard fixture data set.
	 *
	 * @return array<string, int> Source IDs keyed by handle.
	 */
	private function seed_givewp_site(): array {
		global $wpdb;

		// Two campaigns; forms 101/102 belong to them via the junction table.
		$wpdb->insert(
			"{$wpdb->prefix}give_campaigns",
			[
				'campaign_title' => 'Clean Water Fund',
				'short_desc'     => 'Water for everyone.',
				'campaign_goal'  => 5000,
				'goal_type'      => 'amount',
				'status'         => 'active',
			]
		);
		$campaign_water = (int) $wpdb->insert_id;

		$wpdb->insert(
			"{$wpdb->prefix}give_campaigns",
			[
				'campaign_title' => 'Donor Drive',
				'campaign_goal'  => 100,
				'goal_type'      => 'donors',
				'status'         => 'active',
			]
		);
		$campaign_drive = (int) $wpdb->insert_id;

		$wpdb->insert(
			"{$wpdb->prefix}give_campaign_forms",
			[
				'campaign_id' => $campaign_water,
				'form_id'     => 101,
			]
		);
		$wpdb->insert(
			"{$wpdb->prefix}give_campaign_forms",
			[
				'campaign_id' => $campaign_drive,
				'form_id'     => 102,
			]
		);

		$alice = $this->give_donor(
			'alice@example.com',
			'Alice Smith',
			[
				'_give_donor_first_name'                => 'Alice',
				'_give_donor_last_name'                 => 'Smith',
				'_give_donor_address_billing_line1_0'   => '1 Main St',
				'_give_donor_address_billing_city_0'    => 'Springfield',
				'_give_donor_address_billing_state_0'   => 'IL',
				'_give_donor_address_billing_zip_0'     => '62701',
				'_give_donor_address_billing_country_0' => 'US',
				'_give_donor_company'                   => 'Acme Inc',
			]
		);
		$bob   = $this->give_donor( 'bob@example.com', 'Bob Jones' );
		$carol = $this->give_donor( 'carol@example.com', 'Carol King' );

		// P1: completed live donation with explicit campaign meta and a comment.
		$p1 = $this->give_payment(
			'publish',
			[
				'_give_payment_total'          => '25.000000',
				'_give_payment_currency'       => 'USD',
				'_give_payment_gateway'        => 'stripe_checkout',
				'_give_payment_mode'           => 'live',
				'_give_payment_transaction_id' => 'ch_111',
				'_give_payment_donor_id'       => (string) $alice,
				'_give_campaign_id'            => (string) $campaign_water,
				'_give_payment_form_id'        => '101',
				'_give_donation_comment'       => 'Keep it up!',
			]
		);

		// P2: pending live donation resolving its campaign via the form junction.
		$p2 = $this->give_payment(
			'pending',
			[
				'_give_payment_total'    => '10.00',
				'_give_payment_currency' => 'USD',
				'_give_payment_gateway'  => 'manual',
				'_give_payment_mode'     => 'live',
				'_give_payment_donor_id' => (string) $bob,
				'_give_payment_form_id'  => '102',
			]
		);

		// P3: test-mode donation, excluded unless include_test.
		$p3 = $this->give_payment(
			'publish',
			[
				'_give_payment_total'    => '99.00',
				'_give_payment_currency' => 'USD',
				'_give_payment_gateway'  => 'stripe',
				'_give_payment_mode'     => 'test',
				'_give_payment_donor_id' => (string) $carol,
				'_give_payment_form_id'  => '101',
			]
		);

		// P4: subscription signup payment (live).
		$p4 = $this->give_payment(
			'publish',
			[
				'_give_payment_total'         => '20.00',
				'_give_payment_currency'      => 'USD',
				'_give_payment_gateway'       => 'stripe',
				'_give_payment_mode'          => 'live',
				'_give_payment_donor_id'      => (string) $alice,
				'_give_payment_form_id'       => '101',
				'_give_is_donation_recurring' => '1',
			]
		);

		// S1: active monthly subscription whose campaign resolves via form 101.
		$wpdb->insert(
			"{$wpdb->prefix}give_subscriptions",
			[
				'customer_id'       => $alice,
				'period'            => 'month',
				'frequency'         => 1,
				'recurring_amount'  => '20.0000000000',
				'parent_payment_id' => $p4,
				'payment_mode'      => 'live',
				'product_id'        => 101,
				'status'            => 'active',
				'profile_id'        => 'sub_123',
			]
		);
		$s1 = (int) $wpdb->insert_id;

		// S2: finished daily subscription (lossy cadence, terminal status).
		$wpdb->insert(
			"{$wpdb->prefix}give_subscriptions",
			[
				'customer_id'       => $bob,
				'period'            => 'day',
				'frequency'         => 1,
				'recurring_amount'  => '5.00',
				'parent_payment_id' => $p2,
				'payment_mode'      => 'live',
				'product_id'        => 102,
				'status'            => 'completed',
				'profile_id'        => 'sub_456',
			]
		);
		$s2 = (int) $wpdb->insert_id;

		// Link P4 to S1 now that the subscription ID exists.
		$wpdb->insert(
			"{$wpdb->prefix}give_donationmeta",
			[
				'donation_id' => $p4,
				'meta_key'    => 'subscription_id',
				'meta_value'  => (string) $s1,
			]
		);

		// P5: renewal payment, child post of P4.
		$p5 = $this->give_payment(
			'publish',
			[
				'_give_payment_total'          => '20.00',
				'_give_payment_currency'       => 'USD',
				'_give_payment_gateway'        => 'stripe',
				'_give_payment_mode'           => 'live',
				'_give_payment_transaction_id' => 'ch_555',
				'_give_payment_donor_id'       => (string) $alice,
				'_give_payment_form_id'        => '101',
				'_give_is_donation_recurring'  => '1',
				'subscription_id'              => (string) $s1,
			],
			$p4
		);

		// P6: trashed donation, never migrated.
		$this->give_payment(
			'trash',
			[
				'_give_payment_total'    => '1.00',
				'_give_payment_currency' => 'USD',
				'_give_payment_donor_id' => (string) $alice,
			]
		);

		return [
			'campaign_water' => $campaign_water,
			'campaign_drive' => $campaign_drive,
			'alice'          => $alice,
			'bob'            => $bob,
			'carol'          => $carol,
			'p1'             => $p1,
			'p2'             => $p2,
			'p3'             => $p3,
			'p4'             => $p4,
			'p5'             => $p5,
			's1'             => $s1,
			's2'             => $s2,
		];
	}

	/**
	 * Fire migration ticks until the job reaches a terminal state.
	 *
	 * @param string $job_id Job token.
	 */
	private function run_until_terminal( string $job_id ): void {
		for ( $i = 0; $i < 200; $i++ ) {
			do_action( MigrationJobHandler::HOOK, $job_id );

			$phases = MigrationPhase::find_for_job( $job_id );
			if ( empty( $phases ) ) {
				return;
			}

			$status = $this->service->job_status( $phases );
			if ( in_array( $status, [ 'completed', 'failed', 'cancelled' ], true ) ) {
				return;
			}
		}

		$this->fail( 'Migration did not reach a terminal state within 200 ticks.' );
	}

	/**
	 * Start a migration and run it to completion, asserting it completed.
	 *
	 * @param bool $include_test Include test-mode data.
	 * @return string Job token.
	 */
	private function migrate( bool $include_test = false ): string {
		$status = $this->service->start( 'givewp', [ 'include_test' => $include_test ], $this->user_id );
		$this->assertIsArray( $status );

		$this->run_until_terminal( $status['job_id'] );

		$final = $this->service->get_status( $status['job_id'] );
		$this->assertSame( 'completed', $final['status'], wp_json_encode( $final ) );

		return $status['job_id'];
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * The scan reports counts that match the reader filters.
	 */
	public function test_scan_counts_and_checks(): void {
		$this->seed_givewp_site();

		$scan = $this->service->scan( 'givewp' );

		$this->assertIsArray( $scan );
		$this->assertSame( 2, $scan['counts']['campaigns'] );
		$this->assertSame( 3, $scan['counts']['donors'] );
		$this->assertSame( 4, $scan['counts']['transactions'] );
		$this->assertSame( 2, $scan['counts']['subscriptions'] );
		$this->assertSame( 1, $scan['counts']['test_transactions'] );
		$this->assertSame( 0, $scan['counts']['test_subscriptions'] );

		$check_ids = array_column( $scan['checks'], 'id' );
		$this->assertContains( 'source', $check_ids );
		$this->assertContains( 'database', $check_ids );
	}

	/**
	 * Scanning an unknown or empty source errors cleanly.
	 */
	public function test_scan_unknown_source(): void {
		$result = $this->service->scan( 'nope' );
		$this->assertWPError( $result );
		$this->assertSame( 'unknown_source', $result->get_error_code() );
	}

	/**
	 * Full pipeline: campaigns, donors, subscriptions, transactions, finalize.
	 */
	public function test_full_migration(): void {
		$ids = $this->seed_givewp_site();

		// Bob already exists in Mission; the migration must reuse him.
		$existing_bob = new Donor(
			[
				'email'      => 'bob@example.com',
				'first_name' => 'Robert',
			]
		);
		$existing_bob->save();

		$job_id = $this->migrate();

		// Campaigns: goal in cents for amount goals, raw count for donor goals.
		$water = Campaign::find_by_title( 'Clean Water Fund' );
		$this->assertNotNull( $water );
		$this->assertSame( 500000, $water->goal_amount );
		$this->assertSame( 'amount', $water->goal_type );
		$this->assertSame( 'active', $water->status );
		$this->assertSame( (string) $ids['campaign_water'], (string) $water->get_meta( '_givewp_campaign_id' ) );

		$drive = Campaign::find_by_title( 'Donor Drive' );
		$this->assertNotNull( $drive );
		$this->assertSame( 100, $drive->goal_amount );
		$this->assertSame( 'donors', $drive->goal_type );

		// Donors: Alice and Carol created; Bob matched, not duplicated.
		$this->assertSame( 3, Donor::count() );

		$alice = Donor::find_by_email( 'alice@example.com' );
		$this->assertSame( 'Alice', $alice->first_name );
		$this->assertSame( 'Smith', $alice->last_name );
		$this->assertSame( '1 Main St', $alice->address_1 );
		$this->assertSame( 'Acme Inc', $alice->get_meta( 'company' ) );

		$bob = Donor::find_by_email( 'bob@example.com' );
		$this->assertSame( $existing_bob->id, $bob->id );
		$this->assertSame( 'Robert', $bob->first_name, 'Matched donors must not be overwritten.' );
		$this->assertSame( (string) $ids['bob'], (string) $bob->get_meta( '_givewp_donor_id' ) );
		$this->assertSame( '', (string) $bob->get_meta( '_migration_created' ), 'Matched donors must not carry the created marker.' );

		// Transactions: 4 migrated (test-mode and trashed excluded).
		$this->assertSame( 4, Transaction::count() );

		$p1_map = Transaction::find_ids_by_meta( '_givewp_payment_id', [ $ids['p1'] ] );
		$p1     = Transaction::find( $p1_map[ (string) $ids['p1'] ] );
		$this->assertSame( 'completed', $p1->status );
		$this->assertSame( 2500, $p1->amount );
		$this->assertSame( 'usd', $p1->currency );
		$this->assertSame( 'stripe', $p1->payment_gateway );
		$this->assertSame( 'ch_111', $p1->gateway_transaction_id );
		$this->assertSame( $water->id, $p1->campaign_id );
		$this->assertSame( $alice->id, $p1->donor_id );
		$this->assertSame( 'Keep it up!', $p1->get_meta( 'donor_comment' ) );
		$this->assertSame( 'stripe_checkout', $p1->get_meta( '_givewp_gateway' ) );

		$p2_map = Transaction::find_ids_by_meta( '_givewp_payment_id', [ $ids['p2'] ] );
		$p2     = Transaction::find( $p2_map[ (string) $ids['p2'] ] );
		$this->assertSame( 'pending', $p2->status );
		$this->assertSame( $drive->id, $p2->campaign_id, 'Campaign should resolve via the form junction.' );

		// Subscriptions: statuses, frequencies, and lossy cadence metadata.
		$this->assertSame( 2, Subscription::count() );

		$s1_map = Subscription::find_ids_by_meta( '_givewp_subscription_id', [ $ids['s1'] ] );
		$s1     = Subscription::find( $s1_map[ (string) $ids['s1'] ] );
		$this->assertSame( 'active', $s1->status );
		$this->assertSame( 'monthly', $s1->frequency );
		$this->assertSame( 2000, $s1->amount );
		$this->assertSame( 'sub_123', $s1->gateway_subscription_id );
		$this->assertSame( $water->id, $s1->campaign_id );

		$s2_map = Subscription::find_ids_by_meta( '_givewp_subscription_id', [ $ids['s2'] ] );
		$s2     = Subscription::find( $s2_map[ (string) $ids['s2'] ] );
		$this->assertSame( 'cancelled', $s2->status );
		$this->assertSame( 'monthly', $s2->frequency, 'Daily cadence has no Mission equivalent.' );
		$this->assertSame( 'completed', $s2->get_meta( '_givewp_original_status' ) );
		$this->assertSame( 'day:1', $s2->get_meta( '_givewp_billing_period' ) );

		// Renewal linkage: P5 is a child of P4 within subscription S1.
		$p4_map = Transaction::find_ids_by_meta( '_givewp_payment_id', [ $ids['p4'] ] );
		$p5_map = Transaction::find_ids_by_meta( '_givewp_payment_id', [ $ids['p5'] ] );
		$p4     = Transaction::find( $p4_map[ (string) $ids['p4'] ] );
		$p5     = Transaction::find( $p5_map[ (string) $ids['p5'] ] );

		$this->assertSame( 'recurring', $p4->type );
		$this->assertSame( $s1->id, $p4->subscription_id );
		$this->assertSame( $s1->id, $p5->subscription_id );
		$this->assertSame( $p4->id, $p5->parent_id );

		// Finalize: initial transaction link and renewal totals.
		$s1 = $s1->fresh();
		$this->assertSame( $p4->id, $s1->initial_transaction_id );
		$this->assertSame( 1, $s1->renewal_count );
		$this->assertSame( 2000, $s1->total_renewed );

		// Finalize: donor and campaign aggregates recomputed from real rows.
		$alice = $alice->fresh();
		$this->assertSame( 6500, $alice->total_donated );
		$this->assertSame( 3, $alice->transaction_count );

		$water = $water->fresh();
		$this->assertSame( 6500, $water->total_raised );

		// Receipt totals come from the phase rows.
		$status   = $this->service->get_status( $job_id );
		$by_entity = array_column( $status['phases'], null, 'entity' );
		$this->assertSame( 2, $by_entity['campaigns']['imported'] );
		$this->assertSame( 2, $by_entity['donors']['imported'] );
		$this->assertSame( 1, $by_entity['donors']['skipped'] );
		$this->assertSame( 2, $by_entity['subscriptions']['imported'] );
		$this->assertSame( 4, $by_entity['transactions']['imported'] );
		$this->assertSame( 0, $status['totals']['errors'] );

		// The lock is released and the completion was logged.
		$this->assertFalse( get_option( MigrationService::LOCK_OPTION ) );

		global $wpdb;
		$logged = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}missiondp_activity_log WHERE event = 'data_migrated'"
		);
		$this->assertSame( 1, $logged );
	}

	/**
	 * Including test mode brings the test donation along, flagged is_test.
	 */
	public function test_include_test_mode(): void {
		$ids = $this->seed_givewp_site();

		$this->migrate( true );

		$this->assertSame( 5, Transaction::count() );

		$p3_map = Transaction::find_ids_by_meta( '_givewp_payment_id', [ $ids['p3'] ] );
		$p3     = Transaction::find( $p3_map[ (string) $ids['p3'] ] );
		$this->assertTrue( $p3->is_test );
		$this->assertSame( 9900, $p3->amount );
	}

	/**
	 * Data already brought in through the CSV import tool (matched by gateway
	 * IDs, no source stamps) is reused instead of duplicated, and migrated
	 * records still resolve references through the matched rows.
	 */
	public function test_csv_imported_data_is_not_duplicated(): void {
		$ids = $this->seed_givewp_site();

		// Simulate a prior CSV import: Alice, her completed donation (by
		// Charge ID), and her subscription (by Subscription ID) already exist.
		$csv_donor = new Donor( [ 'email' => 'alice@example.com' ] );
		$csv_donor->save();

		$csv_subscription = new Subscription(
			[
				'status'                  => 'active',
				'donor_id'                => $csv_donor->id,
				'amount'                  => 2000,
				'frequency'               => 'monthly',
				'gateway_subscription_id' => 'sub_123',
			]
		);
		$csv_subscription->save();

		$csv_transaction = new Transaction(
			[
				'status'                 => 'completed',
				'donor_id'               => $csv_donor->id,
				'amount'                 => 2500,
				'total_amount'           => 2500,
				'gateway_transaction_id' => 'ch_111',
			]
		);
		$csv_transaction->save();

		$job_id = $this->migrate();

		// P1 (ch_111) and S1 (sub_123) were matched, not duplicated.
		$this->assertSame( 4, Transaction::count(), 'P1 must reuse the CSV-imported transaction.' );
		$this->assertSame( 2, Subscription::count(), 'S1 must reuse the CSV-imported subscription.' );

		$status    = $this->service->get_status( $job_id );
		$by_entity = array_column( $status['phases'], null, 'entity' );
		$this->assertSame( 3, $by_entity['transactions']['imported'] );
		$this->assertSame( 1, $by_entity['transactions']['skipped'] );
		$this->assertSame( 1, $by_entity['subscriptions']['imported'] );
		$this->assertSame( 1, $by_entity['subscriptions']['skipped'] );

		// Matched rows were stamped (so references resolve) but never marked
		// created (so rollback cannot delete them).
		$this->assertSame( (string) $ids['p1'], (string) $csv_transaction->get_meta( '_givewp_payment_id' ) );
		$this->assertSame( '', (string) $csv_transaction->get_meta( '_migration_created' ) );
		$this->assertSame( (string) $ids['s1'], (string) $csv_subscription->get_meta( '_givewp_subscription_id' ) );
		$this->assertSame( '', (string) $csv_subscription->get_meta( '_migration_created' ) );

		// Migrated renewals link to the matched (CSV-imported) subscription.
		$p4_map = Transaction::find_ids_by_meta( '_givewp_payment_id', [ $ids['p4'] ] );
		$p5_map = Transaction::find_ids_by_meta( '_givewp_payment_id', [ $ids['p5'] ] );
		$p4     = Transaction::find( $p4_map[ (string) $ids['p4'] ] );
		$p5     = Transaction::find( $p5_map[ (string) $ids['p5'] ] );
		$this->assertSame( $csv_subscription->id, $p4->subscription_id );
		$this->assertSame( $csv_subscription->id, $p5->subscription_id );

		// Rollback removes only what this run created; CSV rows survive.
		$rollback = $this->service->start_rollback( $job_id, $this->user_id );
		$this->run_until_terminal( $rollback['job_id'] );

		$this->assertNotNull( Transaction::find( $csv_transaction->id ) );
		$this->assertNotNull( Subscription::find( $csv_subscription->id ) );
		$this->assertSame( 1, Transaction::count() );
		$this->assertSame( 1, Subscription::count() );
	}

	/**
	 * Re-running the migration skips everything and creates no duplicates.
	 */
	public function test_rerun_is_idempotent(): void {
		$this->seed_givewp_site();

		$this->migrate();

		$campaigns     = Campaign::count();
		$donors        = Donor::count();
		$transactions  = Transaction::count();
		$subscriptions = Subscription::count();

		$second = $this->migrate();

		$this->assertSame( $campaigns, Campaign::count() );
		$this->assertSame( $donors, Donor::count() );
		$this->assertSame( $transactions, Transaction::count() );
		$this->assertSame( $subscriptions, Subscription::count() );

		$status    = $this->service->get_status( $second );
		$by_entity = array_column( $status['phases'], null, 'entity' );
		$this->assertSame( 0, $by_entity['transactions']['imported'] );
		$this->assertSame( 4, $by_entity['transactions']['skipped'] );
	}

	/**
	 * Rollback deletes exactly what the migration created, preserves matched
	 * pre-existing records, and clears the original job.
	 */
	public function test_rollback(): void {
		$this->seed_givewp_site();

		$existing_bob = new Donor(
			[
				'email'      => 'bob@example.com',
				'first_name' => 'Robert',
			]
		);
		$existing_bob->save();

		$job_id = $this->migrate();

		$rollback = $this->service->start_rollback( $job_id, $this->user_id );
		$this->assertIsArray( $rollback );

		$this->run_until_terminal( $rollback['job_id'] );

		// Everything the migration created is gone.
		$this->assertSame( 0, Campaign::count() );
		$this->assertSame( 0, Transaction::count() );
		$this->assertSame( 0, Subscription::count() );

		// The pre-existing donor survives, with totals back at zero.
		$this->assertSame( 1, Donor::count() );
		$bob = Donor::find_by_email( 'bob@example.com' );
		$this->assertSame( $existing_bob->id, $bob->id );
		$this->assertSame( 0, $bob->total_donated );

		// The original migration's phase rows are gone, so the UI returns to
		// the source list; the rollback job itself reads completed.
		$this->assertSame( [], MigrationPhase::find_for_job( $job_id ) );

		$status = $this->service->get_status( $rollback['job_id'] );
		$this->assertSame( 'completed', $status['status'] );
		$this->assertSame( 'rollback', $status['job_type'] );

		$this->assertFalse( get_option( MigrationService::LOCK_OPTION ) );
	}

	/**
	 * A second start while a job is active is rejected with a conflict.
	 */
	public function test_concurrent_start_rejected(): void {
		$this->seed_givewp_site();

		$first = $this->service->start( 'givewp', [], $this->user_id );
		$this->assertIsArray( $first );

		$second = $this->service->start( 'givewp', [], $this->user_id );
		$this->assertWPError( $second );
		$this->assertSame( 'migration_in_progress', $second->get_error_code() );

		// Cancel releases the lock so a new run can start.
		$cancelled = $this->service->cancel( $first['job_id'] );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertFalse( get_option( MigrationService::LOCK_OPTION ) );
	}
}
