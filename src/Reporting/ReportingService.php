<?php
/**
 * Reporting service for aggregate queries.
 *
 * @package MissionDP
 */

namespace MissionDP\Reporting;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom-table layer. Identifiers are $wpdb->prefix + plugin-hardcoded suffixes; no user input reaches SQL identifiers. Values use %s/%d placeholders throughout.

use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Centralized service for aggregate and cross-table queries.
 *
 * REST endpoints and other consumers use this for summary stats, charts,
 * and joined listings that don't map to single-model CRUD.
 */
class ReportingService {

	/**
	 * Settings service.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsService|null $settings Settings service.
	 */
	public function __construct( ?SettingsService $settings = null ) {
		$this->settings = $settings ?? new SettingsService();
	}

	/**
	 * Whether the site is currently in test mode.
	 *
	 * @return bool
	 */
	private function is_test_mode(): bool {
		return (bool) $this->settings->get( 'test_mode' );
	}

	/**
	 * Get transaction summary stats.
	 *
	 * @param string $currency Currency code (lowercase).
	 * @return array{total_revenue: int, total_donations: int, average_donation: int, total_refunded: int}
	 */
	public function transaction_summary( string $currency ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'missiondp_transactions';
		$is_test = (int) $this->is_test_mode();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					COALESCE(SUM(amount), 0) AS total_revenue,
					COUNT(*) AS total_donations,
					COALESCE(AVG(amount), 0) AS average_donation
				FROM %i
				WHERE status = \'completed\' AND is_test = %d AND currency = %s',
				$table,
				$is_test,
				$currency
			),
			ARRAY_A
		);

		$refund_row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS refund_count, COALESCE(SUM(amount), 0) AS refund_amount
				FROM %i
				WHERE status = \'refunded\' AND is_test = %d AND currency = %s',
				$table,
				$is_test,
				$currency
			),
			ARRAY_A
		);

		// Previous month comparison.
		$now            = new \DateTimeImmutable( 'now', wp_timezone() );
		$prev_start     = $now->modify( 'first day of last month' )->format( 'Y-m-d 00:00:00' );
		$prev_end       = $now->modify( 'first day of this month' )->format( 'Y-m-d 00:00:00' );
		$previous_stats = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount), 0) AS previous_revenue,
						COUNT(*) AS previous_donations
				FROM %i
				WHERE status = \'completed\' AND is_test = %d AND currency = %s
					AND date_created >= %s AND date_created < %s',
				$table,
				$is_test,
				$currency,
				$prev_start,
				$prev_end
			),
			ARRAY_A
		);

		return [
			'total_revenue'         => (int) ( $row['total_revenue'] ?? 0 ),
			'total_donations'       => (int) ( $row['total_donations'] ?? 0 ),
			'average_donation'      => (int) ( $row['average_donation'] ?? 0 ),
			'total_refunded'        => (int) ( $refund_row['refund_count'] ?? 0 ),
			'total_refunded_amount' => (int) ( $refund_row['refund_amount'] ?? 0 ),
			'previous_revenue'      => (int) ( $previous_stats['previous_revenue'] ?? 0 ),
			'previous_donations'    => (int) ( $previous_stats['previous_donations'] ?? 0 ),
		];
	}

	/**
	 * Get donor summary stats.
	 *
	 * @return array{total_donors: int, top_donor_name: string, top_donor_total: int, average_donated: int, repeat_donors: int}
	 */
	public function donor_summary(): array {
		global $wpdb;

		$table       = $wpdb->prefix . 'missiondp_donors';
		$donated_col = $this->is_test_mode() ? 'test_total_donated' : 'total_donated';
		$count_col   = $this->is_test_mode() ? 'test_transaction_count' : 'transaction_count';

		$total_donors = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i > 0', $table, $count_col )
		);

		$top_donor = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT first_name, last_name, %i AS top_donated FROM %i WHERE %i > 0 ORDER BY %i DESC LIMIT 1',
				$donated_col,
				$table,
				$count_col,
				$donated_col
			),
			ARRAY_A
		);

		$average_donated = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(AVG(%i), 0) FROM %i WHERE %i > 0',
				$donated_col,
				$table,
				$count_col
			)
		);

		$repeat_donors = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i > 1', $table, $count_col )
		);

		$top_donor_name  = '';
		$top_donor_total = 0;

		if ( $top_donor ) {
			$top_donor_name  = trim( $top_donor['first_name'] . ' ' . $top_donor['last_name'] );
			$top_donor_total = (int) $top_donor['top_donated'];
		}

		return [
			'total_donors'    => $total_donors,
			'top_donor_name'  => $top_donor_name,
			'top_donor_total' => $top_donor_total,
			'average_donated' => $average_donated,
			'repeat_donors'   => $repeat_donors,
		];
	}

	/**
	 * Get detail stats for a single donor (is_recurring, is_top_donor).
	 *
	 * @param \MissionDP\Models\Donor $donor   The donor.
	 * @param bool|null             $is_test Override test mode (null = use settings).
	 * @return array{is_recurring: bool, is_top_donor: bool}
	 */
	public function donor_detail_stats( \MissionDP\Models\Donor $donor, ?bool $is_test = null ): array {
		global $wpdb;

		$test_mode   = $is_test ?? $this->is_test_mode();
		$sub_table   = $wpdb->prefix . 'missiondp_subscriptions';
		$donor_table = $wpdb->prefix . 'missiondp_donors';
		$donated_col = $test_mode ? 'test_total_donated' : 'total_donated';
		$count_col   = $test_mode ? 'test_transaction_count' : 'transaction_count';

		$donor_donated = $test_mode ? $donor->test_total_donated : $donor->total_donated;
		$donor_count   = $test_mode ? $donor->test_transaction_count : $donor->transaction_count;

		$has_sub = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SELECT 1 FROM %i WHERE donor_id = %d AND status = \'active\' AND is_test = %d LIMIT 1', $sub_table, $donor->id, (int) $test_mode )
		);

		$total_donors = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i > 0', $donor_table, $count_col )
		);
		$is_top       = false;

		if ( $total_donors > 0 && $donor_count > 0 ) {
			$rank   = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE %i > %d AND %i > 0',
					$donor_table,
					$donated_col,
					$donor_donated,
					$count_col
				)
			);
			$is_top = $rank < max( 1, (int) ceil( $total_donors * 0.1 ) );
		}

		return [
			'is_recurring' => $has_sub,
			'is_top_donor' => (bool) apply_filters( 'missiondp_donor_is_top_donor', $is_top, $donor ),
		];
	}

	/**
	 * Get the largest single completed donation for a campaign.
	 *
	 * @param int  $campaign_id Campaign ID (from missiondp_campaigns table).
	 * @param bool $is_test     Whether to query test transactions.
	 * @return int Amount in minor units (0 if no donations).
	 */
	public function top_donation_for_campaign( int $campaign_id, bool $is_test = false ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'missiondp_transactions';

		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(MAX(amount), 0) FROM %i WHERE campaign_id = %d AND status = \'completed\' AND is_test = %d',
				$table,
				$campaign_id,
				(int) $is_test
			)
		);

		return (int) $result;
	}

	/**
	 * Get campaign summary stats.
	 *
	 * @return array<string, mixed>
	 */
	public function campaign_summary(): array {
		global $wpdb;

		$table      = $wpdb->prefix . 'missiondp_campaigns';
		$raised_col = $this->is_test_mode() ? 'test_total_raised' : 'total_raised';

		$total_campaigns = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
		);
		$total_raised    = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(%i), 0) FROM %i', $raised_col, $table )
		);

		$average_per_campaign = $total_campaigns > 0
			? (int) round( $total_raised / $total_campaigns )
			: 0;

		$active    = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = \'active\'', $table )
		);
		$ended     = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = \'ended\'', $table )
		);
		$scheduled = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = \'scheduled\'', $table )
		);

		$top = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT title, %i AS top_raised FROM %i ORDER BY %i DESC LIMIT 1',
				$raised_col,
				$table,
				$raised_col
			),
			ARRAY_A
		);

		$top_campaign_name   = '';
		$top_campaign_raised = 0;

		if ( $top ) {
			$top_campaign_name   = $top['title'];
			$top_campaign_raised = (int) $top['top_raised'];
		}

		return [
			'total_campaigns'      => $total_campaigns,
			'active'               => $active,
			'ended'                => $ended,
			'scheduled'            => $scheduled,
			'total_raised'         => $total_raised,
			'average_per_campaign' => $average_per_campaign,
			'top_campaign_name'    => $top_campaign_name,
			'top_campaign_raised'  => $top_campaign_raised,
		];
	}

	/**
	 * Get live (non-test) stats for the review banner.
	 *
	 * Always uses live data regardless of the current test mode setting.
	 *
	 * @return array{total_raised: int, donation_count: int}
	 */
	public function review_banner_stats(): array {
		global $wpdb;

		$campaigns_table    = $wpdb->prefix . 'missiondp_campaigns';
		$transactions_table = $wpdb->prefix . 'missiondp_transactions';

		$total_raised = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(total_raised), 0) FROM %i', $campaigns_table )
		);

		$donation_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = \'completed\' AND is_test = 0', $transactions_table )
		);

		return [
			'total_raised'   => $total_raised,
			'donation_count' => $donation_count,
		];
	}

	/**
	 * Get aggregate stats for a date range (dashboard).
	 *
	 * @param string $start Start date (Y-m-d H:i:s).
	 * @param string $end   End date (Y-m-d H:i:s).
	 * @return array{total_amount: string, donor_count: string, avg_amount: string}
	 */
	public function period_stats( string $start, string $end ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'missiondp_transactions';
		$is_test = (int) $this->is_test_mode();

		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					COALESCE( SUM( amount ), 0 ) AS total_amount,
					COUNT( DISTINCT donor_id ) AS donor_count,
					COALESCE( AVG( amount ), 0 ) AS avg_amount
				FROM %i
				WHERE status = \'completed\'
					AND is_test = %d
					AND date_completed >= %s
					AND date_completed <= %s',
				$table,
				$is_test,
				$start,
				$end
			),
			ARRAY_A
		);

		return $result ?: [
			'total_amount' => '0',
			'donor_count'  => '0',
			'avg_amount'   => '0',
		];
	}

	/**
	 * Get donation chart data (daily or hourly).
	 *
	 * @param string $start  Start date (Y-m-d H:i:s).
	 * @param string $end    End date (Y-m-d H:i:s).
	 * @param string $period Period identifier (today, week, month).
	 * @return array<int, array{date: string, amount: int}>
	 */
	public function chart_data( string $start, string $end, string $period = 'month' ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'missiondp_transactions';
		$is_test = (int) $this->is_test_mode();

		if ( 'today' === $period ) {
			return $this->get_hourly_chart_data( $table, $start, $end, $is_test );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE( date_completed ) AS day, SUM( amount ) AS amount
				FROM %i
				WHERE status = \'completed\'
					AND is_test = %d
					AND date_completed >= %s
					AND date_completed <= %s
				GROUP BY day
				ORDER BY day ASC',
				$table,
				$is_test,
				$start,
				$end
			),
			ARRAY_A
		);

		$data_map = [];
		foreach ( $rows as $row ) {
			$data_map[ $row['day'] ] = (int) $row['amount'];
		}

		$chart  = [];
		$cursor = new \DateTimeImmutable( $start );
		$end_dt = new \DateTimeImmutable( $end );

		while ( $cursor <= $end_dt ) {
			$day     = $cursor->format( 'Y-m-d' );
			$chart[] = [
				'date'   => $day,
				'amount' => $data_map[ $day ] ?? 0,
			];
			$cursor  = $cursor->modify( '+1 day' );
		}

		return $chart;
	}

	/**
	 * Get top campaigns by total raised (dashboard).
	 *
	 * @param int $limit Number of campaigns.
	 * @return array<int, array{id: int, title: string, total_raised: int, goal_amount: int}>
	 */
	public function top_campaigns( int $limit = 5 ): array {
		global $wpdb;

		$table      = $wpdb->prefix . 'missiondp_campaigns';
		$raised_col = $this->is_test_mode() ? 'test_total_raised' : 'total_raised';

		$txn_count_col   = $this->is_test_mode() ? 'test_transaction_count' : 'transaction_count';
		$donor_count_col = $this->is_test_mode() ? 'test_donor_count' : 'donor_count';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.id, c.title, c.%i AS raised, c.goal_amount, c.goal_type,
					c.%i AS txn_count, c.%i AS dn_count
				FROM %i c
				ORDER BY c.%i DESC
				LIMIT %d',
				$raised_col,
				$txn_count_col,
				$donor_count_col,
				$table,
				$raised_col,
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		$campaigns = [];
		foreach ( $rows as $row ) {
			$goal_type = $row['goal_type'] ?? 'amount';

			$goal_progress = match ( $goal_type ) {
				'donations' => (int) $row['txn_count'],
				'donors'    => (int) $row['dn_count'],
				default     => (int) $row['raised'],
			};

			$campaigns[] = [
				'id'            => (int) $row['id'],
				'title'         => $row['title'] ?: __( 'Untitled', 'mission-donation-platform' ),
				'total_raised'  => (int) $row['raised'],
				'goal_amount'   => (int) $row['goal_amount'],
				'goal_type'     => $goal_type,
				'goal_progress' => $goal_progress,
			];
		}

		return $campaigns;
	}

	/**
	 * Get transactions with donor names for listings.
	 *
	 * @param array<string, mixed> $args Query args: status, campaign_id, donor_id, search, orderby, order, per_page, page.
	 * @return array{items: array, total: int}
	 */
	public function transactions_with_donors( array $args = [] ): array {
		global $wpdb;

		$txn_table   = $wpdb->prefix . 'missiondp_transactions';
		$donor_table = $wpdb->prefix . 'missiondp_donors';

		$per_page = (int) ( $args['per_page'] ?? 25 );
		$page     = (int) ( $args['page'] ?? 1 );

		$allowed_orderby = [ 'date_created', 'amount' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$order_dir       = 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';

		$where         = [];
		$where_values  = [];
		$tribute_table = null;

		// Always filter by test mode.
		$where[]        = 't.is_test = %d';
		$where_values[] = (int) $this->is_test_mode();

		if ( ! empty( $args['status'] ) ) {
			$where[]        = 't.status = %s';
			$where_values[] = $args['status'];
		}

		if ( ! empty( $args['campaign_id'] ) ) {
			$where[]        = 't.campaign_id = %d';
			$where_values[] = $args['campaign_id'];
		}

		if ( ! empty( $args['donor_id'] ) ) {
			$where[]        = 't.donor_id = %d';
			$where_values[] = $args['donor_id'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like           = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]        = '(d.first_name LIKE %s OR d.last_name LIKE %s OR d.email LIKE %s OR t.id = %d)';
			$where_values[] = $like;
			$where_values[] = $like;
			$where_values[] = $like;
			$where_values[] = (int) $args['search'];
		}

		// Dedication filter — requires a JOIN to the tributes table.
		if ( ! empty( $args['dedication'] ) ) {
			$tribute_table = $wpdb->prefix . 'missiondp_tributes';

			match ( $args['dedication'] ) {
				'mail_pending' => array_push( $where, "tribute.notify_method = 'mail'", 'tribute.notification_sent_at IS NULL' ),
				'mail_sent'    => array_push( $where, "tribute.notify_method = 'mail'", 'tribute.notification_sent_at IS NOT NULL' ),
				'email_sent'   => array_push( $where, "tribute.notify_method = 'email'", 'tribute.notification_sent_at IS NOT NULL' ),
				default        => null, // 'any' — the INNER JOIN alone filters to tributes.
			};
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where );
		$tribute_join = null === $tribute_table ? '' : ' INNER JOIN %i AS tribute ON t.id = tribute.transaction_id';

		// Values must appear in SQL placeholder order: table identifiers first, then WHERE values.
		$base_values = [ $txn_table, $donor_table ];
		if ( null !== $tribute_table ) {
			$base_values[] = $tribute_table;
		}
		$base_values = array_merge( $base_values, $where_values );

		// Count total.
		$count_sql = 'SELECT COUNT(*) FROM %i AS t LEFT JOIN %i AS d ON t.donor_id = d.id' . $tribute_join . ' ' . $where_clause;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $count_sql composed from literal fragments above: table names use %i, WHERE fragments contain only %s/%d placeholders.
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $base_values ) );

		// Fetch rows.
		$offset = ( $page - 1 ) * $per_page;

		$sql = 'SELECT t.*, d.first_name AS donor_first_name, d.last_name AS donor_last_name, d.email AS donor_email'
			. ' FROM %i AS t'
			. ' LEFT JOIN %i AS d ON t.donor_id = d.id'
			. $tribute_join
			. ' ' . $where_clause
			. ' ORDER BY t.%i ' . $order_dir
			. ' LIMIT %d OFFSET %d';

		$rows_values = array_merge( $base_values, [ $orderby, $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql composed from literal fragments above: table names and orderby column use %i, ASC/DESC is a hardcoded ternary literal, WHERE fragments contain only %s/%d placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $rows_values ), ARRAY_A );

		// Batch-fetch campaign titles from the campaigns table.
		$campaign_ids = array_unique( array_filter( array_column( $rows ?: [], 'campaign_id' ) ) );
		$campaign_map = [];
		if ( $campaign_ids ) {
			$placeholders         = implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) );
			$campaign_lookup_sql  = 'SELECT id, title FROM %i WHERE id IN (' . $placeholders . ')';
			$campaign_lookup_args = array_merge( [ $wpdb->prefix . 'missiondp_campaigns' ], array_map( 'intval', $campaign_ids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN clause expands one %d per campaign ID; every ID passes through intval() before binding.
			$campaigns = $wpdb->get_results( $wpdb->prepare( $campaign_lookup_sql, $campaign_lookup_args ), ARRAY_A );
			foreach ( $campaigns as $campaign ) {
				$campaign_map[ $campaign['id'] ] = $campaign['title'];
			}
		}

		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$donor_name = trim( ( $row['donor_first_name'] ?? '' ) . ' ' . ( $row['donor_last_name'] ?? '' ) );

			$items[] = [
				'id'             => (int) $row['id'],
				'donor_name'     => $donor_name ?: __( 'Anonymous', 'mission-donation-platform' ),
				'donor_email'    => $row['donor_email'] ?? '',
				'amount'         => (int) $row['amount'],
				'currency'       => $row['currency'],
				'status'         => $row['status'],
				'type'           => $row['type'],
				'campaign_id'    => $row['campaign_id'] ? (int) $row['campaign_id'] : null,
				'campaign_title' => $campaign_map[ $row['campaign_id'] ?? 0 ] ?? '',
				'date_created'   => $row['date_created'],
			];
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Get subscriptions with pre-joined donor data and search support.
	 *
	 * @param array $args Query arguments: per_page, page, orderby, order, status, donor_id, campaign_id, search, is_test.
	 * @return array{items: array, total: int}
	 */
	public function subscriptions_with_donors( array $args = [] ): array {
		global $wpdb;

		$sub_table   = $wpdb->prefix . 'missiondp_subscriptions';
		$donor_table = $wpdb->prefix . 'missiondp_donors';

		$per_page = (int) ( $args['per_page'] ?? 25 );
		$page     = (int) ( $args['page'] ?? 1 );

		$allowed_orderby = [ 'id', 'date_created', 'date_next_renewal', 'total_amount', 'status' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$order_dir       = 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';

		$where        = [];
		$where_values = [];

		// Always filter by test mode.
		$where[]        = 's.is_test = %d';
		$where_values[] = (int) $this->is_test_mode();

		if ( ! empty( $args['status'] ) ) {
			$where[]        = 's.status = %s';
			$where_values[] = $args['status'];
		}

		if ( ! empty( $args['campaign_id'] ) ) {
			$where[]        = 's.campaign_id = %d';
			$where_values[] = $args['campaign_id'];
		}

		if ( ! empty( $args['donor_id'] ) ) {
			$where[]        = 's.donor_id = %d';
			$where_values[] = $args['donor_id'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like           = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]        = '(d.first_name LIKE %s OR d.last_name LIKE %s OR d.email LIKE %s OR s.gateway_subscription_id LIKE %s)';
			$where_values[] = $like;
			$where_values[] = $like;
			$where_values[] = $like;
			$where_values[] = $like;
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where );
		$base_values  = array_merge( [ $sub_table, $donor_table ], $where_values );

		// Count total.
		$count_sql = 'SELECT COUNT(*) FROM %i AS s LEFT JOIN %i AS d ON s.donor_id = d.id ' . $where_clause;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $count_sql composed from literal fragments above: table names use %i, WHERE fragments contain only %s/%d placeholders.
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $base_values ) );

		// Fetch rows.
		$offset = ( $page - 1 ) * $per_page;

		$sql = 'SELECT s.*, d.first_name AS donor_first_name, d.last_name AS donor_last_name, d.email AS donor_email'
			. ' FROM %i AS s'
			. ' LEFT JOIN %i AS d ON s.donor_id = d.id'
			. ' ' . $where_clause
			. ' ORDER BY s.%i ' . $order_dir
			. ' LIMIT %d OFFSET %d';

		$rows_values = array_merge( $base_values, [ $orderby, $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql composed from literal fragments above: table names and orderby column use %i, ASC/DESC is a hardcoded ternary literal, WHERE fragments contain only %s/%d placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $rows_values ), ARRAY_A );

		// Batch-fetch campaign titles.
		$campaign_ids = array_unique( array_filter( array_column( $rows ?: [], 'campaign_id' ) ) );
		$campaign_map = [];
		if ( $campaign_ids ) {
			$placeholders         = implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) );
			$campaign_lookup_sql  = 'SELECT id, title FROM %i WHERE id IN (' . $placeholders . ')';
			$campaign_lookup_args = array_merge( [ $wpdb->prefix . 'missiondp_campaigns' ], array_map( 'intval', $campaign_ids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN clause expands one %d per campaign ID; every ID passes through intval() before binding.
			$campaigns = $wpdb->get_results( $wpdb->prepare( $campaign_lookup_sql, $campaign_lookup_args ), ARRAY_A );
			foreach ( $campaigns as $campaign ) {
				$campaign_map[ $campaign['id'] ] = $campaign['title'];
			}
		}

		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$donor_name = trim( ( $row['donor_first_name'] ?? '' ) . ' ' . ( $row['donor_last_name'] ?? '' ) );

			$items[] = [
				'id'                => (int) $row['id'],
				'status'            => $row['status'],
				'amount'            => (int) $row['amount'],
				'fee_amount'        => (int) $row['fee_amount'],
				'tip_amount'        => (int) $row['tip_amount'],
				'total_amount'      => (int) $row['total_amount'],
				'currency'          => $row['currency'],
				'frequency'         => $row['frequency'],
				'renewal_count'     => (int) $row['renewal_count'],
				'total_renewed'     => (int) $row['total_renewed'],
				'date_created'      => $row['date_created'],
				'date_next_renewal' => $row['date_next_renewal'],
				'donor_name'        => $donor_name ?: __( 'Anonymous', 'mission-donation-platform' ),
				'donor_email'       => $row['donor_email'] ?? '',
				'donor_id'          => $row['donor_id'] ? (int) $row['donor_id'] : null,
				'campaign_title'    => $campaign_map[ $row['campaign_id'] ?? 0 ] ?? '',
				'campaign_id'       => $row['campaign_id'] ? (int) $row['campaign_id'] : null,
			];
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Get subscription summary stats (MRR, churn, etc.).
	 *
	 * @return array<string, mixed>
	 */
	public function subscription_summary(): array {
		global $wpdb;

		$table       = $wpdb->prefix . 'missiondp_subscriptions';
		$is_test     = (int) $this->is_test_mode();
		$now         = new \DateTimeImmutable( 'now', wp_timezone() );
		$month_start = $now->modify( 'first day of this month' )->format( 'Y-m-d 00:00:00' );

		$monthly_expr = $this->frequency_to_monthly_sql( 'amount', 'frequency' );

		// MRR: sum of active subscriptions normalized to monthly.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $monthly_expr is a hardcoded SQL CASE expression with no user input.
		$mrr = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM({$monthly_expr}), 0)
				FROM %i
				WHERE status = 'active' AND is_test = %d",
				$table,
				$is_test
			)
		);

		// Previous MRR: subscriptions that were active at start of current month.
		$prev_mrr = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM({$monthly_expr}), 0)
				FROM %i
				WHERE is_test = %d
					AND date_created < %s
					AND (status = 'active' OR (status = 'cancelled' AND date_cancelled >= %s))",
				$table,
				$is_test,
				$month_start,
				$month_start
			)
		);

		// Active count.
		$active = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE status = 'active' AND is_test = %d",
				$table,
				$is_test
			)
		);

		// New this month: active subscriptions created since first of month.
		$new_this_month = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE status = 'active' AND is_test = %d AND date_created >= %s",
				$table,
				$is_test,
				$month_start
			)
		);

		// Churned: subscriptions cancelled this month.
		$churned = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE status = 'cancelled' AND is_test = %d AND date_cancelled >= %s",
				$table,
				$is_test,
				$month_start
			)
		);

		// Churned MRR: normalized monthly amount lost from churned subscriptions.
		$churned_mrr = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM({$monthly_expr}), 0)
				FROM %i
				WHERE status = 'cancelled' AND is_test = %d AND date_cancelled >= %s",
				$table,
				$is_test,
				$month_start
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$average_monthly = $active > 0 ? (int) round( $mrr / $active ) : 0;

		return [
			'mrr'             => $mrr,
			'previous_mrr'    => $prev_mrr,
			'active'          => $active,
			'new_this_month'  => $new_this_month,
			'average_monthly' => $average_monthly,
			'churned'         => $churned,
			'churned_mrr'     => $churned_mrr,
		];
	}

	/**
	 * SQL CASE expression to normalize a subscription amount to monthly.
	 *
	 * @param string $amount_col    Column name for the amount.
	 * @param string $frequency_col Column name for the frequency.
	 * @return string SQL expression.
	 */
	private function frequency_to_monthly_sql( string $amount_col, string $frequency_col ): string {
		return "CASE {$frequency_col}
			WHEN 'weekly' THEN {$amount_col} * 52 / 12
			WHEN 'monthly' THEN {$amount_col}
			WHEN 'quarterly' THEN {$amount_col} / 3
			WHEN 'annually' THEN {$amount_col} / 12
			ELSE {$amount_col}
		END";
	}

	/**
	 * Get hourly donation totals for "today" chart.
	 *
	 * @param string $table   Table name.
	 * @param string $start   Start date.
	 * @param string $end     End date.
	 * @param int    $is_test Whether to filter by test mode.
	 * @return array<int, array{date: string, amount: int}>
	 */
	private function get_hourly_chart_data( string $table, string $start, string $end, int $is_test ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT HOUR( date_completed ) AS hr, SUM( amount ) AS amount
				FROM %i
				WHERE status = \'completed\'
					AND is_test = %d
					AND date_completed >= %s
					AND date_completed <= %s
				GROUP BY hr
				ORDER BY hr ASC',
				$table,
				$is_test,
				$start,
				$end
			),
			ARRAY_A
		);

		$data_map = [];
		foreach ( $rows as $row ) {
			$data_map[ (int) $row['hr'] ] = (int) $row['amount'];
		}

		$current_hour = (int) gmdate( 'G' );
		$chart        = [];

		for ( $h = 0; $h <= $current_hour; $h++ ) {
			$chart[] = [
				'date'   => sprintf( '%02d:00', $h ),
				'amount' => $data_map[ $h ] ?? 0,
			];
		}

		return $chart;
	}

	/**
	 * Get top donors for a campaign, ranked by total donated amount.
	 *
	 * @param int $campaign_id Campaign ID (from missiondp_campaigns table).
	 * @param int $limit       Number of donors to return.
	 * @return array<int, array{donor_id: int, first_name: string, last_name: string, email: string, is_anonymous: bool, total: int, tribute_type: ?string, honoree_name: ?string}>
	 */
	public function top_donors_for_campaign( int $campaign_id, int $limit = 5 ): array {
		global $wpdb;

		$txn_table   = $wpdb->prefix . 'missiondp_transactions';
		$donor_table = $wpdb->prefix . 'missiondp_donors';
		$is_test     = (int) $this->is_test_mode();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.donor_id, d.first_name, d.last_name, d.email,
					SUM(t.amount) AS total, MAX(t.is_anonymous) AS is_anonymous
				FROM %i AS t
				INNER JOIN %i AS d ON t.donor_id = d.id
				WHERE t.campaign_id = %d AND t.status = \'completed\' AND t.is_test = %d
				GROUP BY t.donor_id
				ORDER BY total DESC
				LIMIT %d',
				$txn_table,
				$donor_table,
				$campaign_id,
				$is_test,
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		// Batch-fetch tributes: get the most recent transaction per donor, then load tributes.
		$donor_ids   = array_column( $rows, 'donor_id' );
		$tribute_map = $this->batch_fetch_tributes_for_donors( $donor_ids, $campaign_id );

		$donors = [];
		foreach ( $rows as $row ) {
			$tribute  = $tribute_map[ (int) $row['donor_id'] ] ?? null;
			$donors[] = [
				'donor_id'     => (int) $row['donor_id'],
				'first_name'   => $row['first_name'],
				'last_name'    => $row['last_name'],
				'email'        => $row['email'],
				'is_anonymous' => (bool) $row['is_anonymous'],
				'total'        => (int) $row['total'],
				'tribute_type' => $tribute['tribute_type'] ?? null,
				'honoree_name' => $tribute['honoree_name'] ?? null,
			];
		}

		return $donors;
	}

	/**
	 * Get recent donors for a campaign, ordered by transaction date.
	 *
	 * @param int $campaign_id Campaign ID (from missiondp_campaigns table).
	 * @param int $limit       Number of entries to return.
	 * @return array<int, array{donor_id: int, transaction_id: int, first_name: string, last_name: string, email: string, is_anonymous: bool, amount: int, date_created: string, tribute_type: ?string, honoree_name: ?string}>
	 */
	public function recent_donors_for_campaign( int $campaign_id, int $limit = 5 ): array {
		global $wpdb;

		$txn_table   = $wpdb->prefix . 'missiondp_transactions';
		$donor_table = $wpdb->prefix . 'missiondp_donors';
		$is_test     = (int) $this->is_test_mode();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.id AS transaction_id, t.donor_id, t.amount, t.is_anonymous, t.date_created,
					d.first_name, d.last_name, d.email
				FROM %i AS t
				INNER JOIN %i AS d ON t.donor_id = d.id
				WHERE t.campaign_id = %d AND t.status = \'completed\' AND t.is_test = %d
				ORDER BY t.date_created DESC
				LIMIT %d',
				$txn_table,
				$donor_table,
				$campaign_id,
				$is_test,
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		// Batch-fetch tributes for the transaction IDs.
		$txn_ids     = array_column( $rows, 'transaction_id' );
		$tribute_map = $this->batch_fetch_tributes_by_transaction( $txn_ids );

		$donors = [];
		foreach ( $rows as $row ) {
			$txn_id   = (int) $row['transaction_id'];
			$tribute  = $tribute_map[ $txn_id ] ?? null;
			$donors[] = [
				'donor_id'       => (int) $row['donor_id'],
				'transaction_id' => $txn_id,
				'first_name'     => $row['first_name'],
				'last_name'      => $row['last_name'],
				'email'          => $row['email'],
				'is_anonymous'   => (bool) $row['is_anonymous'],
				'amount'         => (int) $row['amount'],
				'date_created'   => $row['date_created'],
				'tribute_type'   => $tribute['tribute_type'] ?? null,
				'honoree_name'   => $tribute['honoree_name'] ?? null,
			];
		}

		return $donors;
	}

	/**
	 * Batch-fetch the most recent tribute per donor for a campaign.
	 *
	 * @param int[] $donor_ids   Donor IDs.
	 * @param int   $campaign_id Campaign ID.
	 * @return array<int, array{tribute_type: string, honoree_name: string}> Keyed by donor_id.
	 */
	private function batch_fetch_tributes_for_donors( array $donor_ids, int $campaign_id ): array {
		global $wpdb;

		if ( ! $donor_ids ) {
			return [];
		}

		$txn_table     = $wpdb->prefix . 'missiondp_transactions';
		$tribute_table = $wpdb->prefix . 'missiondp_tributes';
		$is_test       = (int) $this->is_test_mode();

		$placeholders = implode( ',', array_fill( 0, count( $donor_ids ), '%d' ) );
		$values       = array_merge(
			[ $txn_table ],
			array_map( 'intval', $donor_ids ),
			[ $campaign_id, $is_test, $tribute_table ]
		);
		$sql          = 'SELECT sub.donor_id, tr.tribute_type, tr.honoree_name'
			. ' FROM ('
			. '   SELECT donor_id, MAX(id) AS max_txn_id'
			. '   FROM %i'
			. '   WHERE donor_id IN (' . $placeholders . ') AND campaign_id = %d AND status = \'completed\' AND is_test = %d'
			. '   GROUP BY donor_id'
			. ' ) AS sub'
			. ' INNER JOIN %i AS tr ON tr.transaction_id = sub.max_txn_id';

		// Get the most recent transaction ID per donor, then join tributes.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN clause expands one %d per donor ID; every ID passes through intval() before binding. Table names use %i.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		$map = [];
		foreach ( $rows ?: [] as $row ) {
			$map[ (int) $row['donor_id'] ] = [
				'tribute_type' => $row['tribute_type'],
				'honoree_name' => $row['honoree_name'],
			];
		}

		return $map;
	}

	/**
	 * Batch-fetch tributes by transaction IDs.
	 *
	 * @param int[] $transaction_ids Transaction IDs.
	 * @return array<int, array{tribute_type: string, honoree_name: string}> Keyed by transaction_id.
	 */
	private function batch_fetch_tributes_by_transaction( array $transaction_ids ): array {
		global $wpdb;

		if ( ! $transaction_ids ) {
			return [];
		}

		$tribute_table = $wpdb->prefix . 'missiondp_tributes';
		$placeholders  = implode( ',', array_fill( 0, count( $transaction_ids ), '%d' ) );
		$sql           = 'SELECT transaction_id, tribute_type, honoree_name FROM %i WHERE transaction_id IN (' . $placeholders . ')';
		$values        = array_merge( [ $tribute_table ], array_map( 'intval', $transaction_ids ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN clause expands one %d per transaction ID; every ID passes through intval() before binding. Table name uses %i.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		$map = [];
		foreach ( $rows ?: [] as $row ) {
			$map[ (int) $row['transaction_id'] ] = [
				'tribute_type' => $row['tribute_type'],
				'honoree_name' => $row['honoree_name'],
			];
		}

		return $map;
	}

	/**
	 * Query donations for the public donor wall.
	 *
	 * @param int    $campaign_id    Campaign ID (0 = all campaigns).
	 * @param int    $per_page       Results per page.
	 * @param int    $page           Page number.
	 * @param string $orderby        Order by column (date_completed or amount).
	 * @param string $order          Sort direction (ASC or DESC).
	 * @param bool   $show_anonymous Whether to include anonymous donations.
	 * @return array{items: array, total: int}
	 */
	public function donor_wall_query(
		int $campaign_id = 0,
		int $per_page = 12,
		int $page = 1,
		string $orderby = 'date_completed',
		string $order = 'DESC',
		bool $show_anonymous = true,
	): array {
		global $wpdb;

		$txn_table   = $wpdb->prefix . 'missiondp_transactions';
		$donor_table = $wpdb->prefix . 'missiondp_donors';
		$offset      = ( $page - 1 ) * $per_page;

		// Validate orderby.
		$allowed_orderby = [ 'date_completed', 'amount' ];
		$orderby         = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'date_completed';
		$order_dir       = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		// Build WHERE clause.
		$where        = [ "t.status = 'completed'", 't.is_test = %d' ];
		$where_values = [ (int) $this->is_test_mode() ];

		if ( $campaign_id > 0 ) {
			$where[]        = 't.campaign_id = %d';
			$where_values[] = $campaign_id;
		}

		if ( ! $show_anonymous ) {
			$where[] = 't.is_anonymous = 0';
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where );

		// Count query.
		$count_sql    = 'SELECT COUNT(*) FROM %i AS t ' . $where_clause;
		$count_values = array_merge( [ $txn_table ], $where_values );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $count_sql composed from literal fragments above: table name uses %i, WHERE fragments contain only %s/%d placeholders or hardcoded literals.
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_values ) );

		// Main query.
		$sql = 'SELECT t.id AS transaction_id, t.amount, t.type, t.is_anonymous,'
			. ' t.date_completed, t.currency, d.first_name, d.last_name, d.email'
			. ' FROM %i AS t'
			. ' INNER JOIN %i AS d ON t.donor_id = d.id'
			. ' ' . $where_clause
			. ' ORDER BY t.%i ' . $order_dir
			. ' LIMIT %d OFFSET %d';

		$main_values = array_merge(
			[ $txn_table, $donor_table ],
			$where_values,
			[ $orderby, $per_page, $offset ]
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql composed from literal fragments above: table names and orderby column use %i, ASC/DESC is a hardcoded ternary literal, WHERE fragments contain only %s/%d placeholders or hardcoded literals.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $main_values ), ARRAY_A );

		if ( ! $rows ) {
			return [
				'items' => [],
				'total' => $total,
			];
		}

		// Batch-fetch donor comments from transaction meta.
		$txn_ids     = array_column( $rows, 'transaction_id' );
		$comment_map = $this->batch_fetch_donor_comments( $txn_ids );

		$items = [];
		foreach ( $rows as $row ) {
			$txn_id  = (int) $row['transaction_id'];
			$items[] = [
				'first_name'   => $row['first_name'],
				'last_name'    => $row['last_name'],
				'email'        => $row['email'],
				'is_anonymous' => (bool) $row['is_anonymous'],
				'amount'       => (int) $row['amount'],
				'type'         => $row['type'],
				'date'         => $row['date_completed'],
				'currency'     => $row['currency'],
				'comment'      => $comment_map[ $txn_id ] ?? null,
			];
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Batch-fetch donor comments by transaction IDs.
	 *
	 * @param int[] $transaction_ids Transaction IDs.
	 * @return array<int, string> Keyed by transaction_id.
	 */
	private function batch_fetch_donor_comments( array $transaction_ids ): array {
		global $wpdb;

		if ( ! $transaction_ids ) {
			return [];
		}

		$meta_table   = $wpdb->prefix . 'missiondp_transactionmeta';
		$placeholders = implode( ',', array_fill( 0, count( $transaction_ids ), '%d' ) );
		$sql          = 'SELECT missiondp_transaction_id, meta_value FROM %i WHERE missiondp_transaction_id IN (' . $placeholders . ') AND meta_key = \'donor_comment\'';
		$values       = array_merge( [ $meta_table ], array_map( 'intval', $transaction_ids ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN clause expands one %d per transaction ID; every ID passes through intval() before binding. Table name uses %i.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		$map = [];
		foreach ( $rows ?: [] as $row ) {
			$map[ (int) $row['missiondp_transaction_id'] ] = $row['meta_value'];
		}

		return $map;
	}

	/**
	 * Get annual donation summaries for a specific donor.
	 *
	 * Groups completed transactions by year with totals and counts.
	 *
	 * @param int $donor_id Donor ID.
	 * @return array<int, array{year: int, total: int, count: int}>
	 */
	public function donor_annual_summary( int $donor_id, bool $is_test = false ): array {
		global $wpdb;

		$table     = $wpdb->prefix . 'missiondp_transactions';
		$test_flag = $is_test ? 1 : 0;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT
					YEAR(date_completed) AS year,
					COALESCE(SUM(amount), 0) AS total,
					COUNT(*) AS count
				FROM %i
				WHERE donor_id = %d AND status = \'completed\' AND is_test = %d
				GROUP BY YEAR(date_completed)
				ORDER BY year DESC',
				$table,
				$donor_id,
				$test_flag
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ) => [
				'year'  => (int) $row['year'],
				'total' => (int) $row['total'],
				'count' => (int) $row['count'],
			],
			$rows ?: []
		);
	}

	/**
	 * Get detailed transaction data for a donor's annual receipt.
	 *
	 * Returns completed, non-test transactions for the given year with
	 * campaign names — ready for PDF generation.
	 *
	 * @param int $donor_id Donor ID.
	 * @param int $year     Calendar year.
	 * @return array{
	 *     transactions: array<int, array{
	 *         id: int,
	 *         amount: int,
	 *         currency: string,
	 *         campaign_name: string,
	 *         payment_gateway: string,
	 *         date_completed: string
	 *     }>,
	 *     total: int,
	 *     count: int,
	 *     currency: string
	 * }
	 */
	public function donor_annual_receipt_data( int $donor_id, int $year, bool $is_test = false ): array {
		global $wpdb;

		$txn_table = $wpdb->prefix . 'missiondp_transactions';
		$cam_table = $wpdb->prefix . 'missiondp_campaigns';
		$test_flag = $is_test ? 1 : 0;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT
					t.id,
					t.amount,
					t.currency,
					COALESCE(c.title, %s) AS campaign_name,
					t.payment_gateway,
					t.date_completed
				FROM %i t
				LEFT JOIN %i c ON c.id = t.campaign_id
				WHERE t.donor_id = %d
					AND t.status = \'completed\'
					AND t.is_test = %d
					AND YEAR(t.date_completed) = %d
				ORDER BY t.date_completed ASC',
				__( 'General Fund', 'mission-donation-platform' ),
				$txn_table,
				$cam_table,
				$donor_id,
				$test_flag,
				$year
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [
				'transactions' => [],
				'total'        => 0,
				'count'        => 0,
				'currency'     => '',
			];
		}

		$total        = 0;
		$transactions = [];

		foreach ( $rows as $row ) {
			$amount = (int) $row['amount'];
			$total += $amount;

			$transactions[] = [
				'id'              => (int) $row['id'],
				'amount'          => $amount,
				'currency'        => $row['currency'],
				'campaign_name'   => $row['campaign_name'],
				'payment_gateway' => $row['payment_gateway'],
				'date_completed'  => $row['date_completed'],
			];
		}

		return [
			'transactions' => $transactions,
			'total'        => $total,
			'count'        => count( $transactions ),
			'currency'     => $transactions[0]['currency'] ?? '',
		];
	}

	/**
	 * Get distinct years a donor has transactions in.
	 *
	 * @param int $donor_id Donor ID.
	 * @return int[] Years in descending order.
	 */
	public function donor_transaction_years( int $donor_id, bool $is_test = false ): array {
		global $wpdb;

		$table     = $wpdb->prefix . 'missiondp_transactions';
		$test_flag = $is_test ? 1 : 0;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT YEAR(date_created) AS year
				FROM %i
				WHERE donor_id = %d AND is_test = %d
				ORDER BY year DESC',
				$table,
				$donor_id,
				$test_flag
			)
		);

		return array_map( 'intval', $rows ?: [] );
	}

	/**
	 * Get distinct campaigns a donor has transacted with.
	 *
	 * @param int $donor_id Donor ID.
	 * @return array<int, array{id: int, name: string}> Campaign ID/name pairs.
	 */
	public function donor_transaction_campaigns( int $donor_id, bool $is_test = false ): array {
		global $wpdb;

		$txn_table = $wpdb->prefix . 'missiondp_transactions';
		$cam_table = $wpdb->prefix . 'missiondp_campaigns';
		$test_flag = $is_test ? 1 : 0;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT t.campaign_id AS id, COALESCE(c.title, %s) AS name
				FROM %i t
				LEFT JOIN %i c ON c.id = t.campaign_id
				WHERE t.donor_id = %d AND t.is_test = %d AND t.campaign_id IS NOT NULL
				ORDER BY name ASC',
				__( 'Deleted Campaign', 'mission-donation-platform' ),
				$txn_table,
				$cam_table,
				$donor_id,
				$test_flag
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ) => [
				'id'   => (int) $row['id'],
				'name' => $row['name'],
			],
			$rows ?: []
		);
	}
}
