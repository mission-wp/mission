<?php
/**
 * Column mapper. Translates uploaded file headers into model field keys.
 *
 * @package MissionDP
 */

namespace MissionDP\Import;

use MissionDP\Export\ExportService;

defined( 'ABSPATH' ) || exit;

/**
 * Maps imported column headers to known model field names.
 */
class ColumnMapper {

	/**
	 * Constructor.
	 *
	 * @param ExportService $export Export service (source of canonical column lists).
	 */
	public function __construct(
		private readonly ExportService $export,
	) {}

	/**
	 * Get the canonical field keys for a data type.
	 *
	 * @param string $type Data type.
	 * @return string[]
	 */
	public function get_known_fields( string $type ): array {
		return array_column( $this->export->get_columns( $type ), 'key' );
	}

	/**
	 * Get the alias map for a type: array<alias_lowercase, canonical_key>.
	 *
	 * @param string $type Data type.
	 * @return array<string, string>
	 */
	public function get_alias_map( string $type ): array {
		$map = [];

		foreach ( $this->export->get_columns( $type ) as $column ) {
			$key   = $column['key'];
			$label = $column['label'];

			$map[ self::normalize( $key ) ]   = $key;
			$map[ self::normalize( $label ) ] = $key;
		}

		foreach ( $this->common_aliases( $type ) as $alias => $canonical ) {
			$map[ self::normalize( $alias ) ] = $canonical;
		}

		/**
		 * Filter the column alias map for an import type.
		 *
		 * @param array<string, string> $map  Alias => canonical key. Aliases are pre-normalized (lowercase, alphanumeric).
		 * @param string                $type Data type.
		 */
		return apply_filters( "missiondp_import_{$type}_column_map", $map, $type );
	}

	/**
	 * Resolve uploaded headers to canonical field keys.
	 *
	 * @param string[] $headers Uploaded headers in file order.
	 * @param string   $type    Data type.
	 * @return array{matched: array<int, string|null>, unmatched: string[]}
	 */
	public function resolve_headers( array $headers, string $type ): array {
		$alias_map = $this->get_alias_map( $type );
		$matched   = [];
		$unmatched = [];

		foreach ( $headers as $header ) {
			$key = self::normalize( $header );

			if ( isset( $alias_map[ $key ] ) ) {
				$matched[] = $alias_map[ $key ];
			} else {
				$matched[]   = null;
				$unmatched[] = $header;
			}
		}

		return [
			'matched'   => $matched,
			'unmatched' => $unmatched,
		];
	}

	/**
	 * Normalize a header for comparison (lowercase, strip non-alphanumeric).
	 *
	 * @param string $value Header value.
	 */
	public static function normalize( string $value ): string {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( $value ) ) ?? '';
	}

	/**
	 * Hand-crafted aliases for headers users commonly produce.
	 *
	 * @param string $type Data type.
	 * @return array<string, string>
	 */
	private function common_aliases( string $type ): array {
		$shared = [
			'Email Address' => 'email',
			'E-mail'        => 'email',
			'Name'          => 'first_name',
			'First'         => 'first_name',
			'Last'          => 'last_name',
			'Postal Code'   => 'zip',
			'Zip Code'      => 'zip',
			'Created'       => 'date_created',
			'Created At'    => 'date_created',
			'Modified'      => 'date_modified',
			'Updated At'    => 'date_modified',
		];

		$by_type = match ( $type ) {
			'donors'        => [
				'Total Given'   => 'total_donated',
				'Donations'     => 'transaction_count',
				'Last Donation' => 'last_transaction',
			],
			'transactions'  => [
				'Donor Email'    => 'donor_email',
				'Campaign Title' => 'campaign_title',
				'Gateway'        => 'payment_gateway',
				'Charge ID'      => 'gateway_transaction_id',
				'Transaction ID' => 'gateway_transaction_id',
				'Date'           => 'date_created',
			],
			'campaigns'     => [
				'Title' => 'title',
				'Goal'  => 'goal_amount',
			],
			'subscriptions' => [
				'Donor Email' => 'donor_email',
			],
			default         => [],
		};

		return array_merge( $shared, $by_type );
	}
}
