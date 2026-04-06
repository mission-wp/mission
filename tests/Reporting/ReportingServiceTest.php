<?php
/**
 * Tests for the ReportingService class.
 *
 * @package Mission
 */

namespace Mission\Tests\Reporting;

use Mission\Database\DatabaseModule;
use Mission\Models\Campaign;
use Mission\Models\Donor;
use Mission\Models\Subscription;
use Mission\Models\Transaction;
use Mission\Reporting\ReportingService;
use Mission\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * ReportingService test class.
 */
class ReportingServiceTest extends WP_UnitTestCase {

	/**
	 * Default donor for tests.
	 *
	 * @var Donor
	 */
	private Donor $donor;

	/**
	 * Default campaign for tests.
	 *
	 * @var Campaign
	 */
	private Campaign $campaign;

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_activity_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transaction_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_notes" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaigns" );

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		// Create a default donor.
		$this->donor = new Donor( [
			'email'      => 'jane@example.com',
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
		] );
		$this->donor->save();

		// Create a default campaign.
		$this->campaign = new Campaign( [
			'title'       => 'General Fund',
			'goal_amount' => 100000,
		] );
		$this->campaign->save();

		// Configure settings — live mode.
		update_option( SettingsService::OPTION_NAME, [
			'test_mode' => false,
		] );

		// Set currency.
		update_option( 'mission_currency', 'usd' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_activity_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transaction_history" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_notes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );

		delete_option( SettingsService::OPTION_NAME );
		delete_option( 'mission_currency' );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a donor with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Donor
	 */
	private function create_donor( array $overrides = [] ): Donor {
		static $counter = 0;
		++$counter;

		$defaults = [
			'email'      => "donor{$counter}@example.com",
			'first_name' => "Donor{$counter}",
			'last_name'  => 'Test',
		];

		$donor = new Donor( array_merge( $defaults, $overrides ) );
		$donor->save();

		return $donor;
	}

	/**
	 * Create a campaign with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Campaign
	 */
	private function create_campaign( array $overrides = [] ): Campaign {
		static $counter = 0;
		++$counter;

		$defaults = [
			'title'       => "Campaign {$counter}",
			'goal_amount' => 100000,
		];

		$campaign = new Campaign( array_merge( $defaults, $overrides ) );
		$campaign->save();

		return $campaign;
	}

	/**
	 * Create a completed transaction with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Transaction
	 */
	private function create_transaction( array $overrides = [] ): Transaction {
		$defaults = [
			'status'                 => 'completed',
			'type'                   => 'one_time',
			'donor_id'               => $this->donor->id,
			'campaign_id'            => $this->campaign->id,
			'amount'                 => 5000,
			'tip_amount'             => 0,
			'fee_amount'             => 0,
			'total_amount'           => 5000,
			'currency'               => 'usd',
			'payment_gateway'        => 'stripe',
			'gateway_transaction_id' => 'pi_test_' . wp_rand(),
			'is_test'                => false,
			'date_completed'         => current_time( 'mysql', true ),
		];

		$transaction = new Transaction( array_merge( $defaults, $overrides ) );
		$transaction->save();

		return $transaction;
	}

	/**
	 * Create a subscription via the model.
	 *
	 * @param array $overrides Column values to override.
	 */
	private function create_subscription( array $overrides = [] ): void {
		$subscription = new Subscription( array_merge( [
			'status'                  => 'active',
			'donor_id'                => $this->donor->id,
			'campaign_id'             => $this->campaign->id,
			'amount'                  => 5000,
			'fee_amount'              => 0,
			'tip_amount'              => 0,
			'total_amount'            => 5000,
			'currency'                => 'usd',
			'frequency'               => 'monthly',
			'payment_gateway'         => 'stripe',
			'gateway_subscription_id' => 'sub_test_' . wp_rand(),
			'is_test'                 => 0,
		], $overrides ) );
		$subscription->save();
	}

	/**
	 * Build a fresh ReportingService, optionally overriding settings.
	 *
	 * @param array|null $settings_override Settings to apply before creating service.
	 * @return ReportingService
	 */
	private function make_service( ?array $settings_override = null ): ReportingService {
		if ( null !== $settings_override ) {
			update_option( SettingsService::OPTION_NAME, $settings_override );
		}

		return new ReportingService( new SettingsService() );
	}

	// =========================================================================
	// 1. transaction_summary(): correct totals
	// =========================================================================

	/**
	 * Test transaction_summary returns correct totals.
	 */
	public function test_transaction_summary_correct_totals(): void {
		$this->create_transaction( [ 'amount' => 3000, 'total_amount' => 3000 ] );
		$this->create_transaction( [ 'amount' => 7000, 'total_amount' => 7000 ] );
		$this->create_transaction( [ 'amount' => 2000, 'total_amount' => 2000, 'status' => 'refunded' ] );

		$service = $this->make_service();
		$result  = $service->transaction_summary( 'usd' );

		$this->assertSame( 10000, $result['total_revenue'] );
		$this->assertSame( 2, $result['total_donations'] );
		$this->assertSame( 5000, $result['average_donation'] );
		$this->assertSame( 1, $result['total_refunded'] );
		$this->assertSame( 2000, $result['total_refunded_amount'] );
	}

	// =========================================================================
	// 2. transaction_summary(): excludes test in live mode
	// =========================================================================

	/**
	 * Test transaction_summary excludes test transactions in live mode.
	 */
	public function test_transaction_summary_excludes_test_in_live_mode(): void {
		$this->create_transaction( [ 'amount' => 5000, 'total_amount' => 5000, 'is_test' => false ] );
		$this->create_transaction( [ 'amount' => 3000, 'total_amount' => 3000, 'is_test' => true ] );

		$service = $this->make_service( [ 'test_mode' => false ] );
		$result  = $service->transaction_summary( 'usd' );

		$this->assertSame( 5000, $result['total_revenue'] );
		$this->assertSame( 1, $result['total_donations'] );
	}

	// =========================================================================
	// 3. transaction_summary(): excludes live in test mode
	// =========================================================================

	/**
	 * Test transaction_summary excludes live transactions in test mode.
	 */
	public function test_transaction_summary_excludes_live_in_test_mode(): void {
		$this->create_transaction( [ 'amount' => 5000, 'total_amount' => 5000, 'is_test' => false ] );
		$this->create_transaction( [ 'amount' => 3000, 'total_amount' => 3000, 'is_test' => true ] );

		$service = $this->make_service( [ 'test_mode' => true ] );
		$result  = $service->transaction_summary( 'usd' );

		$this->assertSame( 3000, $result['total_revenue'] );
		$this->assertSame( 1, $result['total_donations'] );
	}

	// =========================================================================
	// 4. donor_summary(): correct unique donor count
	// =========================================================================

	/**
	 * Test donor_summary returns correct counts.
	 */
	public function test_donor_summary_correct_counts(): void {
		$donor_a = $this->create_donor( [
			'first_name'        => 'Alice',
			'last_name'         => 'Smith',
			'transaction_count' => 3,
			'total_donated'     => 15000,
		] );

		$donor_b = $this->create_donor( [
			'first_name'        => 'Bob',
			'last_name'         => 'Jones',
			'transaction_count' => 1,
			'total_donated'     => 5000,
		] );

		// Donor C has zero transactions — should not count.
		$this->create_donor( [
			'first_name'        => 'Charlie',
			'last_name'         => 'Brown',
			'transaction_count' => 0,
			'total_donated'     => 0,
		] );

		$service = $this->make_service();
		$result  = $service->donor_summary();

		$this->assertSame( 2, $result['total_donors'] );
		$this->assertSame( 'Alice Smith', $result['top_donor_name'] );
		$this->assertSame( 15000, $result['top_donor_total'] );
		$this->assertSame( 1, $result['repeat_donors'] ); // Only Alice has >1 transaction.
		$this->assertSame( 10000, $result['average_donated'] ); // (15000 + 5000) / 2.
	}

	// =========================================================================
	// 5. campaign_summary(): correct per-campaign breakdown
	// =========================================================================

	/**
	 * Test campaign_summary returns correct breakdown.
	 */
	public function test_campaign_summary_correct_breakdown(): void {
		$today = wp_date( 'Y-m-d' );

		// Active campaign — no dates (always active).
		$active = $this->create_campaign( [
			'title'        => 'Active Campaign',
			'total_raised' => 5000,
		] );

		// Ended campaign.
		$ended = $this->create_campaign( [
			'title'        => 'Ended Campaign',
			'total_raised' => 10000,
			'status'       => 'ended',
		] );

		// Scheduled campaign.
		$scheduled = $this->create_campaign( [
			'title'        => 'Scheduled Campaign',
			'total_raised' => 3000,
			'status'       => 'scheduled',
		] );

		$service = $this->make_service();
		$result  = $service->campaign_summary();

		// +1 because setUp creates a default campaign.
		$this->assertSame( 4, $result['total_campaigns'] );
		// Active = default campaign (no dates) + active campaign (no dates) = 2.
		$this->assertSame( 2, $result['active'] );
		$this->assertSame( 1, $result['ended'] );
		$this->assertSame( 1, $result['scheduled'] );
		$this->assertSame( 18000, $result['total_raised'] ); // 0 + 5000 + 10000 + 3000.
		$this->assertSame( 'Ended Campaign', $result['top_campaign_name'] );
		$this->assertSame( 10000, $result['top_campaign_raised'] );
	}

	// =========================================================================
	// 6. period_stats(): current vs previous period comparison
	// =========================================================================

	/**
	 * Test period_stats returns correct aggregates for a date range.
	 */
	public function test_period_stats_returns_correct_aggregates(): void {
		$start = '2025-03-01 00:00:00';
		$end   = '2025-03-31 23:59:59';

		// Two transactions within range.
		$this->create_transaction( [
			'amount'         => 4000,
			'total_amount'   => 4000,
			'date_completed' => '2025-03-10 12:00:00',
		] );

		$donor_b = $this->create_donor();
		$this->create_transaction( [
			'donor_id'       => $donor_b->id,
			'amount'         => 6000,
			'total_amount'   => 6000,
			'date_completed' => '2025-03-20 14:00:00',
		] );

		// One outside range — should be excluded.
		$this->create_transaction( [
			'amount'         => 9000,
			'total_amount'   => 9000,
			'date_completed' => '2025-04-05 10:00:00',
		] );

		$service = $this->make_service();
		$result  = $service->period_stats( $start, $end );

		$this->assertEquals( 10000, (int) $result['total_amount'] );
		$this->assertEquals( 2, (int) $result['donor_count'] );
		$this->assertEquals( 5000, (int) (float) $result['avg_amount'] );
	}

	// =========================================================================
	// 7. period_stats(): month-over-month delta calculation
	// =========================================================================

	/**
	 * Test period_stats returns different totals for different periods.
	 */
	public function test_period_stats_month_over_month(): void {
		// March transactions.
		$this->create_transaction( [
			'amount'         => 8000,
			'total_amount'   => 8000,
			'date_completed' => '2025-03-15 12:00:00',
		] );

		// February transactions.
		$this->create_transaction( [
			'amount'         => 3000,
			'total_amount'   => 3000,
			'date_completed' => '2025-02-15 12:00:00',
		] );

		$service = $this->make_service();

		$march = $service->period_stats( '2025-03-01 00:00:00', '2025-03-31 23:59:59' );
		$feb   = $service->period_stats( '2025-02-01 00:00:00', '2025-02-28 23:59:59' );

		$this->assertEquals( 8000, (int) $march['total_amount'] );
		$this->assertEquals( 3000, (int) $feb['total_amount'] );
	}

	// =========================================================================
	// 8. top_campaigns(): returns campaigns ordered by total raised
	// =========================================================================

	/**
	 * Test top_campaigns returns campaigns ordered by total raised descending.
	 */
	public function test_top_campaigns_ordered_by_total_raised(): void {
		$this->create_campaign( [ 'title' => 'Low',  'total_raised' => 5000 ] );
		$this->create_campaign( [ 'title' => 'High', 'total_raised' => 15000 ] );
		$this->create_campaign( [ 'title' => 'Mid',  'total_raised' => 10000 ] );

		$service = $this->make_service();
		$result  = $service->top_campaigns();

		$this->assertSame( 'High', $result[0]['title'] );
		$this->assertSame( 15000, $result[0]['total_raised'] );
		$this->assertSame( 'Mid', $result[1]['title'] );
		$this->assertSame( 10000, $result[1]['total_raised'] );
		$this->assertSame( 'Low', $result[2]['title'] );
		$this->assertSame( 5000, $result[2]['total_raised'] );
	}

	// =========================================================================
	// 9. top_campaigns(): respects limit parameter
	// =========================================================================

	/**
	 * Test top_campaigns respects the limit parameter.
	 */
	public function test_top_campaigns_respects_limit(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->create_campaign( [ 'title' => "Campaign {$i}", 'total_raised' => $i * 1000 ] );
		}

		$service = $this->make_service();
		$result  = $service->top_campaigns( 2 );

		$this->assertCount( 2, $result );
	}

	// =========================================================================
	// 10. donor_detail_stats(): correct per-donor aggregate
	// =========================================================================

	/**
	 * Test donor_detail_stats returns correct is_recurring and is_top_donor flags.
	 */
	public function test_donor_detail_stats_correct_aggregate(): void {
		// Create the top donor with a subscription.
		$top_donor = $this->create_donor( [
			'first_name'        => 'Top',
			'last_name'         => 'Donor',
			'transaction_count' => 5,
			'total_donated'     => 50000,
		] );

		$this->create_subscription( [
			'donor_id' => $top_donor->id,
			'status'   => 'active',
			'is_test'  => 0,
		] );

		// Create several other donors with lower totals so top_donor is in top 10%.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->create_donor( [
				'transaction_count' => 1,
				'total_donated'     => 1000,
			] );
		}

		$service = $this->make_service();
		$result  = $service->donor_detail_stats( $top_donor );

		$this->assertTrue( $result['is_recurring'] );
		$this->assertTrue( $result['is_top_donor'] );

		// A low donor should not be top.
		$low_donor = $this->create_donor( [
			'transaction_count' => 1,
			'total_donated'     => 100,
		] );

		$low_result = $service->donor_detail_stats( $low_donor );
		$this->assertFalse( $low_result['is_recurring'] );
		$this->assertFalse( $low_result['is_top_donor'] );
	}

	// =========================================================================
	// 11. chart_data(): fills gaps for days with zero donations
	// =========================================================================

	/**
	 * Test chart_data fills gaps for days with zero donations.
	 */
	public function test_chart_data_fills_gaps(): void {
		$start = '2025-03-01 00:00:00';
		$end   = '2025-03-05 23:59:59';

		// Day 1 and day 3 have transactions; days 2, 4, 5 should be 0.
		$this->create_transaction( [
			'amount'         => 2000,
			'total_amount'   => 2000,
			'date_completed' => '2025-03-01 10:00:00',
		] );

		$this->create_transaction( [
			'amount'         => 3000,
			'total_amount'   => 3000,
			'date_completed' => '2025-03-03 14:00:00',
		] );

		$service = $this->make_service();
		$result  = $service->chart_data( $start, $end, 'week' );

		$this->assertCount( 5, $result );

		$this->assertSame( '2025-03-01', $result[0]['date'] );
		$this->assertSame( 2000, $result[0]['amount'] );

		$this->assertSame( '2025-03-02', $result[1]['date'] );
		$this->assertSame( 0, $result[1]['amount'] );

		$this->assertSame( '2025-03-03', $result[2]['date'] );
		$this->assertSame( 3000, $result[2]['amount'] );

		$this->assertSame( '2025-03-04', $result[3]['date'] );
		$this->assertSame( 0, $result[3]['amount'] );

		$this->assertSame( '2025-03-05', $result[4]['date'] );
		$this->assertSame( 0, $result[4]['amount'] );
	}

	// =========================================================================
	// 12. chart_data(): correct grouping by day and hourly
	// =========================================================================

	/**
	 * Test chart_data groups correctly by day and hourly.
	 */
	public function test_chart_data_grouping_by_day_and_hourly(): void {
		// Daily grouping: 2 transactions on the same day should sum.
		$this->create_transaction( [
			'amount'         => 1000,
			'total_amount'   => 1000,
			'date_completed' => '2025-03-10 09:00:00',
		] );
		$this->create_transaction( [
			'amount'         => 2000,
			'total_amount'   => 2000,
			'date_completed' => '2025-03-10 15:00:00',
		] );

		$service    = $this->make_service();
		$day_result = $service->chart_data( '2025-03-10 00:00:00', '2025-03-10 23:59:59', 'week' );

		$this->assertCount( 1, $day_result );
		$this->assertSame( 3000, $day_result[0]['amount'] );

		// Hourly grouping: period='today' splits by hour.
		$hourly_result = $service->chart_data( '2025-03-10 00:00:00', '2025-03-10 23:59:59', 'today' );

		// Hourly results should contain entries from hour 0 up to the current hour.
		$this->assertNotEmpty( $hourly_result );

		// Each entry should have a time-formatted date like "09:00".
		$this->assertMatchesRegularExpression( '/^\d{2}:00$/', $hourly_result[0]['date'] );

		// Check that hour 9 and 15 have the expected amounts (if current hour >= 15).
		$hourly_map = [];
		foreach ( $hourly_result as $entry ) {
			$hourly_map[ $entry['date'] ] = $entry['amount'];
		}

		if ( isset( $hourly_map['09:00'] ) ) {
			$this->assertSame( 1000, $hourly_map['09:00'] );
		}

		if ( isset( $hourly_map['15:00'] ) ) {
			$this->assertSame( 2000, $hourly_map['15:00'] );
		}
	}

	// =========================================================================
	// 13. transactions_with_donors(): JOIN returns donor fields
	// =========================================================================

	/**
	 * Test transactions_with_donors returns joined donor and campaign fields.
	 */
	public function test_transactions_with_donors_returns_joined_fields(): void {
		$this->create_transaction( [
			'amount'       => 5000,
			'total_amount' => 5000,
		] );

		$this->create_transaction( [
			'amount'       => 3000,
			'total_amount' => 3000,
		] );

		$service = $this->make_service();
		$result  = $service->transactions_with_donors();

		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['items'] );

		$item = $result['items'][0];
		$this->assertArrayHasKey( 'donor_name', $item );
		$this->assertArrayHasKey( 'donor_email', $item );
		$this->assertArrayHasKey( 'campaign_title', $item );
		$this->assertArrayHasKey( 'amount', $item );
		$this->assertArrayHasKey( 'currency', $item );
		$this->assertArrayHasKey( 'status', $item );

		$this->assertSame( 'Jane Doe', $item['donor_name'] );
		$this->assertSame( 'jane@example.com', $item['donor_email'] );
		$this->assertSame( 'General Fund', $item['campaign_title'] );

		// Test pagination — per_page=1.
		$paginated = $service->transactions_with_donors( [ 'per_page' => 1 ] );
		$this->assertSame( 2, $paginated['total'] );
		$this->assertCount( 1, $paginated['items'] );
	}

	// =========================================================================
	// 14. All methods: handle empty result sets gracefully
	// =========================================================================

	/**
	 * Test all public methods handle empty results gracefully.
	 */
	public function test_all_methods_handle_empty_results(): void {
		global $wpdb;

		// Truncate everything so there's truly no data.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptions" );

		$service = $this->make_service();

		// transaction_summary.
		$txn_summary = $service->transaction_summary( 'usd' );
		$this->assertSame( 0, $txn_summary['total_revenue'] );
		$this->assertSame( 0, $txn_summary['total_donations'] );
		$this->assertSame( 0, $txn_summary['average_donation'] );
		$this->assertSame( 0, $txn_summary['total_refunded'] );
		$this->assertSame( 0, $txn_summary['total_refunded_amount'] );

		// donor_summary.
		$donor_summary = $service->donor_summary();
		$this->assertSame( 0, $donor_summary['total_donors'] );
		$this->assertSame( '', $donor_summary['top_donor_name'] );
		$this->assertSame( 0, $donor_summary['top_donor_total'] );
		$this->assertSame( 0, $donor_summary['repeat_donors'] );

		// campaign_summary.
		$camp_summary = $service->campaign_summary();
		$this->assertSame( 0, $camp_summary['total_campaigns'] );
		$this->assertSame( 0, $camp_summary['total_raised'] );
		$this->assertSame( '', $camp_summary['top_campaign_name'] );

		// period_stats.
		$period = $service->period_stats( '2025-01-01 00:00:00', '2025-12-31 23:59:59' );
		$this->assertEquals( 0, (int) $period['total_amount'] );
		$this->assertEquals( 0, (int) $period['donor_count'] );
		$this->assertEquals( 0, (int) (float) $period['avg_amount'] );

		// top_campaigns.
		$top = $service->top_campaigns();
		$this->assertSame( [], $top );

		// chart_data.
		$chart = $service->chart_data( '2025-03-01 00:00:00', '2025-03-03 23:59:59', 'week' );
		$this->assertCount( 3, $chart );
		$this->assertSame( 0, $chart[0]['amount'] );

		// transactions_with_donors.
		$txn_donors = $service->transactions_with_donors();
		$this->assertSame( 0, $txn_donors['total'] );
		$this->assertSame( [], $txn_donors['items'] );
	}

	// =========================================================================
	// 15. subscription_summary(): MRR normalizes mixed frequencies
	// =========================================================================

	/**
	 * Test MRR correctly normalizes weekly, monthly, quarterly, and annually.
	 */
	public function test_subscription_summary_mrr_normalizes_mixed_frequencies(): void {
		// weekly  12000 → 12000 * 52 / 12 = 52000
		// monthly 10000 → 10000
		// quarterly 30000 → 30000 / 3 = 10000
		// annually 120000 → 120000 / 12 = 10000
		$this->create_subscription( [ 'frequency' => 'weekly',    'amount' => 12000, 'total_amount' => 12000 ] );
		$this->create_subscription( [ 'frequency' => 'monthly',   'amount' => 10000, 'total_amount' => 10000 ] );
		$this->create_subscription( [ 'frequency' => 'quarterly', 'amount' => 30000, 'total_amount' => 30000 ] );
		$this->create_subscription( [ 'frequency' => 'annually',  'amount' => 120000, 'total_amount' => 120000 ] );

		$service = $this->make_service();
		$result  = $service->subscription_summary();

		$this->assertSame( 82000, $result['mrr'] );
	}

	// =========================================================================
	// 16. subscription_summary(): previous MRR only subs active at month start
	// =========================================================================

	/**
	 * Test previous_mrr includes only subscriptions that existed before month start.
	 */
	public function test_subscription_summary_previous_mrr_only_subs_active_at_month_start(): void {
		$now         = new \DateTimeImmutable( 'now', wp_timezone() );
		$before      = $now->modify( 'first day of this month' )->modify( '-10 days' )->format( 'Y-m-d H:i:s' );
		$this_month  = $now->format( 'Y-m-d H:i:s' );

		// Sub A: active, created before month start → in previous_mrr and mrr.
		$this->create_subscription( [
			'total_amount' => 5000,
			'date_created' => $before,
			'date_modified' => $before,
		] );

		// Sub B: active, created this month → in mrr only.
		$this->create_subscription( [
			'amount'        => 8000,
			'total_amount'  => 8000,
			'date_created'  => $this_month,
			'date_modified' => $this_month,
		] );

		$service = $this->make_service();
		$result  = $service->subscription_summary();

		$this->assertSame( 5000, $result['previous_mrr'] );
		$this->assertSame( 13000, $result['mrr'] );
	}

	// =========================================================================
	// 17. subscription_summary(): previous MRR includes recently churned
	// =========================================================================

	/**
	 * Test previous_mrr includes subs cancelled this month but active at month start.
	 */
	public function test_subscription_summary_previous_mrr_includes_recently_churned(): void {
		$now         = new \DateTimeImmutable( 'now', wp_timezone() );
		$month_start = $now->modify( 'first day of this month' )->format( 'Y-m-d 00:00:00' );
		$before      = $now->modify( 'first day of this month' )->modify( '-10 days' )->format( 'Y-m-d H:i:s' );
		$way_before  = $now->modify( 'first day of this month' )->modify( '-40 days' )->format( 'Y-m-d H:i:s' );
		$this_month  = $now->format( 'Y-m-d H:i:s' );

		// Sub A: cancelled this month, created before month start → in previous_mrr.
		$this->create_subscription( [
			'amount'         => 6000,
			'total_amount'   => 6000,
			'status'         => 'cancelled',
			'date_created'   => $before,
			'date_modified'  => $this_month,
			'date_cancelled' => $this_month,
		] );

		// Sub B: active, created before month start → in previous_mrr.
		$this->create_subscription( [
			'amount'        => 4000,
			'total_amount'  => 4000,
			'date_created'  => $before,
			'date_modified' => $before,
		] );

		// Sub C: cancelled BEFORE month start → NOT in previous_mrr.
		$this->create_subscription( [
			'amount'         => 3000,
			'total_amount'   => 3000,
			'status'         => 'cancelled',
			'date_created'   => $way_before,
			'date_modified'  => $before,
			'date_cancelled' => $before,
		] );

		$service = $this->make_service();
		$result  = $service->subscription_summary();

		$this->assertSame( 10000, $result['previous_mrr'] );
	}

	// =========================================================================
	// 18. subscription_summary(): active excludes non-active statuses
	// =========================================================================

	/**
	 * Test active count excludes cancelled, pending, and paused subscriptions.
	 */
	public function test_subscription_summary_active_excludes_non_active_statuses(): void {
		$this->create_subscription( [ 'status' => 'active' ] );
		$this->create_subscription( [ 'status' => 'active' ] );
		$this->create_subscription( [ 'status' => 'cancelled' ] );
		$this->create_subscription( [ 'status' => 'pending' ] );
		$this->create_subscription( [ 'status' => 'paused' ] );

		$service = $this->make_service();
		$result  = $service->subscription_summary();

		$this->assertSame( 2, $result['active'] );
	}

	// =========================================================================
	// 19. subscription_summary(): new this month
	// =========================================================================

	/**
	 * Test new_this_month counts only active subs created since first of month.
	 */
	public function test_subscription_summary_new_this_month(): void {
		$now        = new \DateTimeImmutable( 'now', wp_timezone() );
		$before     = $now->modify( 'first day of this month' )->modify( '-10 days' )->format( 'Y-m-d H:i:s' );
		$this_month = $now->format( 'Y-m-d H:i:s' );

		// Sub A: active, created now → counts.
		$this->create_subscription( [
			'status'       => 'active',
			'date_created' => $this_month,
			'date_modified' => $this_month,
		] );

		// Sub B: active, created last month → doesn't count.
		$this->create_subscription( [
			'status'        => 'active',
			'date_created'  => $before,
			'date_modified' => $before,
		] );

		// Sub C: cancelled, created now → doesn't count.
		$this->create_subscription( [
			'status'        => 'cancelled',
			'date_created'  => $this_month,
			'date_modified' => $this_month,
		] );

		$service = $this->make_service();
		$result  = $service->subscription_summary();

		$this->assertSame( 1, $result['new_this_month'] );
	}

	// =========================================================================
	// 20. subscription_summary(): churned only this month
	// =========================================================================

	/**
	 * Test churned count only includes subs cancelled this month.
	 */
	public function test_subscription_summary_churned_only_this_month(): void {
		$now        = new \DateTimeImmutable( 'now', wp_timezone() );
		$before     = $now->modify( 'first day of this month' )->modify( '-10 days' )->format( 'Y-m-d H:i:s' );
		$this_month = $now->format( 'Y-m-d H:i:s' );

		// Sub A: cancelled this month → counts.
		$this->create_subscription( [
			'status'         => 'cancelled',
			'date_created'   => $before,
			'date_modified'  => $this_month,
			'date_cancelled' => $this_month,
		] );

		// Sub B: cancelled last month → doesn't count.
		$this->create_subscription( [
			'status'         => 'cancelled',
			'date_created'   => $before,
			'date_modified'  => $before,
			'date_cancelled' => $before,
		] );

		// Sub C: active → doesn't count.
		$this->create_subscription( [ 'status' => 'active' ] );

		$service = $this->make_service();
		$result  = $service->subscription_summary();

		$this->assertSame( 1, $result['churned'] );
	}

	// =========================================================================
	// 21. subscription_summary(): churned MRR normalized
	// =========================================================================

	/**
	 * Test churned_mrr is the normalized monthly amount from this month's cancellations.
	 */
	public function test_subscription_summary_churned_mrr_normalized(): void {
		$now        = new \DateTimeImmutable( 'now', wp_timezone() );
		$before     = $now->modify( 'first day of this month' )->modify( '-10 days' )->format( 'Y-m-d H:i:s' );
		$this_month = $now->format( 'Y-m-d H:i:s' );

		// Sub A: cancelled this month, weekly/12000 → 52000.
		$this->create_subscription( [
			'status'         => 'cancelled',
			'frequency'      => 'weekly',
			'amount'         => 12000,
			'total_amount'   => 12000,
			'date_created'   => $before,
			'date_modified'  => $this_month,
			'date_cancelled' => $this_month,
		] );

		// Sub B: cancelled this month, quarterly/30000 → 10000.
		$this->create_subscription( [
			'status'         => 'cancelled',
			'frequency'      => 'quarterly',
			'amount'         => 30000,
			'total_amount'   => 30000,
			'date_created'   => $before,
			'date_modified'  => $this_month,
			'date_cancelled' => $this_month,
		] );

		$service = $this->make_service();
		$result  = $service->subscription_summary();

		$this->assertSame( 62000, $result['churned_mrr'] );
	}

	// =========================================================================
	// 22. subscription_summary(): average monthly
	// =========================================================================

	/**
	 * Test average_monthly equals MRR divided by active count.
	 */
	public function test_subscription_summary_average_monthly(): void {
		$this->create_subscription( [ 'amount' => 3000, 'total_amount' => 3000 ] );
		$this->create_subscription( [ 'amount' => 7000, 'total_amount' => 7000 ] );
		$this->create_subscription( [ 'amount' => 5000, 'total_amount' => 5000 ] );

		$service = $this->make_service();
		$result  = $service->subscription_summary();

		$this->assertSame( 5000, $result['average_monthly'] );
	}

	// =========================================================================
	// 23. subscription_summary(): average monthly zero when no active
	// =========================================================================

	/**
	 * Test average_monthly returns 0 when there are no active subscriptions.
	 */
	public function test_subscription_summary_average_monthly_zero_when_no_active(): void {
		$this->create_subscription( [ 'status' => 'cancelled' ] );

		$service = $this->make_service();
		$result  = $service->subscription_summary();

		$this->assertSame( 0, $result['average_monthly'] );
		$this->assertSame( 0, $result['active'] );
	}

	// =========================================================================
	// 24. subscription_summary(): all zeros when empty
	// =========================================================================

	/**
	 * Test subscription_summary returns all zeros when no subscriptions exist.
	 */
	public function test_subscription_summary_all_zeros_when_empty(): void {
		$service = $this->make_service();
		$result  = $service->subscription_summary();

		$this->assertSame( 0, $result['mrr'] );
		$this->assertSame( 0, $result['previous_mrr'] );
		$this->assertSame( 0, $result['active'] );
		$this->assertSame( 0, $result['new_this_month'] );
		$this->assertSame( 0, $result['average_monthly'] );
		$this->assertSame( 0, $result['churned'] );
		$this->assertSame( 0, $result['churned_mrr'] );
	}

	// =========================================================================
	// 25. subscription_summary(): excludes test subs in live mode
	// =========================================================================

	/**
	 * Test subscription_summary filters by test mode.
	 */
	public function test_subscription_summary_excludes_test_subs_in_live_mode(): void {
		// Live subscription.
		$this->create_subscription( [ 'amount' => 5000, 'total_amount' => 5000, 'is_test' => 0 ] );

		// Test subscription.
		$this->create_subscription( [ 'amount' => 8000, 'total_amount' => 8000, 'is_test' => 1 ] );

		// Live mode.
		$service = $this->make_service( [ 'test_mode' => false ] );
		$result  = $service->subscription_summary();

		$this->assertSame( 5000, $result['mrr'] );
		$this->assertSame( 1, $result['active'] );

		// Test mode.
		$service = $this->make_service( [ 'test_mode' => true ] );
		$result  = $service->subscription_summary();

		$this->assertSame( 8000, $result['mrr'] );
		$this->assertSame( 1, $result['active'] );
	}

	// =========================================================================
	// donor_annual_summary()
	// =========================================================================

	/**
	 * Test donor_annual_summary groups by year with correct totals.
	 */
	public function test_donor_annual_summary_groups_by_year(): void {
		// 2025 transactions.
		$this->create_transaction( [
			'amount'         => 3000,
			'total_amount'   => 3000,
			'date_completed' => '2025-06-15 12:00:00',
		] );
		$this->create_transaction( [
			'amount'         => 7000,
			'total_amount'   => 7000,
			'date_completed' => '2025-11-01 12:00:00',
		] );

		// 2026 transaction.
		$this->create_transaction( [
			'amount'         => 5000,
			'total_amount'   => 5000,
			'date_completed' => '2026-02-10 12:00:00',
		] );

		$service = $this->make_service();
		$result  = $service->donor_annual_summary( $this->donor->id );

		$this->assertCount( 2, $result );
		// Ordered DESC — 2026 first.
		$this->assertSame( 2026, $result[0]['year'] );
		$this->assertSame( 5000, $result[0]['total'] );
		$this->assertSame( 1, $result[0]['count'] );
		$this->assertSame( 2025, $result[1]['year'] );
		$this->assertSame( 10000, $result[1]['total'] );
		$this->assertSame( 2, $result[1]['count'] );
	}

	/**
	 * Test donor_annual_summary excludes test transactions.
	 */
	public function test_donor_annual_summary_excludes_test_transactions(): void {
		$this->create_transaction( [
			'amount'         => 5000,
			'total_amount'   => 5000,
			'is_test'        => false,
			'date_completed' => '2025-06-15 12:00:00',
		] );
		$this->create_transaction( [
			'amount'         => 8000,
			'total_amount'   => 8000,
			'is_test'        => true,
			'date_completed' => '2025-06-15 12:00:00',
		] );

		$service = $this->make_service();
		$result  = $service->donor_annual_summary( $this->donor->id );

		$this->assertCount( 1, $result );
		$this->assertSame( 5000, $result[0]['total'] );
		$this->assertSame( 1, $result[0]['count'] );
	}

	/**
	 * Test donor_annual_summary returns empty for donor with no transactions.
	 */
	public function test_donor_annual_summary_empty_for_donor_with_no_transactions(): void {
		$service = $this->make_service();
		$result  = $service->donor_annual_summary( $this->donor->id );

		$this->assertSame( [], $result );
	}

	// =========================================================================
	// donor_transaction_years()
	// =========================================================================

	/**
	 * Test donor_transaction_years returns distinct years DESC.
	 */
	public function test_donor_transaction_years_returns_distinct_years_desc(): void {
		$this->create_transaction( [ 'date_created' => '2024-03-01 12:00:00' ] );
		$this->create_transaction( [ 'date_created' => '2024-09-15 12:00:00' ] );
		$this->create_transaction( [ 'date_created' => '2025-06-10 12:00:00' ] );
		$this->create_transaction( [ 'date_created' => '2026-01-05 12:00:00' ] );

		$service = $this->make_service();
		$result  = $service->donor_transaction_years( $this->donor->id );

		$this->assertSame( [ 2026, 2025, 2024 ], $result );
	}

	/**
	 * Test donor_transaction_years excludes test transactions.
	 */
	public function test_donor_transaction_years_excludes_test(): void {
		$this->create_transaction( [ 'date_created' => '2025-06-10 12:00:00', 'is_test' => false ] );
		$this->create_transaction( [ 'date_created' => '2026-01-05 12:00:00', 'is_test' => true ] );

		$service = $this->make_service();
		$result  = $service->donor_transaction_years( $this->donor->id );

		$this->assertSame( [ 2025 ], $result );
	}

	/**
	 * Test donor_transaction_years returns empty for no transactions.
	 */
	public function test_donor_transaction_years_empty(): void {
		$service = $this->make_service();
		$result  = $service->donor_transaction_years( $this->donor->id );

		$this->assertSame( [], $result );
	}

	// =========================================================================
	// donor_transaction_campaigns()
	// =========================================================================

	/**
	 * Test donor_transaction_campaigns returns distinct campaigns.
	 */
	public function test_donor_transaction_campaigns_returns_distinct(): void {
		$campaign_b = $this->create_campaign( [ 'title' => 'Annual Gala' ] );

		$this->create_transaction( [ 'campaign_id' => $this->campaign->id ] );
		$this->create_transaction( [ 'campaign_id' => $this->campaign->id ] );
		$this->create_transaction( [ 'campaign_id' => $campaign_b->id ] );

		$service = $this->make_service();
		$result  = $service->donor_transaction_campaigns( $this->donor->id );

		$this->assertCount( 2, $result );

		// Ordered by name ASC — "Annual Gala" before "General Fund".
		$this->assertSame( $campaign_b->id, $result[0]['id'] );
		$this->assertSame( 'Annual Gala', $result[0]['name'] );
		$this->assertSame( $this->campaign->id, $result[1]['id'] );
		$this->assertSame( 'General Fund', $result[1]['name'] );
	}

	/**
	 * Test donor_transaction_campaigns excludes test transactions.
	 */
	public function test_donor_transaction_campaigns_excludes_test(): void {
		$campaign_b = $this->create_campaign( [ 'title' => 'Test Campaign' ] );

		$this->create_transaction( [ 'campaign_id' => $this->campaign->id, 'is_test' => false ] );
		$this->create_transaction( [ 'campaign_id' => $campaign_b->id, 'is_test' => true ] );

		$service = $this->make_service();
		$result  = $service->donor_transaction_campaigns( $this->donor->id );

		$this->assertCount( 1, $result );
		$this->assertSame( $this->campaign->id, $result[0]['id'] );
	}

	/**
	 * Test donor_transaction_campaigns returns empty for no transactions.
	 */
	public function test_donor_transaction_campaigns_empty(): void {
		$service = $this->make_service();
		$result  = $service->donor_transaction_campaigns( $this->donor->id );

		$this->assertSame( [], $result );
	}
}
