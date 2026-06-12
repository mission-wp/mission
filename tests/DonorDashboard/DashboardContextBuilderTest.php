<?php
/**
 * Tests for the DashboardContextBuilder.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\DonorDashboard;

use MissionDP\Database\DatabaseModule;
use MissionDP\DonorDashboard\DashboardContextBuilder;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use WP_UnitTestCase;

/**
 * Dashboard context builder test class.
 */
class DashboardContextBuilderTest extends WP_UnitTestCase {

	/**
	 * Default panel definitions passed to build().
	 *
	 * @var array<string, array>
	 */
	private const PANELS = [
		'overview'  => [],
		'recurring' => [],
		'history'   => [],
		'receipts'  => [],
		'profile'   => [],
	];

	/**
	 * Default panel labels passed to build().
	 *
	 * @var array<string, string>
	 */
	private const PANEL_LABELS = [
		'overview'  => 'Overview',
		'recurring' => 'Recurring',
		'history'   => 'History',
		'receipts'  => 'Receipts',
		'profile'   => 'Profile',
	];

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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		// phpcs:enable

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a donor with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Column values to override.
	 * @return Donor
	 */
	private function create_donor( array $overrides = [] ): Donor {
		static $counter = 0;
		++$counter;

		$donor = new Donor( array_merge(
			[
				'email'      => "dashboard{$counter}@example.com",
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
			],
			$overrides
		) );

		$donor->save();

		return $donor;
	}

	/**
	 * Create a completed transaction without firing creation hooks.
	 *
	 * @param array<string, mixed> $overrides Column values to override.
	 * @return Transaction
	 */
	private function create_transaction( array $overrides = [] ): Transaction {
		$transaction = new Transaction( array_merge(
			[
				'status'         => Transaction::STATUS_COMPLETED,
				'type'           => 'one_time',
				'amount'         => 5000,
				'total_amount'   => 5000,
				'currency'       => 'usd',
				'date_completed' => '2026-06-01 10:00:00',
			],
			$overrides
		) );

		$transaction->save_silent();

		return $transaction;
	}

	/**
	 * Create a subscription with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Column values to override.
	 * @return Subscription
	 */
	private function create_subscription( array $overrides = [] ): Subscription {
		$subscription = new Subscription( array_merge(
			[
				'status'       => Subscription::STATUS_ACTIVE,
				'amount'       => 2500,
				'total_amount' => 2500,
				'currency'     => 'usd',
				'frequency'    => 'monthly',
			],
			$overrides
		) );

		$subscription->save();

		return $subscription;
	}

	/**
	 * Build the context for a donor with default panels and USD settings.
	 *
	 * @param Donor                $donor    The donor.
	 * @param array<string, mixed> $settings Settings overrides.
	 * @return array{context: array, state: array}
	 */
	private function build( Donor $donor, array $settings = [] ): array {
		$builder = new DashboardContextBuilder(
			$donor,
			array_merge( [ 'currency' => 'USD' ], $settings )
		);

		return $builder->build( self::PANELS, self::PANEL_LABELS );
	}

	// -------------------------------------------------------------------------
	// Structure tests.
	// -------------------------------------------------------------------------

	/**
	 * Test build returns the expected top-level structure.
	 */
	public function test_build_returns_context_and_state(): void {
		$result = $this->build( $this->create_donor() );

		$this->assertArrayHasKey( 'context', $result );
		$this->assertArrayHasKey( 'state', $result );

		foreach ( [ 'donor', 'profile', 'overview', 'recurring', 'history', 'receipts', 'validPanels', 'panelLabels' ] as $key ) {
			$this->assertArrayHasKey( $key, $result['context'] );
		}
	}

	/**
	 * Test panel keys and labels pass through to the context.
	 */
	public function test_valid_panels_and_labels_passthrough(): void {
		$result = $this->build( $this->create_donor() );

		$this->assertSame( array_keys( self::PANELS ), $result['context']['validPanels'] );
		$this->assertSame( self::PANEL_LABELS, $result['context']['panelLabels'] );
	}

	// -------------------------------------------------------------------------
	// Overview tests.
	// -------------------------------------------------------------------------

	/**
	 * Test overview stats derive from the donor's aggregate columns.
	 */
	public function test_overview_stats_from_donor_aggregates(): void {
		$donor = $this->create_donor( [
			'total_donated'     => 20000,
			'transaction_count' => 4,
		] );

		$result = $this->build( $donor );
		$stats  = $result['context']['overview']['stats'];

		$this->assertSame( '4', $stats[0]['value'] );
		$this->assertSame( '$200.00', $stats[1]['value'] );
		$this->assertSame( '$50.00', $stats[2]['value'] );
		$this->assertTrue( $result['context']['overview']['hasTransactions'] );
	}

	/**
	 * Test recent transactions are formatted with their campaign names.
	 */
	public function test_recent_transactions_prepared(): void {
		$campaign = new Campaign( [
			'title'       => 'Clean Water',
			'goal_amount' => 100000,
		] );
		$campaign->save();

		$donor = $this->create_donor();
		$this->create_transaction( [
			'donor_id'    => $donor->id,
			'campaign_id' => $campaign->id,
		] );
		// A transaction whose campaign is gone.
		$this->create_transaction( [
			'donor_id'       => $donor->id,
			'campaign_id'    => 999999,
			'date_completed' => '2026-05-01 10:00:00',
		] );

		$result = $this->build( $donor );
		$recent = $result['context']['overview']['recentTransactions'];

		$this->assertCount( 2, $recent );
		// Newest first.
		$this->assertSame( 'Clean Water', $recent[0]['campaignName'] );
		$this->assertSame( '$50.00', $recent[0]['formattedAmount'] );
		$this->assertSame( date_i18n( 'M j, Y', strtotime( '2026-06-01 10:00:00' ) ), $recent[0]['formattedDate'] );
		$this->assertSame( 'Deleted Campaign', $recent[1]['campaignName'] );
	}

	// -------------------------------------------------------------------------
	// Recurring tests.
	// -------------------------------------------------------------------------

	/**
	 * Test the recurring panel separates active and cancelled subscriptions.
	 */
	public function test_recurring_panel_active_and_cancelled(): void {
		$donor = $this->create_donor();

		$this->create_subscription( [
			'donor_id'          => $donor->id,
			'date_next_renewal' => '2026-07-01 00:00:00',
		] );
		$this->create_subscription( [
			'donor_id'       => $donor->id,
			'status'         => Subscription::STATUS_CANCELLED,
			'date_created'   => '2025-01-15 00:00:00',
			'date_cancelled' => '2025-11-20 00:00:00',
			'renewal_count'  => 10,
			'total_renewed'  => 25000,
		] );

		$result    = $this->build( $donor );
		$recurring = $result['context']['recurring'];

		$this->assertTrue( $recurring['hasActive'] );
		$this->assertTrue( $recurring['hasCancelled'] );
		$this->assertTrue( $recurring['hasAny'] );
		$this->assertCount( 1, $recurring['activeSubscriptions'] );
		$this->assertCount( 1, $recurring['cancelledSubscriptions'] );

		$active = $recurring['activeSubscriptions'][0];
		$this->assertSame( '$25.00', $active['formattedAmount'] );
		$this->assertSame( '/ month', $active['frequencySuffix'] );
		$this->assertSame( date_i18n( 'M j, Y', strtotime( '2026-07-01 00:00:00' ) ), $active['nextPayment'] );

		$cancelled = $recurring['cancelledSubscriptions'][0];
		// 1 initial payment + 10 renewals; $25.00 + $250.00 contributed.
		$this->assertSame( '11', $cancelled['paymentsMade'] );
		$this->assertSame( '$275.00', $cancelled['totalContributed'] );
		$this->assertSame(
			date_i18n( 'M Y', strtotime( '2025-01-15' ) ) . ' – ' . date_i18n( 'M Y', strtotime( '2025-11-20' ) ),
			$cancelled['period']
		);
	}

	/**
	 * Test paused subscriptions count as active for the dashboard.
	 */
	public function test_paused_subscription_listed_as_active(): void {
		$donor = $this->create_donor();
		$this->create_subscription( [
			'donor_id' => $donor->id,
			'status'   => Subscription::STATUS_PAUSED,
		] );

		$result = $this->build( $donor );

		$this->assertTrue( $result['context']['recurring']['hasActive'] );
		$this->assertSame( Subscription::STATUS_PAUSED, $result['context']['recurring']['activeSubscriptions'][0]['status'] );
	}

	// -------------------------------------------------------------------------
	// History tests.
	// -------------------------------------------------------------------------

	/**
	 * Test history pagination math and per-page slicing.
	 */
	public function test_history_pagination(): void {
		$donor = $this->create_donor();

		for ( $i = 1; $i <= 25; $i++ ) {
			$this->create_transaction( [
				'donor_id'     => $donor->id,
				'date_created' => sprintf( '2026-05-%02d 10:00:00', min( $i, 28 ) ),
			] );
		}

		$result  = $this->build( $donor );
		$history = $result['context']['history'];

		$this->assertSame( 25, $history['total'] );
		$this->assertSame( 2, $history['totalPages'] );
		$this->assertCount( 20, $history['transactions'] );
		$this->assertFalse( $result['state']['historyHasOnePage'] );
		$this->assertFalse( $result['state']['historyIsLastPage'] );
		$this->assertSame( 'Page 1 of 2', $result['state']['historyPaginationLabel'] );
	}

	/**
	 * Test history rows label one-time versus recurring transactions.
	 */
	public function test_history_type_labels(): void {
		$donor = $this->create_donor();
		$this->create_transaction( [ 'donor_id' => $donor->id ] );
		$this->create_transaction( [
			'donor_id'     => $donor->id,
			'type'         => 'monthly',
			'date_created' => '2026-06-02 10:00:00',
		] );

		$result = $this->build( $donor );
		$rows   = $result['context']['history']['transactions'];

		$labels = array_column( $rows, 'typeLabel' );
		$this->assertContains( 'One-time', $labels );
		$this->assertContains( 'Recurring', $labels );
	}

	// -------------------------------------------------------------------------
	// Receipts tests.
	// -------------------------------------------------------------------------

	/**
	 * Test receipt years summarize completed transactions per calendar year.
	 */
	public function test_receipts_years(): void {
		$donor        = $this->create_donor();
		$current_year = (int) gmdate( 'Y' );

		$this->create_transaction( [
			'donor_id'       => $donor->id,
			'date_completed' => '2024-03-01 10:00:00',
		] );
		$this->create_transaction( [
			'donor_id'       => $donor->id,
			'amount'         => 2500,
			'date_completed' => '2024-09-01 10:00:00',
		] );
		$this->create_transaction( [
			'donor_id'       => $donor->id,
			'date_completed' => $current_year . '-01-15 10:00:00',
		] );
		// Pending transactions don't appear on receipts.
		$this->create_transaction( [
			'donor_id'       => $donor->id,
			'status'         => Transaction::STATUS_PENDING,
			'date_completed' => null,
		] );

		$result   = $this->build( $donor );
		$receipts = $result['context']['receipts'];

		$this->assertTrue( $receipts['hasAny'] );
		$this->assertCount( 2, $receipts['years'] );

		$by_year = array_column( $receipts['years'], null, 'year' );
		$this->assertSame( '$75.00', $by_year[2024]['formattedTotal'] );
		$this->assertSame( 2, $by_year[2024]['count'] );
		$this->assertFalse( $by_year[2024]['isCurrentYear'] );
		$this->assertTrue( $by_year[ $current_year ]['isCurrentYear'] );
	}

	// -------------------------------------------------------------------------
	// Test-mode and empty-state tests.
	// -------------------------------------------------------------------------

	/**
	 * Test test mode uses test aggregates and filters out live transactions.
	 */
	public function test_test_mode_filters_data(): void {
		$donor = $this->create_donor( [
			'total_donated'          => 20000,
			'transaction_count'      => 4,
			'test_total_donated'     => 1000,
			'test_transaction_count' => 1,
		] );

		// One live, one test transaction.
		$this->create_transaction( [ 'donor_id' => $donor->id ] );
		$this->create_transaction( [
			'donor_id' => $donor->id,
			'is_test'  => true,
			'amount'   => 1000,
		] );

		$result = $this->build( $donor, [ 'test_mode' => true ] );

		$this->assertSame( '1', $result['context']['overview']['stats'][0]['value'] );
		$this->assertSame( '$10.00', $result['context']['overview']['stats'][1]['value'] );
		$this->assertSame( 1, $result['context']['history']['total'] );
		$this->assertSame( '$10.00', $result['context']['history']['transactions'][0]['formattedAmount'] );
	}

	/**
	 * Test a donor with no activity gets an empty-state-safe context.
	 */
	public function test_empty_donor_context(): void {
		$donor  = $this->create_donor( [
			'first_name' => '',
			'last_name'  => '',
		] );
		$result = $this->build( $donor );

		$context = $result['context'];
		$this->assertFalse( $context['overview']['hasTransactions'] );
		$this->assertFalse( $context['overview']['hasSubscriptions'] );
		$this->assertSame( [], $context['overview']['recentTransactions'] );
		$this->assertFalse( $context['recurring']['hasAny'] );
		$this->assertSame( [], $context['history']['transactions'] );
		$this->assertFalse( $context['receipts']['hasAny'] );
		$this->assertSame( '?', $context['donor']['initials'] );
		$this->assertSame( '$0.00', $context['overview']['stats'][2]['value'] );
		$this->assertTrue( $result['state']['historyIsEmpty'] );
	}

	/**
	 * Test donor preferences load from meta with defaults.
	 */
	public function test_profile_preferences_from_meta(): void {
		$donor = $this->create_donor();
		$donor->update_meta( 'email_receipts', '0' );

		$result      = $this->build( $donor );
		$preferences = $result['context']['profile']['preferences'];

		$this->assertFalse( $preferences['emailReceipts'] );
		// Unset preferences fall back to their defaults.
		$this->assertTrue( $preferences['emailCampaignUpdates'] );
		$this->assertTrue( $preferences['emailAnnualReminder'] );
	}

	/**
	 * Test the overview stats filter can extend the stat cards.
	 */
	public function test_overview_stats_filter(): void {
		$callback = static function ( array $stats ) {
			$stats[] = [
				'value' => '42',
				'label' => 'Extra Stat',
			];
			return $stats;
		};
		add_filter( 'mission_donor_dashboard_overview_stats', $callback );

		try {
			$result = $this->build( $this->create_donor() );
		} finally {
			remove_filter( 'mission_donor_dashboard_overview_stats', $callback );
		}

		$stats = $result['context']['overview']['stats'];
		$this->assertCount( 4, $stats );
		$this->assertSame( 'Extra Stat', $stats[3]['label'] );
	}
}
