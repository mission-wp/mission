<?php
/**
 * Imports donor rows.
 *
 * @package MissionDP
 */

namespace MissionDP\Import\Importers;

use MissionDP\Models\Donor;
use MissionDP\Models\ImportJob;

defined( 'ABSPATH' ) || exit;

/**
 * Writes donor rows through the Model layer so hooks fire. Duplicates are
 * matched by email.
 *
 * @internal Serves the import pipeline; ImportService is the public API.
 */
class DonorImporter extends RowImporter {

	/**
	 * Apply a batch of donor rows. Re-runs the validator (skip on errors),
	 * respects the duplicate strategy, and saves via the Model layer.
	 *
	 * @param array<int, array<string, string>> $rows      Mapped rows.
	 * @param string                            $strategy  Duplicate strategy.
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

			$row_warnings = $this->validator->validate( $row, 'donors', $row_number );

			foreach ( $row_warnings as $warning ) {
				if ( 'error' === ( $warning['severity'] ?? 'warning' ) ) {
					++$skipped;
					continue 2;
				}
			}

			$prepared = $this->prepare( $row );

			/**
			 * Filter a donor row right before it is saved.
			 *
			 * @param array  $prepared Prepared row data.
			 * @param array  $row      Original mapped row.
			 * @param string $strategy Duplicate strategy.
			 */
			$prepared = apply_filters( 'mission_import_donors_row', $prepared, $row, $strategy );

			$meta_pairs = $this->extract_meta_pairs( $row );

			try {
				$email    = (string) ( $prepared['email'] ?? '' );
				$existing = '' !== $email ? Donor::find_by_email( $email ) : null;

				if ( $existing ) {
					if ( 'skip' === $strategy ) {
						++$skipped;
						continue;
					}

					if ( 'update' === $strategy ) {
						foreach ( $prepared as $field => $value ) {
							if ( 'email' === $field ) {
								continue;
							}
							$existing->{$field} = $value;
						}
						$existing->save();
						$this->apply_meta( $existing, $meta_pairs );
						++$updated;
						continue;
					}
				}

				$donor = new Donor( $prepared );
				$donor->save();
				$this->apply_meta( $donor, $meta_pairs );
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
	 * Convert a mapped row into the data array expected by the Donor constructor.
	 *
	 * @param array<string, string> $row Mapped row keyed by canonical field.
	 * @return array<string, mixed>
	 */
	private function prepare( array $row ): array {
		$known = [
			'email',
			'first_name',
			'last_name',
			'phone',
			'address_1',
			'address_2',
			'city',
			'state',
			'zip',
			'country',
			'total_donated',
			'total_tip',
			'transaction_count',
			'first_transaction',
			'last_transaction',
			'date_created',
			'date_modified',
		];

		$amount_fields = [ 'total_donated', 'total_tip' ];
		$date_fields   = [ 'first_transaction', 'last_transaction', 'date_created', 'date_modified' ];
		$int_fields    = [ 'transaction_count' ];

		$prepared = [];

		foreach ( $known as $field ) {
			if ( ! array_key_exists( $field, $row ) ) {
				continue;
			}

			$value = is_string( $row[ $field ] ) ? trim( $row[ $field ] ) : $row[ $field ];

			if ( '' === $value ) {
				continue;
			}

			if ( in_array( $field, $amount_fields, true ) ) {
				$prepared[ $field ] = $this->values->parse_amount( (string) $value );
			} elseif ( in_array( $field, $int_fields, true ) ) {
				$prepared[ $field ] = (int) preg_replace( '/[^0-9\-]/', '', (string) $value );
			} elseif ( in_array( $field, $date_fields, true ) ) {
				$parsed = $this->values->parse_date( (string) $value );
				if ( null !== $parsed ) {
					$prepared[ $field ] = $parsed;
				}
			} else {
				$prepared[ $field ] = (string) $value;
			}
		}

		if ( isset( $prepared['email'] ) ) {
			$prepared['email'] = strtolower( $prepared['email'] );
		}

		return $prepared;
	}
}
