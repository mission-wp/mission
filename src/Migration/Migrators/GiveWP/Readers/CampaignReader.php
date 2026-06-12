<?php
/**
 * Reads GiveWP campaigns (or donation forms on pre-v4 sites).
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Migrators\GiveWP\Readers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reads a foreign plugin's tables; no higher-level API exists.

use MissionDP\Migration\AmountConverter;
use MissionDP\Migration\Migrators\GiveWP\GiveWPSource;
use MissionDP\Migration\Migrators\GiveWP\Maps\StatusMap;

defined( 'ABSPATH' ) || exit;

/**
 * On GiveWP v4+ sites campaigns come from the wp_give_campaigns table. Older
 * sites have no campaigns, so each donation form becomes a Mission campaign.
 */
class CampaignReader {

	/**
	 * Constructor.
	 *
	 * @param GiveWPSource $source   Source DB access.
	 * @param string       $currency Site currency code for goal conversion.
	 */
	public function __construct(
		private GiveWPSource $source,
		private string $currency,
	) {}

	/**
	 * Count migratable campaigns.
	 *
	 * @param array<string, mixed> $options Migration options (unused; campaigns have no test mode).
	 */
	public function count( array $options ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- uniform reader signature.
		global $wpdb;

		if ( $this->source->has_campaigns() ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->source->table( 'campaigns' ) )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status <> %s',
				$wpdb->posts,
				'give_forms',
				'trash'
			)
		);
	}

	/**
	 * Read the next batch of normalized campaign records.
	 *
	 * @param int                  $after_id Last processed source ID.
	 * @param int                  $limit    Max records.
	 * @param array<string, mixed> $options  Migration options (unused; campaigns have no test mode).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function read_batch( int $after_id, int $limit, array $options ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- uniform reader signature.
		return $this->source->has_campaigns()
			? $this->read_campaigns( $after_id, $limit )
			: $this->read_forms( $after_id, $limit );
	}

	/**
	 * Read from the v4+ campaigns table.
	 *
	 * @param int $after_id Cursor.
	 * @param int $limit    Max records.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read_campaigns( int $after_id, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				$this->source->table( 'campaigns' ),
				$after_id,
				$limit
			),
			ARRAY_A
		);

		$records = [];

		foreach ( $rows ?: [] as $row ) {
			$goal_type = StatusMap::campaign_goal_type( (string) ( $row['goal_type'] ?? 'amount' ) );
			$goal      = (string) ( $row['campaign_goal'] ?? '0' );

			$records[] = [
				'source_id'       => (int) $row['id'],
				'source_meta_key' => '_givewp_campaign_id',
				'title'           => (string) ( $row['campaign_title'] ?? '' ),
				'description'     => (string) ( '' !== ( $row['long_desc'] ?? '' ) ? $row['long_desc'] : ( $row['short_desc'] ?? '' ) ),
				'goal_type'       => $goal_type,
				// GiveWP stores amount goals in major currency units; count goals are plain counts.
				'goal_amount'     => 'amount' === $goal_type
					? AmountConverter::to_minor_units( $goal, $this->currency )
					: (int) $goal,
				'status'          => $this->resolve_status(
					(string) ( $row['status'] ?? 'active' ),
					$this->normalize_date( $row['start_date'] ?? null ),
					$this->normalize_date( $row['end_date'] ?? null )
				),
				'date_start'      => $this->normalize_date( $row['start_date'] ?? null ),
				'date_end'        => $this->normalize_date( $row['end_date'] ?? null ),
				'date_created'    => $this->normalize_date( $row['date_created'] ?? null ),
			];
		}

		return $records;
	}

	/**
	 * Read donation forms as campaigns (pre-v4 fallback).
	 *
	 * @param int $after_id Cursor.
	 * @param int $limit    Max records.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read_forms( int $after_id, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ID, post_title, post_excerpt, post_status, post_date_gmt FROM %i WHERE post_type = %s AND post_status <> %s AND ID > %d ORDER BY ID ASC LIMIT %d',
				$wpdb->posts,
				'give_forms',
				'trash',
				$after_id,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return [];
		}

		$form_ids = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
		$meta     = $this->source->table_exists( 'formmeta' )
			? $this->source->form_meta( $form_ids, [ '_give_goal_option', '_give_set_goal' ] )
			: [];

		$records = [];

		foreach ( $rows as $row ) {
			$form_id   = (int) $row['ID'];
			$form_meta = $meta[ $form_id ] ?? [];
			$has_goal  = 'enabled' === ( $form_meta['_give_goal_option'] ?? '' );

			$records[] = [
				'source_id'       => $form_id,
				'source_meta_key' => '_givewp_form_id',
				'title'           => (string) $row['post_title'],
				'description'     => (string) $row['post_excerpt'],
				'goal_type'       => 'amount',
				'goal_amount'     => $has_goal
					? AmountConverter::to_minor_units( (string) ( $form_meta['_give_set_goal'] ?? '0' ), $this->currency )
					: 0,
				'status'          => 'publish' === $row['post_status'] ? 'active' : 'ended',
				'date_start'      => null,
				'date_end'        => null,
				'date_created'    => (string) $row['post_date_gmt'],
			];
		}

		return $records;
	}

	/**
	 * Map a GiveWP campaign status to a Mission status, deriving from the
	 * date window where GiveWP's status has no Mission equivalent.
	 *
	 * @param string      $status     GiveWP campaign status.
	 * @param string|null $date_start Start date.
	 * @param string|null $date_end   End date.
	 */
	private function resolve_status( string $status, ?string $date_start, ?string $date_end ): string {
		$now = current_time( 'mysql', true );

		if ( in_array( $status, [ 'archived', 'inactive' ], true ) ) {
			return 'ended';
		}

		if ( null !== $date_end && $date_end < $now ) {
			return 'ended';
		}

		if ( null !== $date_start && $date_start > $now ) {
			return 'scheduled';
		}

		// Draft and pending campaigns have not gone live yet.
		if ( 'active' !== $status ) {
			return 'scheduled';
		}

		return 'active';
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
