<?php
/**
 * Shared base for migration writers.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Writers;

use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;

defined( 'ABSPATH' ) || exit;

/**
 * Writers consume normalized records from a migrator and persist them through
 * Mission models, stamping two kinds of meta:
 *
 * - A source-ID stamp ("{prefix}_donor_id" etc.) on every written record, so
 *   later batches/phases resolve references and re-runs skip duplicates.
 * - A created marker (CREATED_META_KEY = job_id) only on records the migration
 *   inserted. Records matched to pre-existing Mission data never get the
 *   marker, so a rollback deletes only what the migration created.
 */
abstract class AbstractWriter {

	/**
	 * Meta key marking records created (not merely matched) by a migration run.
	 */
	public const CREATED_META_KEY = '_migration_created';

	/**
	 * Constructor.
	 *
	 * @param string $key_prefix Source meta key prefix (e.g. '_givewp').
	 * @param string $job_id     Public job token.
	 */
	public function __construct(
		protected string $key_prefix,
		protected string $job_id,
	) {}

	/**
	 * Build a fully-prefixed source meta key.
	 *
	 * @param string $suffix Key suffix (e.g. 'donor_id').
	 */
	protected function source_key( string $suffix ): string {
		return $this->key_prefix . '_' . $suffix;
	}

	/**
	 * Build a PII-free error detail entry.
	 *
	 * @param int    $source_id Source row ID.
	 * @param string $message   Error message.
	 *
	 * @return array{source_id: int, message: string}
	 */
	protected function error_entry( int $source_id, string $message ): array {
		return [
			'source_id' => $source_id,
			'message'   => $message,
		];
	}

	/**
	 * Empty result accumulator.
	 *
	 * @return array{imported: int, skipped: int, errors: int, error_details: array<int, array{source_id: int, message: string}>}
	 */
	protected function empty_result(): array {
		return [
			'imported'      => 0,
			'skipped'       => 0,
			'errors'        => 0,
			'error_details' => [],
		];
	}

	/**
	 * Pre-build the source donor ID => Mission donor ID map for a batch.
	 *
	 * @param array<int, array<string, mixed>> $records Records carrying source_donor_id.
	 *
	 * @return array<string, int>
	 */
	protected function map_donors( array $records ): array {
		return Donor::find_ids_by_meta(
			$this->source_key( 'donor_id' ),
			array_column( $records, 'source_donor_id' )
		);
	}

	/**
	 * Pre-build campaign reference maps for a batch (campaign-ID stamps and
	 * form-ID stamps, for sources whose forms became campaigns).
	 *
	 * @param array<int, array<string, mixed>> $records Records carrying source_campaign_id / source_form_id.
	 *
	 * @return array{campaigns: array<string, int>, forms: array<string, int>}
	 */
	protected function map_campaigns( array $records ): array {
		return [
			'campaigns' => Campaign::find_ids_by_meta(
				$this->source_key( 'campaign_id' ),
				array_column( $records, 'source_campaign_id' )
			),
			'forms'     => Campaign::find_ids_by_meta(
				$this->source_key( 'form_id' ),
				array_column( $records, 'source_form_id' )
			),
		];
	}

	/**
	 * Resolve a record's Mission campaign ID via the stamp maps, falling back
	 * to a title match, or null when nothing matches.
	 *
	 * @param array<string, mixed>                                    $record        Normalized record.
	 * @param array{campaigns: array<string, int>, forms: array<string, int>} $campaign_maps Maps from map_campaigns().
	 */
	protected function resolve_campaign_id( array $record, array $campaign_maps ): ?int {
		$campaign_id = (string) (int) ( $record['source_campaign_id'] ?? 0 );
		if ( isset( $campaign_maps['campaigns'][ $campaign_id ] ) ) {
			return $campaign_maps['campaigns'][ $campaign_id ];
		}

		$form_id = (string) (int) ( $record['source_form_id'] ?? 0 );
		if ( isset( $campaign_maps['forms'][ $form_id ] ) ) {
			return $campaign_maps['forms'][ $form_id ];
		}

		$title = trim( (string) ( $record['campaign_title'] ?? '' ) );
		if ( '' !== $title ) {
			return Campaign::find_by_title( $title )?->id;
		}

		return null;
	}
}
