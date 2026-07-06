<?php
/**
 * Reporting service for aggregate queries.
 *
 * @package MissionDP
 */

namespace MissionDP\Reporting;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Reporting queries assemble SQL from literal fragments + the SearchClauseBuilder helper (whose placeholders are matched in $where_args) and whitelisted ASC/DESC direction strings.

use MissionDP\Database\SearchClauseBuilder;
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
	 * Per-instance memo of team_totals() results, keyed by "{team_id}:{is_test}".
	 *
	 * @var array<string, array{raised:int, donations:int, member_count:int}>
	 */
	private array $team_totals_memo = [];

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
			'is_top_donor' => (bool) apply_filters( 'mission_donor_is_top_donor', $is_top, $donor ),
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
	 * Imported transactions are excluded — the banner congratulates the user
	 * on money raised through Mission, not on a migrated history.
	 *
	 * @return array{total_raised: int, donation_count: int}
	 */
	public function review_banner_stats(): array {
		global $wpdb;

		$transactions_table = $wpdb->prefix . 'missiondp_transactions';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount), 0) AS total_raised, COUNT(*) AS donation_count
				 FROM %i
				 WHERE status = \'completed\' AND is_test = 0 AND import_job_id = 0',
				$transactions_table
			),
			ARRAY_A
		);

		return [
			'total_raised'   => (int) ( $row['total_raised'] ?? 0 ),
			'donation_count' => (int) ( $row['donation_count'] ?? 0 ),
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
	 * @param array<string, mixed> $args Query args: status, campaign_id, donor_id, fundraiser_id, team_id, search, orderby, order, per_page, page.
	 * @return array{items: array, total: int}
	 */
	public function transactions_with_donors( array $args = [] ): array {
		global $wpdb;

		$txn_table     = $wpdb->prefix . 'missiondp_transactions';
		$donor_table   = $wpdb->prefix . 'missiondp_donors';
		$tribute_table = $wpdb->prefix . 'missiondp_tributes';
		$fr_table      = $wpdb->prefix . 'missiondp_fundraisers';

		$per_page = (int) ( $args['per_page'] ?? 25 );
		$page     = (int) ( $args['page'] ?? 1 );

		$allowed_orderby = [ 'date_created', 'amount' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$direction       = 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';

		$is_test = (int) $this->is_test_mode();

		$has_status   = ! empty( $args['status'] ) ? 1 : 0;
		$status       = $has_status ? (string) $args['status'] : '';
		$has_campaign = ! empty( $args['campaign_id'] ) ? 1 : 0;
		$campaign_id  = $has_campaign ? (int) $args['campaign_id'] : 0;
		$has_donor    = ! empty( $args['donor_id'] ) ? 1 : 0;
		$donor_id     = $has_donor ? (int) $args['donor_id'] : 0;

		$has_fundraiser = ! empty( $args['fundraiser_id'] ) ? 1 : 0;
		$fundraiser_id  = $has_fundraiser ? (int) $args['fundraiser_id'] : 0;
		$has_team       = ! empty( $args['team_id'] ) ? 1 : 0;
		$team_id        = $has_team ? (int) $args['team_id'] : 0;

		$search_clause = SearchClauseBuilder::build_like_clause(
			(string) ( $args['search'] ?? '' ),
			[ 'd.first_name', 'd.last_name', 'd.email' ]
		);
		if ( $search_clause ) {
			$search_id        = (int) $args['search'];
			$search_sql       = '(' . $search_clause['sql'] . ' OR t.id = ' . $search_id . ')';
			$search_params    = $search_clause['params'];
			$search_where_sql = ' AND ' . $search_sql;
		} else {
			$search_params    = [];
			$search_where_sql = '';
		}

		$dedication      = $args['dedication'] ?? '';
		$has_dedication  = '' !== $dedication ? 1 : 0;
		$is_mail_pending = 'mail_pending' === $dedication ? 1 : 0;
		$is_mail_sent    = 'mail_sent' === $dedication ? 1 : 0;
		$is_email_sent   = 'email_sent' === $dedication ? 1 : 0;

		$offset = ( $page - 1 ) * $per_page;

		// The spliced search fragment is safe SQL: the helper emits only %s
		// placeholders and trusted column names, and the t.id branch is int-cast.
		$where = 'WHERE t.is_test = %d
				   AND ( %d = 0 OR t.status = %s )
				   AND ( %d = 0 OR t.campaign_id = %d )
				   AND ( %d = 0 OR t.donor_id = %d )
				   AND ( %d = 0 OR t.fundraiser_id = %d )
				   AND ( %d = 0 OR t.team_id = %d OR t.fundraiser_id IN (
						SELECT f.id FROM %i AS f WHERE f.team_id = %d
					) )' . $search_where_sql . '
				   AND ( %d = 0 OR EXISTS (
						SELECT 1 FROM %i AS tr
						WHERE tr.transaction_id = t.id
						  AND ( %d = 0 OR ( tr.notify_method = \'mail\' AND tr.notification_sent_at IS NULL ) )
						  AND ( %d = 0 OR ( tr.notify_method = \'mail\' AND tr.notification_sent_at IS NOT NULL ) )
						  AND ( %d = 0 OR ( tr.notify_method = \'email\' AND tr.notification_sent_at IS NOT NULL ) )
					) )';

		// Order must match the placeholders in $where, with search params at the splice point.
		$where_args = array_merge(
			[ $is_test, $has_status, $status, $has_campaign, $campaign_id, $has_donor, $donor_id ],
			[ $has_fundraiser, $fundraiser_id, $has_team, $team_id, $fr_table, $team_id ],
			$search_params,
			[ $has_dedication, $tribute_table, $is_mail_pending, $is_mail_sent, $is_email_sent ]
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i AS t
				 LEFT JOIN %i AS d ON t.donor_id = d.id
				 ' . $where,
				array_merge( [ $txn_table, $donor_table ], $where_args )
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.*, d.first_name AS donor_first_name, d.last_name AS donor_last_name, d.email AS donor_email
				 FROM %i AS t
				 LEFT JOIN %i AS d ON t.donor_id = d.id
				 ' . $where . "
				 ORDER BY t.%i {$direction}
				 LIMIT %d OFFSET %d",
				array_merge(
					[ $txn_table, $donor_table ],
					$where_args,
					[ $orderby, $per_page, $offset ]
				)
			),
			ARRAY_A
		);

		$campaign_ids = array_unique( array_filter( array_column( $rows ?: [], 'campaign_id' ) ) );
		$campaign_map = [];
		if ( $campaign_ids ) {
			$campaign_ids = array_map( 'intval', $campaign_ids );
			$placeholders = implode( ', ', array_fill( 0, count( $campaign_ids ), '%d' ) );
			$sql          = "SELECT id, title FROM %i WHERE id IN ( {$placeholders} )";
			$prepare_args = array_merge( [ $wpdb->prefix . 'missiondp_campaigns' ], $campaign_ids );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
			$campaigns = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );
			foreach ( $campaigns as $campaign ) {
				$campaign_map[ $campaign['id'] ] = $campaign['title'];
			}
		}

		$fundraiser_map = $this->batch_fetch_fundraiser_names(
			array_unique( array_filter( array_column( $rows ?: [], 'fundraiser_id' ) ) )
		);

		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$donor_name = trim( ( $row['donor_first_name'] ?? '' ) . ' ' . ( $row['donor_last_name'] ?? '' ) );

			$items[] = [
				'id'              => (int) $row['id'],
				'donor_id'        => $row['donor_id'] ? (int) $row['donor_id'] : null,
				'donor_name'      => $donor_name ?: __( 'Anonymous', 'mission-donation-platform' ),
				'donor_email'     => $row['donor_email'] ?? '',
				'amount'          => (int) $row['amount'],
				'currency'        => $row['currency'],
				'status'          => $row['status'],
				'type'            => $row['type'],
				'campaign_id'     => $row['campaign_id'] ? (int) $row['campaign_id'] : null,
				'campaign_title'  => $campaign_map[ $row['campaign_id'] ?? 0 ] ?? '',
				'fundraiser_id'   => $row['fundraiser_id'] ? (int) $row['fundraiser_id'] : null,
				'fundraiser_name' => $fundraiser_map[ $row['fundraiser_id'] ?? 0 ] ?? '',
				'date_created'    => $row['date_created'],
			];
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Batch-fetch fundraiser display names (their donor's name) keyed by fundraiser ID.
	 *
	 * @param array<int|string> $fundraiser_ids Fundraiser IDs.
	 * @return array<int, string> Map of fundraiser ID to donor name.
	 */
	private function batch_fetch_fundraiser_names( array $fundraiser_ids ): array {
		global $wpdb;

		if ( ! $fundraiser_ids ) {
			return [];
		}

		$fundraiser_ids = array_map( 'intval', $fundraiser_ids );
		$placeholders   = implode( ', ', array_fill( 0, count( $fundraiser_ids ), '%d' ) );
		$sql            = "SELECT f.id, d.first_name, d.last_name
			 FROM %i AS f
			 LEFT JOIN %i AS d ON f.donor_id = d.id
			 WHERE f.id IN ( {$placeholders} )";
		$prepare_args   = array_merge(
			[ $wpdb->prefix . 'missiondp_fundraisers', $wpdb->prefix . 'missiondp_donors' ],
			$fundraiser_ids
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tables via %i, ids via %d placeholders built from a counted array.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

		$map = [];
		foreach ( $rows ?: [] as $row ) {
			$map[ (int) $row['id'] ] = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
		}

		return $map;
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
		$direction       = 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';

		$is_test = (int) $this->is_test_mode();

		$has_status   = ! empty( $args['status'] ) ? 1 : 0;
		$status       = $has_status ? (string) $args['status'] : '';
		$has_campaign = ! empty( $args['campaign_id'] ) ? 1 : 0;
		$campaign_id  = $has_campaign ? (int) $args['campaign_id'] : 0;
		$has_donor    = ! empty( $args['donor_id'] ) ? 1 : 0;
		$donor_id     = $has_donor ? (int) $args['donor_id'] : 0;

		$search_clause = SearchClauseBuilder::build_like_clause(
			(string) ( $args['search'] ?? '' ),
			[ 'd.first_name', 'd.last_name', 'd.email', 's.gateway_subscription_id' ]
		);
		if ( $search_clause ) {
			$search_params    = $search_clause['params'];
			$search_where_sql = ' AND ' . $search_clause['sql'];
		} else {
			$search_params    = [];
			$search_where_sql = '';
		}

		$offset = ( $page - 1 ) * $per_page;

		$where = 'WHERE s.is_test = %d
				   AND ( %d = 0 OR s.status = %s )
				   AND ( %d = 0 OR s.campaign_id = %d )
				   AND ( %d = 0 OR s.donor_id = %d )' . $search_where_sql;

		$where_args = array_merge(
			[ $is_test, $has_status, $status, $has_campaign, $campaign_id, $has_donor, $donor_id ],
			$search_params
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i AS s
				 LEFT JOIN %i AS d ON s.donor_id = d.id
				 ' . $where,
				array_merge( [ $sub_table, $donor_table ], $where_args )
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.*, d.first_name AS donor_first_name, d.last_name AS donor_last_name, d.email AS donor_email
				 FROM %i AS s
				 LEFT JOIN %i AS d ON s.donor_id = d.id
				 ' . $where . "
				 ORDER BY s.%i {$direction}
				 LIMIT %d OFFSET %d",
				array_merge(
					[ $sub_table, $donor_table ],
					$where_args,
					[ $orderby, $per_page, $offset ]
				)
			),
			ARRAY_A
		);

		$campaign_ids = array_unique( array_filter( array_column( $rows ?: [], 'campaign_id' ) ) );
		$campaign_map = [];
		if ( $campaign_ids ) {
			$campaign_ids = array_map( 'intval', $campaign_ids );
			$placeholders = implode( ', ', array_fill( 0, count( $campaign_ids ), '%d' ) );
			$sql          = "SELECT id, title FROM %i WHERE id IN ( {$placeholders} )";
			$prepare_args = array_merge( [ $wpdb->prefix . 'missiondp_campaigns' ], $campaign_ids );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
			$campaigns = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );
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

		$mrr = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(CASE frequency
					WHEN \'weekly\' THEN amount * 52 / 12
					WHEN \'monthly\' THEN amount
					WHEN \'quarterly\' THEN amount / 3
					WHEN \'annually\' THEN amount / 12
					ELSE amount
				END), 0)
				FROM %i
				WHERE status = \'active\' AND is_test = %d',
				$table,
				$is_test
			)
		);

		$prev_mrr = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(CASE frequency
					WHEN \'weekly\' THEN amount * 52 / 12
					WHEN \'monthly\' THEN amount
					WHEN \'quarterly\' THEN amount / 3
					WHEN \'annually\' THEN amount / 12
					ELSE amount
				END), 0)
				FROM %i
				WHERE is_test = %d
					AND date_created < %s
					AND (status = \'active\' OR (status = \'cancelled\' AND date_cancelled >= %s))',
				$table,
				$is_test,
				$month_start,
				$month_start
			)
		);

		$active = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = \'active\' AND is_test = %d',
				$table,
				$is_test
			)
		);

		$new_this_month = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				WHERE status = \'active\' AND is_test = %d AND date_created >= %s',
				$table,
				$is_test,
				$month_start
			)
		);

		$churned = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				WHERE status = \'cancelled\' AND is_test = %d AND date_cancelled >= %s',
				$table,
				$is_test,
				$month_start
			)
		);

		$churned_mrr = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(CASE frequency
					WHEN \'weekly\' THEN amount * 52 / 12
					WHEN \'monthly\' THEN amount
					WHEN \'quarterly\' THEN amount / 3
					WHEN \'annually\' THEN amount / 12
					ELSE amount
				END), 0)
				FROM %i
				WHERE status = \'cancelled\' AND is_test = %d AND date_cancelled >= %s',
				$table,
				$is_test,
				$month_start
			)
		);

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
		$donor_ids     = array_map( 'intval', $donor_ids );
		$placeholders  = implode( ', ', array_fill( 0, count( $donor_ids ), '%d' ) );

		$sql = "SELECT sub.donor_id, tr.tribute_type, tr.honoree_name
				 FROM (
					 SELECT donor_id, MAX(id) AS max_txn_id
					 FROM %i
					 WHERE donor_id IN ( {$placeholders} )
						AND campaign_id = %d
						AND status = 'completed'
						AND is_test = %d
					 GROUP BY donor_id
				 ) AS sub
				 INNER JOIN %i AS tr ON tr.transaction_id = sub.max_txn_id";

		$prepare_args = array_merge( [ $txn_table ], $donor_ids, [ $campaign_id, $is_test, $tribute_table ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

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

		$tribute_table   = $wpdb->prefix . 'missiondp_tributes';
		$transaction_ids = array_map( 'intval', $transaction_ids );
		$placeholders    = implode( ', ', array_fill( 0, count( $transaction_ids ), '%d' ) );
		$sql             = "SELECT transaction_id, tribute_type, honoree_name FROM %i WHERE transaction_id IN ( {$placeholders} )";
		$prepare_args    = array_merge( [ $tribute_table ], $transaction_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

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

		$allowed_orderby = [ 'date_completed', 'amount' ];
		$orderby         = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'date_completed';
		$order_asc       = 'ASC' === strtoupper( $order );

		$is_test      = (int) $this->is_test_mode();
		$has_campaign = $campaign_id > 0 ? 1 : 0;
		$exclude_anon = $show_anonymous ? 0 : 1;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i AS t
				 WHERE t.status = \'completed\'
				   AND t.is_test = %d
				   AND ( %d = 0 OR t.campaign_id = %d )
				   AND ( %d = 0 OR t.is_anonymous = 0 )',
				$txn_table,
				$is_test,
				$has_campaign,
				$campaign_id,
				$exclude_anon
			)
		);

		if ( $order_asc ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT t.id AS transaction_id, t.amount, t.type, t.is_anonymous,
						t.date_completed, t.currency, d.first_name, d.last_name, d.email
					 FROM %i AS t
					 INNER JOIN %i AS d ON t.donor_id = d.id
					 WHERE t.status = \'completed\'
					   AND t.is_test = %d
					   AND ( %d = 0 OR t.campaign_id = %d )
					   AND ( %d = 0 OR t.is_anonymous = 0 )
					 ORDER BY t.%i ASC
					 LIMIT %d OFFSET %d',
					$txn_table,
					$donor_table,
					$is_test,
					$has_campaign,
					$campaign_id,
					$exclude_anon,
					$orderby,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT t.id AS transaction_id, t.amount, t.type, t.is_anonymous,
						t.date_completed, t.currency, d.first_name, d.last_name, d.email
					 FROM %i AS t
					 INNER JOIN %i AS d ON t.donor_id = d.id
					 WHERE t.status = \'completed\'
					   AND t.is_test = %d
					   AND ( %d = 0 OR t.campaign_id = %d )
					   AND ( %d = 0 OR t.is_anonymous = 0 )
					 ORDER BY t.%i DESC
					 LIMIT %d OFFSET %d',
					$txn_table,
					$donor_table,
					$is_test,
					$has_campaign,
					$campaign_id,
					$exclude_anon,
					$orderby,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		if ( ! $rows ) {
			return [
				'items' => [],
				'total' => $total,
			];
		}

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

		$meta_table      = $wpdb->prefix . 'missiondp_transactionmeta';
		$transaction_ids = array_map( 'intval', $transaction_ids );
		$placeholders    = implode( ', ', array_fill( 0, count( $transaction_ids ), '%d' ) );
		$sql             = "SELECT missiondp_transaction_id, meta_value FROM %i WHERE missiondp_transaction_id IN ( {$placeholders} ) AND meta_key = %s";
		$prepare_args    = array_merge( [ $meta_table ], $transaction_ids, [ 'donor_comment' ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

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

	/**
	 * Get fundraisers with participant, campaign, and team names for listings.
	 *
	 * @param array<string, mixed> $args Query args: campaign_id, team_id, status, search, orderby, order, per_page, page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function fundraisers_with_relations( array $args = [] ): array {
		global $wpdb;

		$f_table = $wpdb->prefix . 'missiondp_fundraisers';
		$d_table = $wpdb->prefix . 'missiondp_donors';
		$c_table = $wpdb->prefix . 'missiondp_campaigns';
		$t_table = $wpdb->prefix . 'missiondp_teams';

		$raised_col = $this->is_test_mode() ? 'test_total_raised' : 'total_raised';
		$dcount_col = $this->is_test_mode() ? 'test_donor_count' : 'donor_count';
		$tcount_col = $this->is_test_mode() ? 'test_transaction_count' : 'transaction_count';

		$per_page = (int) ( $args['per_page'] ?? 25 );
		$page     = (int) ( $args['page'] ?? 1 );
		$offset   = ( $page - 1 ) * $per_page;

		$allowed_orderby = [ 'date_created', 'goal', 'status', 'total_raised' ];
		$orderby_in      = 'raised' === ( $args['orderby'] ?? '' ) ? 'total_raised' : ( $args['orderby'] ?? '' );
		$orderby         = in_array( $orderby_in, $allowed_orderby, true ) ? $orderby_in : 'date_created';
		if ( 'total_raised' === $orderby ) {
			$orderby = $raised_col;
		}
		$direction = 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';

		$has_campaign = ! empty( $args['campaign_id'] ) ? 1 : 0;
		$campaign_id  = (int) ( $args['campaign_id'] ?? 0 );
		$has_team     = ! empty( $args['team_id'] ) ? 1 : 0;
		$team_id      = (int) ( $args['team_id'] ?? 0 );
		$has_status   = ! empty( $args['status'] ) ? 1 : 0;
		$status       = (string) ( $args['status'] ?? '' );

		$search_clause = SearchClauseBuilder::build_like_clause(
			(string) ( $args['search'] ?? '' ),
			[ 'd.first_name', 'd.last_name', 'd.email', 'f.headline' ]
		);
		if ( $search_clause ) {
			$search_where_sql = ' AND ( ' . $search_clause['sql'] . ' )';
			$search_params    = $search_clause['params'];
		} else {
			$search_where_sql = '';
			$search_params    = [];
		}

		$where = 'WHERE ( %d = 0 OR f.campaign_id = %d )
				   AND ( %d = 0 OR f.team_id = %d )
				   AND ( %d = 0 OR f.status = %s )' . $search_where_sql;

		$where_args = array_merge(
			[ $has_campaign, $campaign_id, $has_team, $team_id, $has_status, $status ],
			$search_params
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i AS f
				 LEFT JOIN %i AS d ON f.donor_id = d.id ' . $where,
				array_merge( [ $f_table, $d_table ], $where_args )
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT f.id, f.campaign_id, f.team_id, f.goal, f.status, f.is_team_captain, f.date_created,
						f.%i AS raised, f.%i AS donor_count, f.%i AS transaction_count,
						d.first_name AS donor_first_name, d.last_name AS donor_last_name, d.email AS donor_email,
						c.title AS campaign_title, tm.name AS team_name
				 FROM %i AS f
				 LEFT JOIN %i AS d ON f.donor_id = d.id
				 LEFT JOIN %i AS c ON f.campaign_id = c.id
				 LEFT JOIN %i AS tm ON f.team_id = tm.id
				 ' . $where . "
				 ORDER BY f.%i {$direction}
				 LIMIT %d OFFSET %d",
				array_merge(
					[ $raised_col, $dcount_col, $tcount_col, $f_table, $d_table, $c_table, $t_table ],
					$where_args,
					[ $orderby, $per_page, $offset ]
				)
			),
			ARRAY_A
		);

		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$donor_name = trim( ( $row['donor_first_name'] ?? '' ) . ' ' . ( $row['donor_last_name'] ?? '' ) );

			$items[] = [
				'id'                => (int) $row['id'],
				'donor_name'        => $donor_name ?: __( 'Anonymous', 'mission-donation-platform' ),
				'donor_email'       => $row['donor_email'] ?? '',
				'campaign_id'       => $row['campaign_id'] ? (int) $row['campaign_id'] : null,
				'campaign_title'    => $row['campaign_title'] ?? '',
				'team_id'           => $row['team_id'] ? (int) $row['team_id'] : null,
				'team_name'         => $row['team_name'] ?? '',
				'goal'              => (int) $row['goal'],
				'raised'            => (int) $row['raised'],
				'donor_count'       => (int) $row['donor_count'],
				'transaction_count' => (int) $row['transaction_count'],
				'status'            => $row['status'],
				'is_team_captain'   => (bool) (int) $row['is_team_captain'],
				'date_created'      => $row['date_created'],
			];
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Get teams with campaign title, captain name, member count, and raised totals.
	 *
	 * Member count and raised use correlated subqueries (portable, no GROUP BY)
	 * so the join to the campaigns/captain tables never multiplies rows.
	 *
	 * @param array<string, mixed> $args Query args: campaign_id, status, search, orderby, order, per_page, page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function teams_with_relations( array $args = [] ): array {
		global $wpdb;

		$t_table = $wpdb->prefix . 'missiondp_teams';
		$c_table = $wpdb->prefix . 'missiondp_campaigns';
		$f_table = $wpdb->prefix . 'missiondp_fundraisers';
		$d_table = $wpdb->prefix . 'missiondp_donors';

		$raised_col = $this->is_test_mode() ? 'test_total_raised' : 'total_raised';

		$per_page = (int) ( $args['per_page'] ?? 25 );
		$page     = (int) ( $args['page'] ?? 1 );
		$offset   = ( $page - 1 ) * $per_page;

		$allowed_orderby = [ 'date_created', 'name', 'goal', 'status' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$direction       = 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';

		$has_campaign = ! empty( $args['campaign_id'] ) ? 1 : 0;
		$campaign_id  = (int) ( $args['campaign_id'] ?? 0 );
		$has_status   = ! empty( $args['status'] ) ? 1 : 0;
		$status       = (string) ( $args['status'] ?? '' );

		$search_clause = SearchClauseBuilder::build_like_clause(
			(string) ( $args['search'] ?? '' ),
			[ 't.name' ]
		);
		if ( $search_clause ) {
			$search_where_sql = ' AND ( ' . $search_clause['sql'] . ' )';
			$search_params    = $search_clause['params'];
		} else {
			$search_where_sql = '';
			$search_params    = [];
		}

		$where = 'WHERE ( %d = 0 OR t.campaign_id = %d )
				   AND ( %d = 0 OR t.status = %s )' . $search_where_sql;

		$where_args = array_merge(
			[ $has_campaign, $campaign_id, $has_status, $status ],
			$search_params
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i AS t ' . $where,
				array_merge( [ $t_table ], $where_args )
			)
		);

		$tx_table = $wpdb->prefix . 'missiondp_transactions';
		$is_test  = $this->is_test_mode() ? 1 : 0;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.campaign_id, t.name, t.goal, t.status, t.access, t.date_created,
						c.title AS campaign_title,
						cd.first_name AS captain_first_name, cd.last_name AS captain_last_name,
						( SELECT COUNT(*) FROM %i AS m WHERE m.team_id = t.id ) AS member_count,
						( SELECT COALESCE(SUM(m2.%i), 0) FROM %i AS m2 WHERE m2.team_id = t.id )
						+ ( SELECT COALESCE(SUM(tx.amount - LEAST(tx.amount_refunded, tx.amount)), 0)
							FROM %i AS tx WHERE tx.team_id = t.id AND tx.status = 'completed' AND tx.is_test = %d ) AS raised
				 FROM %i AS t
				 LEFT JOIN %i AS c ON t.campaign_id = c.id
				 LEFT JOIN %i AS cap ON t.captain_id = cap.id
				 LEFT JOIN %i AS cd ON cap.donor_id = cd.id
				 " . $where . "
				 ORDER BY t.%i {$direction}
				 LIMIT %d OFFSET %d",
				array_merge(
					[ $f_table, $raised_col, $f_table, $tx_table, $is_test, $t_table, $c_table, $f_table, $d_table ],
					$where_args,
					[ $orderby, $per_page, $offset ]
				)
			),
			ARRAY_A
		);

		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$captain_name = trim( ( $row['captain_first_name'] ?? '' ) . ' ' . ( $row['captain_last_name'] ?? '' ) );

			$items[] = [
				'id'             => (int) $row['id'],
				'name'           => $row['name'],
				'campaign_id'    => $row['campaign_id'] ? (int) $row['campaign_id'] : null,
				'campaign_title' => $row['campaign_title'] ?? '',
				'captain_name'   => $captain_name,
				'member_count'   => (int) $row['member_count'],
				'goal'           => (int) $row['goal'],
				'raised'         => (int) $row['raised'],
				'status'         => $row['status'],
				'access'         => $row['access'],
				'date_created'   => $row['date_created'],
			];
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Aggregate stats for the Fundraisers admin list.
	 *
	 * @return array{total_fundraisers: int, active_count: int, pending_count: int, total_raised: int}
	 */
	public function fundraiser_summary(): array {
		global $wpdb;

		$table      = $wpdb->prefix . 'missiondp_fundraisers';
		$raised_col = $this->is_test_mode() ? 'test_total_raised' : 'total_raised';

		$total   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$active  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, \MissionDP\Models\Fundraiser::STATUS_ACTIVE ) );
		$pending = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, \MissionDP\Models\Fundraiser::STATUS_PENDING ) );
		$raised  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(%i), 0) FROM %i', $raised_col, $table ) );

		return [
			'total_fundraisers' => $total,
			'active_count'      => $active,
			'pending_count'     => $pending,
			'total_raised'      => $raised,
		];
	}

	/**
	 * Completed donations attributed to a single fundraiser.
	 *
	 * Powers the dashboard "My Donors" and "Activity" sections: the people who
	 * gave through this fundraiser's page, newest first. Test/live scoped by the
	 * current mode so a fundraiser sees totals matching their dashboard cards.
	 *
	 * @param int $fundraiser_id Fundraiser ID.
	 * @param int $per_page      Results per page.
	 * @param int $page          Page number (1-based).
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function fundraiser_donations_query( int $fundraiser_id, int $per_page = 10, int $page = 1 ): array {
		global $wpdb;

		$txn_table   = $wpdb->prefix . 'missiondp_transactions';
		$donor_table = $wpdb->prefix . 'missiondp_donors';
		$offset      = ( max( 1, $page ) - 1 ) * $per_page;
		$is_test     = (int) $this->is_test_mode();

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i AS t
				 WHERE t.fundraiser_id = %d AND t.status = \'completed\' AND t.is_test = %d',
				$txn_table,
				$fundraiser_id,
				$is_test
			)
		);

		if ( 0 === $total ) {
			return [
				'items' => [],
				'total' => 0,
			];
		}

		// LEFT JOIN so rows match the un-joined COUNT above even if a donor row
		// was deleted; consumers render the missing name as "Anonymous".
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.id AS transaction_id, t.amount, t.type, t.is_anonymous,
						t.date_completed, t.currency, d.first_name, d.last_name
				 FROM %i AS t
				 LEFT JOIN %i AS d ON t.donor_id = d.id
				 WHERE t.fundraiser_id = %d AND t.status = \'completed\' AND t.is_test = %d
				 ORDER BY t.date_completed DESC
				 LIMIT %d OFFSET %d',
				$txn_table,
				$donor_table,
				$fundraiser_id,
				$is_test,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$txn_ids     = array_column( $rows ?: [], 'transaction_id' );
		$comment_map = $txn_ids ? $this->batch_fetch_donor_comments( $txn_ids ) : [];

		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$txn_id  = (int) $row['transaction_id'];
			$items[] = [
				'first_name'   => $row['first_name'],
				'last_name'    => $row['last_name'],
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
	 * Recent completed donations across all of a donor's fundraiser pages.
	 *
	 * Powers the fundraiser-persona dashboard Overview: the latest gifts made
	 * through any page this donor owns, newest first, test/live scoped by the
	 * current mode.
	 *
	 * @param int $donor_id Owning donor ID.
	 * @param int $limit    Maximum rows.
	 * @return array<int, array{first_name:string, last_name:string, is_anonymous:bool, amount:int, currency:string, date:string, fundraiser_id:int, headline:string}>
	 */
	public function donor_pages_recent_donations( int $donor_id, int $limit = 5 ): array {
		global $wpdb;

		$txn_table   = $wpdb->prefix . 'missiondp_transactions';
		$f_table     = $wpdb->prefix . 'missiondp_fundraisers';
		$donor_table = $wpdb->prefix . 'missiondp_donors';
		$is_test     = (int) $this->is_test_mode();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.amount, t.currency, t.is_anonymous, t.date_completed,
						d.first_name, d.last_name, f.id AS fundraiser_id, f.headline
				 FROM %i AS t
				 INNER JOIN %i AS f ON t.fundraiser_id = f.id
				 INNER JOIN %i AS d ON t.donor_id = d.id
				 WHERE f.donor_id = %d AND t.status = \'completed\' AND t.is_test = %d
				 ORDER BY t.date_completed DESC
				 LIMIT %d',
				$txn_table,
				$f_table,
				$donor_table,
				$donor_id,
				$is_test,
				max( 1, $limit )
			),
			ARRAY_A
		);

		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$items[] = [
				'first_name'    => $row['first_name'],
				'last_name'     => $row['last_name'],
				'is_anonymous'  => (bool) $row['is_anonymous'],
				'amount'        => (int) $row['amount'],
				'currency'      => $row['currency'],
				'date'          => $row['date_completed'],
				'fundraiser_id' => (int) $row['fundraiser_id'],
				'headline'      => $row['headline'] ?? '',
			];
		}

		return $items;
	}

	/**
	 * Aggregate stats for the Teams admin list.
	 *
	 * @return array{total_teams: int, active_count: int, pending_count: int, total_raised: int}
	 */
	public function team_summary(): array {
		global $wpdb;

		$t_table    = $wpdb->prefix . 'missiondp_teams';
		$f_table    = $wpdb->prefix . 'missiondp_fundraisers';
		$tx_table   = $wpdb->prefix . 'missiondp_transactions';
		$raised_col = $this->is_test_mode() ? 'test_total_raised' : 'total_raised';
		$is_test    = $this->is_test_mode() ? 1 : 0;

		$total   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t_table ) );
		$active  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $t_table, \MissionDP\Models\Team::STATUS_ACTIVE ) );
		$pending = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $t_table, \MissionDP\Models\Team::STATUS_PENDING ) );
		$raised  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ( SELECT COALESCE(SUM(%i), 0) FROM %i WHERE team_id IS NOT NULL )
					+ ( SELECT COALESCE(SUM(amount - LEAST(amount_refunded, amount)), 0)
						FROM %i WHERE team_id IS NOT NULL AND status = 'completed' AND is_test = %d )",
				$raised_col,
				$f_table,
				$tx_table,
				$is_test
			)
		);

		return [
			'total_teams'   => $total,
			'active_count'  => $active,
			'pending_count' => $pending,
			'total_raised'  => $raised,
		];
	}

	/**
	 * Top active fundraisers in a campaign, ordered by amount raised.
	 *
	 * For the campaign-page leaderboard. Returns post_id so the caller can build
	 * the page URL without an N+1 model lookup.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $limit       Maximum rows.
	 * @return array<int, array{id:int, post_id:int, name:string, team_name:string, goal:int, raised:int, is_captain:bool}>
	 */
	public function top_fundraisers( int $campaign_id, int $limit = 10 ): array {
		global $wpdb;

		$f_table = $wpdb->prefix . 'missiondp_fundraisers';
		$d_table = $wpdb->prefix . 'missiondp_donors';
		$t_table = $wpdb->prefix . 'missiondp_teams';

		$raised_col = $this->is_test_mode() ? 'test_total_raised' : 'total_raised';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT f.id, f.post_id, f.goal, f.is_team_captain, f.%i AS raised,
						d.first_name, d.last_name, tm.name AS team_name
				 FROM %i AS f
				 LEFT JOIN %i AS d ON f.donor_id = d.id
				 LEFT JOIN %i AS tm ON f.team_id = tm.id
				 WHERE f.campaign_id = %d AND f.status = %s
				 ORDER BY raised DESC, f.id ASC
				 LIMIT %d',
				$raised_col,
				$f_table,
				$d_table,
				$t_table,
				$campaign_id,
				\MissionDP\Models\Fundraiser::STATUS_ACTIVE,
				$limit
			),
			ARRAY_A
		);

		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$name = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );

			$items[] = [
				'id'         => (int) $row['id'],
				'post_id'    => (int) $row['post_id'],
				'name'       => $name ?: __( 'Fundraiser', 'mission-donation-platform' ),
				'team_name'  => $row['team_name'] ?? '',
				'goal'       => (int) $row['goal'],
				'raised'     => (int) $row['raised'],
				'is_captain' => (bool) (int) $row['is_team_captain'],
			];
		}

		return $items;
	}

	/**
	 * Top active teams in a campaign, ordered by amount raised.
	 *
	 * Raised is summed live: member fundraisers' totals plus any gifts made
	 * directly to the team. Refunds are netted on the direct gifts.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $limit       Maximum rows.
	 * @return array<int, array{id:int, post_id:int, name:string, goal:int, raised:int, member_count:int}>
	 */
	public function top_teams( int $campaign_id, int $limit = 10 ): array {
		global $wpdb;

		$t_table  = $wpdb->prefix . 'missiondp_teams';
		$f_table  = $wpdb->prefix . 'missiondp_fundraisers';
		$tx_table = $wpdb->prefix . 'missiondp_transactions';

		$raised_col = $this->is_test_mode() ? 'test_total_raised' : 'total_raised';
		$is_test    = $this->is_test_mode() ? 1 : 0;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.post_id, t.name, t.goal,
						( SELECT COALESCE(SUM(m.%i), 0) FROM %i AS m WHERE m.team_id = t.id )
						+ ( SELECT COALESCE(SUM(tx.amount - LEAST(tx.amount_refunded, tx.amount)), 0)
							FROM %i AS tx WHERE tx.team_id = t.id AND tx.status = 'completed' AND tx.is_test = %d ) AS raised,
						( SELECT COUNT(*) FROM %i AS m2 WHERE m2.team_id = t.id ) AS member_count
				 FROM %i AS t
				 WHERE t.campaign_id = %d AND t.status = %s AND t.access = %s
				 ORDER BY raised DESC, t.id ASC
				 LIMIT %d",
				$raised_col,
				$f_table,
				$tx_table,
				$is_test,
				$f_table,
				$t_table,
				$campaign_id,
				\MissionDP\Models\Team::STATUS_ACTIVE,
				\MissionDP\Models\Team::ACCESS_PUBLIC,
				$limit
			),
			ARRAY_A
		);

		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$items[] = [
				'id'           => (int) $row['id'],
				'post_id'      => (int) $row['post_id'],
				'name'         => $row['name'],
				'goal'         => (int) $row['goal'],
				'raised'       => (int) $row['raised'],
				'member_count' => (int) $row['member_count'],
			];
		}

		return $items;
	}

	/**
	 * Live totals for a single team page (member fundraisers + direct gifts).
	 *
	 * Memoized per instance: dashboard context builders resolve the same
	 * team's totals several times in one request.
	 *
	 * @param int $team_id Team ID.
	 * @return array{raised:int, donations:int, member_count:int}
	 */
	public function team_totals( int $team_id ): array {
		global $wpdb;

		$f_table  = $wpdb->prefix . 'missiondp_fundraisers';
		$tx_table = $wpdb->prefix . 'missiondp_transactions';

		$raised_col = $this->is_test_mode() ? 'test_total_raised' : 'total_raised';
		$tcount_col = $this->is_test_mode() ? 'test_transaction_count' : 'transaction_count';
		$is_test    = $this->is_test_mode() ? 1 : 0;

		$memo_key = $team_id . ':' . $is_test;

		if ( isset( $this->team_totals_memo[ $memo_key ] ) ) {
			return $this->team_totals_memo[ $memo_key ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					( SELECT COALESCE(SUM(m.%i), 0) FROM %i AS m WHERE m.team_id = %d )
					+ ( SELECT COALESCE(SUM(tx.amount - LEAST(tx.amount_refunded, tx.amount)), 0)
						FROM %i AS tx WHERE tx.team_id = %d AND tx.status = 'completed' AND tx.is_test = %d ) AS raised,
					( SELECT COALESCE(SUM(m2.%i), 0) FROM %i AS m2 WHERE m2.team_id = %d )
					+ ( SELECT COUNT(*) FROM %i AS tx2 WHERE tx2.team_id = %d AND tx2.status = 'completed' AND tx2.is_test = %d ) AS donations,
					( SELECT COUNT(*) FROM %i AS m3 WHERE m3.team_id = %d ) AS member_count",
				$raised_col,
				$f_table,
				$team_id,
				$tx_table,
				$team_id,
				$is_test,
				$tcount_col,
				$f_table,
				$team_id,
				$tx_table,
				$team_id,
				$is_test,
				$f_table,
				$team_id
			),
			ARRAY_A
		);

		$this->team_totals_memo[ $memo_key ] = [
			'raised'       => (int) ( $row['raised'] ?? 0 ),
			'donations'    => (int) ( $row['donations'] ?? 0 ),
			'member_count' => (int) ( $row['member_count'] ?? 0 ),
		];

		return $this->team_totals_memo[ $memo_key ];
	}

	/**
	 * A team's leaderboard rank among the active teams in its campaign.
	 *
	 * Competition ranking on amount raised (member fundraisers plus direct team
	 * gifts): rank = 1 + the number of active teams that raised strictly more.
	 * Private teams count — this powers the owner-facing dashboard, not the
	 * public leaderboard.
	 *
	 * @param int $team_id Team ID.
	 * @return array{rank: int, total: int} Zeroes when the team does not exist.
	 */
	public function team_rank( int $team_id ): array {
		global $wpdb;

		$t_table  = $wpdb->prefix . 'missiondp_teams';
		$f_table  = $wpdb->prefix . 'missiondp_fundraisers';
		$tx_table = $wpdb->prefix . 'missiondp_transactions';

		$raised_col = $this->is_test_mode() ? 'test_total_raised' : 'total_raised';
		$is_test    = $this->is_test_mode() ? 1 : 0;

		$team = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.campaign_id,
					( SELECT COALESCE(SUM(m.%i), 0) FROM %i AS m WHERE m.team_id = t.id )
					+ ( SELECT COALESCE(SUM(tx.amount - LEAST(tx.amount_refunded, tx.amount)), 0)
						FROM %i AS tx WHERE tx.team_id = t.id AND tx.status = 'completed' AND tx.is_test = %d ) AS raised
				 FROM %i AS t
				 WHERE t.id = %d",
				$raised_col,
				$f_table,
				$tx_table,
				$is_test,
				$t_table,
				$team_id
			),
			ARRAY_A
		);

		if ( ! $team ) {
			return [
				'rank'  => 0,
				'total' => 0,
			];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
					COALESCE(SUM(CASE WHEN
						( SELECT COALESCE(SUM(m.%i), 0) FROM %i AS m WHERE m.team_id = t.id )
						+ ( SELECT COALESCE(SUM(tx.amount - LEAST(tx.amount_refunded, tx.amount)), 0)
							FROM %i AS tx WHERE tx.team_id = t.id AND tx.status = 'completed' AND tx.is_test = %d )
						> %d THEN 1 ELSE 0 END), 0) AS higher
				 FROM %i AS t
				 WHERE t.campaign_id = %d AND t.status = %s",
				$raised_col,
				$f_table,
				$tx_table,
				$is_test,
				(int) $team['raised'],
				$t_table,
				(int) $team['campaign_id'],
				\MissionDP\Models\Team::STATUS_ACTIVE
			),
			ARRAY_A
		);

		return [
			'rank'  => 1 + (int) ( $row['higher'] ?? 0 ),
			'total' => (int) ( $row['total'] ?? 0 ),
		];
	}

	/**
	 * A team's member fundraisers with per-member progress, ordered by raised.
	 *
	 * @param int $team_id Team ID.
	 * @param int $limit   Maximum members to return.
	 * @return array<int, array{id:int, post_id:int, donor_id:int, name:string, first_name:string, last_name:string, goal:int, raised:int, is_captain:bool}>
	 */
	public function team_members( int $team_id, int $limit = 100 ): array {
		global $wpdb;

		$f_table = $wpdb->prefix . 'missiondp_fundraisers';
		$d_table = $wpdb->prefix . 'missiondp_donors';

		$raised_col = $this->is_test_mode() ? 'test_total_raised' : 'total_raised';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT f.id, f.post_id, f.donor_id, f.goal, f.is_team_captain, f.%i AS raised,
						d.first_name, d.last_name
				 FROM %i AS f
				 LEFT JOIN %i AS d ON f.donor_id = d.id
				 WHERE f.team_id = %d AND f.status = %s
				 ORDER BY raised DESC, f.id ASC
				 LIMIT %d',
				$raised_col,
				$f_table,
				$d_table,
				$team_id,
				\MissionDP\Models\Fundraiser::STATUS_ACTIVE,
				max( 1, $limit )
			),
			ARRAY_A
		);

		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$name = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );

			$items[] = [
				'id'         => (int) $row['id'],
				'post_id'    => (int) $row['post_id'],
				'donor_id'   => (int) $row['donor_id'],
				'name'       => $name ?: __( 'Fundraiser', 'mission-donation-platform' ),
				'first_name' => (string) ( $row['first_name'] ?? '' ),
				'last_name'  => (string) ( $row['last_name'] ?? '' ),
				'goal'       => (int) $row['goal'],
				'raised'     => (int) $row['raised'],
				'is_captain' => (bool) (int) $row['is_team_captain'],
			];
		}

		return $items;
	}
}
