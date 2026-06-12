<?php
/**
 * Reads GiveWP recurring subscriptions.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Migrators\GiveWP\Readers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reads a foreign plugin's tables; no higher-level API exists.

use MissionDP\Migration\AmountConverter;
use MissionDP\Migration\Migrators\GiveWP\GiveWPSource;
use MissionDP\Migration\Migrators\GiveWP\Maps\FrequencyMap;
use MissionDP\Migration\Migrators\GiveWP\Maps\GatewayMap;
use MissionDP\Migration\Migrators\GiveWP\Maps\StatusMap;

defined( 'ABSPATH' ) || exit;

/**
 * Reads wp_give_subscriptions. Gateway/currency details live on the parent
 * (signup) payment's donation meta, loaded per batch. Newer GiveWP versions
 * add payment_mode and campaign_id columns; both are optional here.
 */
class SubscriptionReader {

	private const PARENT_META_KEYS = [
		'_give_payment_currency',
		'_give_payment_gateway',
		'_give_payment_mode',
		'_give_stripe_customer_id',
	];

	/**
	 * Cached column presence for the subscriptions table.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $columns = null;

	/**
	 * Constructor.
	 *
	 * @param GiveWPSource $source   Source DB access.
	 * @param string       $currency Site currency fallback.
	 */
	public function __construct(
		private GiveWPSource $source,
		private string $currency,
	) {}

	/**
	 * Count migratable subscriptions.
	 *
	 * @param array<string, mixed> $options Migration options.
	 */
	public function count( array $options ): int {
		global $wpdb;

		if ( ! $this->source->table_exists( 'subscriptions' ) ) {
			return 0;
		}

		[ $where_sql, $where_params ] = $this->build_where( $options );

		$sql = 'SELECT COUNT(*) FROM %i s WHERE ' . $where_sql;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- where clause is built from static SQL with placeholders only.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( [ $this->source->table( 'subscriptions' ) ], $where_params ) ) );
	}

	/**
	 * Count test-mode subscriptions, regardless of options.
	 */
	public function count_test_mode(): int {
		global $wpdb;

		if ( ! $this->source->table_exists( 'subscriptions' ) ) {
			return 0;
		}

		$sql = 'SELECT COUNT(*) FROM %i s WHERE ' . $this->test_mode_condition();

		$prepare_args = array_merge( [ $this->source->table( 'subscriptions' ) ], $this->test_mode_params() );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- condition is static SQL with placeholders only.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $prepare_args ) );
	}

	/**
	 * Read the next batch of normalized subscription records.
	 *
	 * @param int                  $after_id Last processed source ID.
	 * @param int                  $limit    Max records.
	 * @param array<string, mixed> $options  Migration options.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function read_batch( int $after_id, int $limit, array $options ): array {
		global $wpdb;

		if ( ! $this->source->table_exists( 'subscriptions' ) ) {
			return [];
		}

		[ $where_sql, $where_params ] = $this->build_where( $options );

		$sql = 'SELECT s.* FROM %i s WHERE ' . $where_sql . ' AND s.id > %d ORDER BY s.id ASC LIMIT %d';

		$prepare_args = array_merge( [ $this->source->table( 'subscriptions' ) ], $where_params, [ $after_id, $limit ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- where clause is built from static SQL with placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

		if ( empty( $rows ) ) {
			return [];
		}

		$parent_ids  = array_map( static fn( array $row ): int => (int) $row['parent_payment_id'], $rows );
		$parent_meta = $this->source->donation_meta( $parent_ids, self::PARENT_META_KEYS );

		$form_campaign_map = $this->source->form_campaign_map();
		$records           = [];

		foreach ( $rows as $row ) {
			$parent_id = (int) $row['parent_payment_id'];
			$m         = $parent_meta[ $parent_id ] ?? [];

			$give_status = (string) $row['status'];
			$status      = StatusMap::subscription_status( $give_status );
			$currency    = strtolower( (string) ( $m['_give_payment_currency'] ?? '' ) ) ?: strtolower( $this->currency );
			$amount      = AmountConverter::to_minor_units( (string) $row['recurring_amount'], $currency );

			$mapping = FrequencyMap::map( (string) $row['period'], (int) $row['frequency'] );

			$is_test = isset( $row['payment_mode'] )
				? 'test' === $row['payment_mode']
				: 'test' === ( $m['_give_payment_mode'] ?? '' );

			$form_id            = (int) $row['product_id'];
			$source_campaign_id = (int) ( $row['campaign_id'] ?? 0 );
			if ( 0 === $source_campaign_id && $form_id > 0 ) {
				$source_campaign_id = $form_campaign_map[ $form_id ] ?? 0;
			}

			$record = [
				'source_id'               => (int) $row['id'],
				'status'                  => $status,
				'amount'                  => $amount,
				'total_amount'            => $amount,
				'currency'                => $currency,
				'frequency'               => $mapping['frequency'],
				'payment_gateway'         => GatewayMap::map( (string) ( $m['_give_payment_gateway'] ?? '' ) ),
				'gateway_subscription_id' => (string) $row['profile_id'] ?: null,
				'gateway_customer_id'     => (string) ( $m['_give_stripe_customer_id'] ?? '' ) ?: null,
				'is_test'                 => $is_test,
				'date_created'            => (string) $row['created'],
				'date_next_renewal'       => in_array( $status, [ 'active', 'past_due' ], true )
					? $this->normalize_date( $row['expiration'] ?? null )
					: null,
				'source_donor_id'         => (int) $row['customer_id'],
				'source_campaign_id'      => $source_campaign_id,
				'source_form_id'          => $form_id,
				'campaign_title'          => '',
				'meta'                    => [
					'_givewp_parent_payment_id' => $parent_id,
				],
				'warnings'                => [],
			];

			if ( $status !== $give_status ) {
				$record['meta']['_givewp_original_status'] = $give_status;
			}

			$bill_times = (int) $row['bill_times'];
			if ( $bill_times > 0 ) {
				$record['meta']['_givewp_bill_times'] = $bill_times;
			}

			if ( $mapping['lossy'] ) {
				$record['meta']['_givewp_billing_period'] = $row['period'] . ':' . (int) $row['frequency'];
				$record['warnings'][]                     = sprintf(
					/* translators: 1: GiveWP billing period, 2: billing frequency multiplier. */
					__( 'Billing cadence "%1$s x%2$d" is not supported and was migrated as monthly.', 'mission-donation-platform' ),
					(string) $row['period'],
					(int) $row['frequency']
				);
			}

			$records[] = $record;
		}

		return $records;
	}

	/**
	 * Build the shared WHERE clause so count() and read_batch() always agree.
	 *
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function build_where( array $options ): array {
		$where  = 's.status <> %s';
		$params = [ 'trashed' ];

		if ( empty( $options['include_test'] ) ) {
			$where .= ' AND NOT ( ' . $this->test_mode_condition() . ' )';
			$params = array_merge( $params, $this->test_mode_params() );
		}

		return [ $where, $params ];
	}

	/**
	 * SQL condition matching test-mode subscriptions.
	 *
	 * Uses the payment_mode column when present (GiveWP 4.x), otherwise falls
	 * back to the parent payment's mode meta.
	 */
	private function test_mode_condition(): string {
		if ( $this->has_column( 'payment_mode' ) ) {
			return 's.payment_mode = %s';
		}

		return 'EXISTS ( SELECT 1 FROM %i dm WHERE dm.donation_id = s.parent_payment_id AND dm.meta_key = %s AND dm.meta_value = %s )';
	}

	/**
	 * Prepare params matching test_mode_condition().
	 *
	 * @return array<int, mixed>
	 */
	private function test_mode_params(): array {
		if ( $this->has_column( 'payment_mode' ) ) {
			return [ 'test' ];
		}

		return [ $this->source->table( 'donationmeta' ), '_give_payment_mode', 'test' ];
	}

	/**
	 * Whether the subscriptions table has a column. Inspects one row instead
	 * of SHOW COLUMNS so it also works on SQLite-based WordPress.
	 *
	 * @param string $column Column name.
	 */
	private function has_column( string $column ): bool {
		global $wpdb;

		if ( null === $this->columns ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i LIMIT 1', $this->source->table( 'subscriptions' ) ),
				ARRAY_A
			);

			$this->columns = array_fill_keys( array_keys( $row ?: [] ), true );
		}

		return isset( $this->columns[ $column ] );
	}

	/**
	 * Normalize a source datetime, treating zeroed/empty dates as null.
	 *
	 * @param mixed $value Raw column value.
	 */
	private function normalize_date( mixed $value ): ?string {
		$value = (string) ( $value ?? '' );

		if ( '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}

		return $value;
	}
}
