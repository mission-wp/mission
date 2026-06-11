<?php
/**
 * Reads GiveWP donors.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Migrators\GiveWP\Readers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reads a foreign plugin's tables; no higher-level API exists.

use MissionDP\Migration\Migrators\GiveWP\GiveWPSource;

defined( 'ABSPATH' ) || exit;

/**
 * Reads wp_give_donors plus name/address/company donor meta. Aggregate fields
 * (purchase_value, purchase_count) are intentionally ignored; Mission
 * recomputes donor totals from the migrated transactions.
 */
class DonorReader {

	private const META_KEYS = [
		'_give_donor_first_name',
		'_give_donor_last_name',
		'_give_donor_company',
		'_give_donor_address_billing_line1_0',
		'_give_donor_address_billing_line2_0',
		'_give_donor_address_billing_city_0',
		'_give_donor_address_billing_state_0',
		'_give_donor_address_billing_zip_0',
		'_give_donor_address_billing_country_0',
	];

	/**
	 * Constructor.
	 *
	 * @param GiveWPSource $source Source DB access.
	 */
	public function __construct( private readonly GiveWPSource $source ) {}

	/**
	 * Count migratable donors.
	 *
	 * @param array<string, mixed> $options Migration options (unused; donors have no test mode).
	 */
	public function count( array $options ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- uniform reader signature.
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email <> %s', $this->source->table( 'donors' ), '' )
		);
	}

	/**
	 * Read the next batch of normalized donor records.
	 *
	 * @param int                  $after_id Last processed source ID.
	 * @param int                  $limit    Max records.
	 * @param array<string, mixed> $options  Migration options (unused; donors have no test mode).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function read_batch( int $after_id, int $limit, array $options ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- uniform reader signature.
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, user_id, name, email, date_created FROM %i WHERE email <> %s AND id > %d ORDER BY id ASC LIMIT %d',
				$this->source->table( 'donors' ),
				'',
				$after_id,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return [];
		}

		$donor_ids = array_map( static fn( array $row ): int => (int) $row['id'], $rows );
		$meta      = $this->source->table_exists( 'donormeta' )
			? $this->source->donor_meta( $donor_ids, self::META_KEYS )
			: [];

		$records = [];

		foreach ( $rows as $row ) {
			$donor_id   = (int) $row['id'];
			$donor_meta = $meta[ $donor_id ] ?? [];

			[ $first_name, $last_name ] = $this->resolve_name( (string) $row['name'], $donor_meta );

			$record = [
				'source_id'    => $donor_id,
				'email'        => strtolower( trim( (string) $row['email'] ) ),
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'user_id'      => (int) $row['user_id'] ?: null,
				'address_1'    => (string) ( $donor_meta['_give_donor_address_billing_line1_0'] ?? '' ),
				'address_2'    => (string) ( $donor_meta['_give_donor_address_billing_line2_0'] ?? '' ),
				'city'         => (string) ( $donor_meta['_give_donor_address_billing_city_0'] ?? '' ),
				'state'        => (string) ( $donor_meta['_give_donor_address_billing_state_0'] ?? '' ),
				'zip'          => (string) ( $donor_meta['_give_donor_address_billing_zip_0'] ?? '' ),
				'country'      => substr( (string) ( $donor_meta['_give_donor_address_billing_country_0'] ?? '' ), 0, 2 ),
				'date_created' => (string) $row['date_created'],
				'meta'         => [],
			];

			$company = (string) ( $donor_meta['_give_donor_company'] ?? '' );
			if ( '' !== $company ) {
				$record['meta']['company'] = $company;
			}

			$records[] = $record;
		}

		return $records;
	}

	/**
	 * Resolve first/last name from donor meta, falling back to splitting the
	 * full name column.
	 *
	 * @param string                $name       Full name from give_donors.name.
	 * @param array<string, string> $donor_meta Loaded donor meta.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function resolve_name( string $name, array $donor_meta ): array {
		$first = trim( (string) ( $donor_meta['_give_donor_first_name'] ?? '' ) );
		$last  = trim( (string) ( $donor_meta['_give_donor_last_name'] ?? '' ) );

		if ( '' !== $first || '' !== $last ) {
			return [ $first, $last ];
		}

		$parts = preg_split( '/\s+/', trim( $name ), 2 ) ?: [];

		return [ (string) ( $parts[0] ?? '' ), (string) ( $parts[1] ?? '' ) ];
	}
}
