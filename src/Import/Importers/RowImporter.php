<?php
/**
 * Abstract base for per-type row importers.
 *
 * @package MissionDP
 */

namespace MissionDP\Import\Importers;

use MissionDP\Import\EntityResolver;
use MissionDP\Import\RowValueParser;
use MissionDP\Import\Validators\RowValidator;
use MissionDP\Models\ImportJob;

defined( 'ABSPATH' ) || exit;

/**
 * Shared dependencies and meta handling for the per-type importers. Each
 * subclass validates, prepares, and writes one batch of mapped rows.
 *
 * @internal Serves the import pipeline; ImportService is the public API.
 */
abstract class RowImporter {

	/**
	 * Constructor.
	 *
	 * @param RowValidator   $validator Row validator.
	 * @param RowValueParser $values    Value coercion for raw cell values.
	 * @param EntityResolver $resolver  ID resolution with per-batch lookup caches.
	 */
	public function __construct(
		protected RowValidator $validator,
		protected RowValueParser $values,
		protected EntityResolver $resolver,
	) {}

	/**
	 * Apply a batch of mapped rows and return the delta to the job's counters.
	 *
	 * @param array<int, array<string, string>> $rows      Mapped rows.
	 * @param string                            $strategy  Duplicate strategy (skip/update).
	 * @param int                               $start_row 1-based row number of the first row.
	 * @param ImportJob                         $job       The job being processed.
	 *
	 * @return array{imported: int, skipped: int, updated: int, errors: int, error_details: array<int, array{row: int, message: string}>}
	 */
	abstract public function process( array $rows, string $strategy, int $start_row, ImportJob $job ): array;

	/**
	 * Pull `meta:*` columns out of a row.
	 *
	 * @param array<string, string> $row Mapped row.
	 * @return array<string, string>
	 */
	protected function extract_meta_pairs( array $row ): array {
		$pairs = [];

		foreach ( $row as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'meta:' ) ) {
				continue;
			}

			$meta_key = substr( $key, 5 );

			if ( '' === $meta_key ) {
				continue;
			}

			$pairs[ $meta_key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $pairs;
	}

	/**
	 * Apply meta:* pairs to any model that uses HasMeta (Donor, Transaction).
	 *
	 * @param object                $model Model with HasMeta trait.
	 * @param array<string, string> $pairs Meta key => value pairs.
	 */
	protected function apply_meta( object $model, array $pairs ): void {
		foreach ( $pairs as $key => $value ) {
			$model->update_meta( $key, $value );
		}
	}
}
