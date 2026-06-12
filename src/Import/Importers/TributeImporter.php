<?php
/**
 * Imports dedication (tribute) rows.
 *
 * @package MissionDP
 */

namespace MissionDP\Import\Importers;

use MissionDP\Models\ImportJob;
use MissionDP\Models\Tribute;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Writes dedication rows through Tribute::save_silent() so the
 * honoree-notification and admin mail-dedication emails don't fire for
 * historical rows. Each dedication attaches to one existing transaction
 * (resolved by Charge ID or transaction_id). Tributes have no meta and touch
 * no aggregates. Duplicates are matched by transaction (one tribute per
 * transaction).
 *
 * @internal Serves the import pipeline; ImportService is the public API.
 */
class TributeImporter extends RowImporter {

	/**
	 * Process a batch of mapped dedication rows.
	 *
	 * @param array<int, array<string, string>> $rows      Mapped rows.
	 * @param string                            $strategy  Duplicate strategy (skip/update).
	 * @param int                               $start_row 1-based row number of the first row.
	 * @param ImportJob                         $job       The job being processed (unused).
	 *
	 * @return array{imported: int, skipped: int, updated: int, errors: int, error_details: array<int, array{row: int, message: string}>}
	 */
	public function process( array $rows, string $strategy, int $start_row, ImportJob $job ): array {
		$imported      = 0;
		$skipped       = 0;
		$updated       = 0;
		$errors        = 0;
		$error_details = [];

		$this->resolver->reset();

		foreach ( $rows as $i => $row ) {
			$row_number = $start_row + $i;

			$row_warnings = $this->validator->validate( $row, 'tributes', $row_number );

			foreach ( $row_warnings as $warning ) {
				if ( 'error' === ( $warning['severity'] ?? 'warning' ) ) {
					++$skipped;
					continue 2;
				}
			}

			$prepared = $this->prepare( $row );

			if ( is_wp_error( $prepared ) ) {
				++$errors;
				$error_details[] = [
					'row'     => $row_number,
					'message' => $prepared->get_error_message(),
				];
				continue;
			}

			/**
			 * Filter a dedication row right before it is saved.
			 *
			 * @param array  $prepared Prepared row data.
			 * @param array  $row      Original mapped row.
			 * @param string $strategy Duplicate strategy.
			 */
			$prepared = apply_filters( 'mission_import_tributes_row', $prepared, $row, $strategy );

			try {
				$existing = Tribute::find_by_transaction_id( (int) $prepared['transaction_id'] );

				if ( $existing ) {
					if ( 'skip' === $strategy ) {
						++$skipped;
						continue;
					}

					if ( 'update' === $strategy ) {
						foreach ( $prepared as $field => $value ) {
							$existing->{$field} = $value;
						}
						$existing->save_silent();
						++$updated;
						continue;
					}
				}

				$tribute = new Tribute( $prepared );
				$tribute->save_silent();
				++$imported;
			} catch ( \Throwable $e ) {
				++$errors;
				$error_details[] = [
					'row'     => $row_number,
					'message' => $e->getMessage(),
				];
			}
		}

		return [
			'imported'      => $imported,
			'skipped'       => $skipped,
			'updated'       => $updated,
			'errors'        => $errors,
			'error_details' => $error_details,
		];
	}

	/**
	 * Convert a mapped row into the data array expected by the Tribute constructor.
	 *
	 * @param array<string, string> $row Mapped row keyed by canonical field.
	 * @return array<string, mixed>|WP_Error
	 */
	private function prepare( array $row ): array|WP_Error {
		$transaction_id = $this->resolver->resolve_transaction_id( $row );
		if ( is_wp_error( $transaction_id ) ) {
			return $transaction_id;
		}

		$tribute_type = strtolower( trim( (string) ( $row['tribute_type'] ?? '' ) ) );
		if ( ! in_array( $tribute_type, [ 'in_honor', 'in_memory' ], true ) ) {
			$tribute_type = 'in_honor';
		}

		$notify_method = strtolower( trim( (string) ( $row['notify_method'] ?? '' ) ) );
		if ( ! in_array( $notify_method, [ 'email', 'mail' ], true ) ) {
			$notify_method = '';
		}

		$prepared = [
			'transaction_id'   => $transaction_id,
			'tribute_type'     => $tribute_type,
			'honoree_name'     => trim( (string) ( $row['honoree_name'] ?? '' ) ),
			'message'          => trim( (string) ( $row['message'] ?? '' ) ),
			'notify_method'    => $notify_method,
			'notify_name'      => trim( (string) ( $row['notify_name'] ?? '' ) ),
			'notify_email'     => trim( (string) ( $row['notify_email'] ?? '' ) ),
			'notify_address_1' => trim( (string) ( $row['notify_address_1'] ?? '' ) ),
			'notify_address_2' => trim( (string) ( $row['notify_address_2'] ?? '' ) ),
			'notify_city'      => trim( (string) ( $row['notify_city'] ?? '' ) ),
			'notify_state'     => trim( (string) ( $row['notify_state'] ?? '' ) ),
			'notify_zip'       => trim( (string) ( $row['notify_zip'] ?? '' ) ),
			'notify_country'   => trim( (string) ( $row['notify_country'] ?? '' ) ),
		];

		$notification_sent_at = $this->values->parse_date( (string) ( $row['notification_sent_at'] ?? '' ) );
		$date_created         = $this->values->parse_date( (string) ( $row['date_created'] ?? '' ) );

		if ( null !== $notification_sent_at ) {
			$prepared['notification_sent_at'] = $notification_sent_at;
		}
		if ( null !== $date_created ) {
			$prepared['date_created'] = $date_created;
		}

		return $prepared;
	}
}
