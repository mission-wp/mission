<?php
/**
 * Reads GiveWP donations (give_payment posts).
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Migrators\GiveWP\Readers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reads a foreign plugin's tables; no higher-level API exists.

use MissionDP\Migration\AmountConverter;
use MissionDP\Migration\Migrators\GiveWP\GiveWPSource;
use MissionDP\Migration\Migrators\GiveWP\Maps\GatewayMap;
use MissionDP\Migration\Migrators\GiveWP\Maps\StatusMap;

defined( 'ABSPATH' ) || exit;

/**
 * Donations live in wp_posts (post_type give_payment) with all payment data
 * in wp_give_donationmeta. Renewal payments are child posts of the initial
 * subscription payment, so cursor order guarantees parents migrate first.
 */
class PaymentReader {

	private const META_KEYS = [
		'_give_payment_total',
		'_give_payment_currency',
		'_give_payment_gateway',
		'_give_payment_mode',
		'_give_payment_transaction_id',
		'_give_payment_donor_id',
		'_give_campaign_id',
		'_give_payment_form_id',
		'_give_payment_form_title',
		'_give_anonymous_donation',
		'_give_payment_donor_ip',
		'_give_donation_comment',
		'_give_fee_amount',
		'_give_completed_date',
		'subscription_id',
		'_give_is_donation_recurring',
	];

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
	 * Count migratable donations.
	 *
	 * @param array<string, mixed> $options Migration options.
	 */
	public function count( array $options ): int {
		global $wpdb;

		[ $where_sql, $where_params ] = $this->build_where( $options );

		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where_sql}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- where clause is built from static SQL with placeholders only.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $where_params ) );
	}

	/**
	 * Count test-mode donations, regardless of options. Used by the scan so
	 * the UI can show what the include-test checkbox adds.
	 */
	public function count_test_mode(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				WHERE p.post_type = %s AND p.post_status <> %s
				AND EXISTS ( SELECT 1 FROM %i dm WHERE dm.donation_id = p.ID AND dm.meta_key = %s AND dm.meta_value = %s )",
				'give_payment',
				'trash',
				$this->source->table( 'donationmeta' ),
				'_give_payment_mode',
				'test'
			)
		);
	}

	/**
	 * Read the next batch of normalized transaction records.
	 *
	 * @param int                  $after_id Last processed source ID.
	 * @param int                  $limit    Max records.
	 * @param array<string, mixed> $options  Migration options.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function read_batch( int $after_id, int $limit, array $options ): array {
		global $wpdb;

		[ $where_sql, $where_params ] = $this->build_where( $options );

		$sql = "SELECT p.ID, p.post_status, p.post_date_gmt, p.post_parent FROM {$wpdb->posts} p WHERE {$where_sql} AND p.ID > %d ORDER BY p.ID ASC LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- where clause is built from static SQL with placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $where_params, [ $after_id, $limit ] ) ), ARRAY_A );

		if ( empty( $rows ) ) {
			return [];
		}

		$payment_ids = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
		$meta        = $this->source->donation_meta( $payment_ids, self::META_KEYS );

		$form_campaign_map = $this->source->form_campaign_map();
		$records           = [];

		foreach ( $rows as $row ) {
			$payment_id = (int) $row['ID'];
			$m          = $meta[ $payment_id ] ?? [];

			$status   = StatusMap::payment_status( (string) $row['post_status'] );
			$currency = strtolower( (string) ( $m['_give_payment_currency'] ?? '' ) ) ?: strtolower( $this->currency );
			$amount   = AmountConverter::to_minor_units( (string) ( $m['_give_payment_total'] ?? '0' ), $currency );

			$source_subscription_id = (int) ( $m['subscription_id'] ?? 0 );
			$is_recurring           = $source_subscription_id > 0
				|| ! empty( $m['_give_is_donation_recurring'] )
				|| (int) $row['post_parent'] > 0;

			$form_id            = (int) ( $m['_give_payment_form_id'] ?? 0 );
			$source_campaign_id = (int) ( $m['_give_campaign_id'] ?? 0 );
			if ( 0 === $source_campaign_id && $form_id > 0 ) {
				$source_campaign_id = $form_campaign_map[ $form_id ] ?? 0;
			}

			$record = [
				'source_id'              => $payment_id,
				'status'                 => $status,
				'type'                   => $is_recurring ? 'recurring' : 'one_time',
				'amount'                 => $amount,
				'total_amount'           => $amount,
				'currency'               => $currency,
				'payment_gateway'        => GatewayMap::map( (string) ( $m['_give_payment_gateway'] ?? '' ) ),
				'gateway_transaction_id' => (string) ( $m['_give_payment_transaction_id'] ?? '' ) ?: null,
				'is_test'                => 'test' === ( $m['_give_payment_mode'] ?? '' ),
				'is_anonymous'           => ! empty( $m['_give_anonymous_donation'] ),
				'donor_ip'               => (string) ( $m['_give_payment_donor_ip'] ?? '' ),
				'date_created'           => (string) $row['post_date_gmt'],
				'date_completed'         => 'completed' === $status
					? ( (string) ( $m['_give_completed_date'] ?? '' ) ?: (string) $row['post_date_gmt'] )
					: null,
				'source_donor_id'        => (int) ( $m['_give_payment_donor_id'] ?? 0 ),
				'source_campaign_id'     => $source_campaign_id,
				'source_form_id'         => $form_id,
				'campaign_title'         => (string) ( $m['_give_payment_form_title'] ?? '' ),
				'source_subscription_id' => $source_subscription_id,
				'source_parent_id'       => (int) $row['post_parent'],
				'meta'                   => [],
			];

			$comment = (string) ( $m['_give_donation_comment'] ?? '' );
			if ( '' !== $comment ) {
				$record['meta']['donor_comment'] = $comment;
			}

			$fee = (string) ( $m['_give_fee_amount'] ?? '' );
			if ( '' !== $fee ) {
				$record['meta']['_givewp_fee_amount'] = $fee;
			}

			$original_gateway = (string) ( $m['_give_payment_gateway'] ?? '' );
			if ( '' !== $original_gateway && $original_gateway !== $record['payment_gateway'] ) {
				$record['meta']['_givewp_gateway'] = $original_gateway;
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
		global $wpdb;

		$where  = 'p.post_type = %s AND p.post_status <> %s';
		$params = [ 'give_payment', 'trash' ];

		if ( empty( $options['include_test'] ) ) {
			$where   .= ' AND NOT EXISTS ( SELECT 1 FROM %i dm WHERE dm.donation_id = p.ID AND dm.meta_key = %s AND dm.meta_value = %s )';
			$params[] = $this->source->table( 'donationmeta' );
			$params[] = '_give_payment_mode';
			$params[] = 'test';
		}

		return [ $where, $params ];
	}
}
