<?php
/**
 * Imports campaign rows.
 *
 * @package MissionDP
 */

namespace MissionDP\Import\Importers;

use MissionDP\Models\Campaign;
use MissionDP\Models\ImportJob;

defined( 'ABSPATH' ) || exit;

/**
 * Writes campaign rows through the Campaign model so each new campaign's
 * linked WP post is created too. Aggregate fields are never imported — they
 * recompute from real transactions — so prepare() omits them. Duplicates are
 * matched by title.
 *
 * @internal Serves the import pipeline; ImportService is the public API.
 */
class CampaignImporter extends RowImporter {

	/**
	 * Process a batch of mapped campaign rows.
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

			$row_warnings = $this->validator->validate( $row, 'campaigns', $row_number );

			foreach ( $row_warnings as $warning ) {
				if ( 'error' === ( $warning['severity'] ?? 'warning' ) ) {
					++$skipped;
					continue 2;
				}
			}

			$prepared = $this->prepare( $row );

			/**
			 * Filter a campaign row right before it is saved.
			 *
			 * @param array  $prepared Prepared row data.
			 * @param array  $row      Original mapped row.
			 * @param string $strategy Duplicate strategy.
			 */
			$prepared = apply_filters( 'mission_import_campaigns_row', $prepared, $row, $strategy );

			$meta_pairs = $this->extract_meta_pairs( $row );

			try {
				$title    = (string) ( $prepared['title'] ?? '' );
				$existing = '' !== $title ? Campaign::find_by_title( $title ) : null;

				if ( $existing ) {
					if ( 'skip' === $strategy ) {
						++$skipped;
						continue;
					}

					if ( 'update' === $strategy ) {
						foreach ( $prepared as $field => $value ) {
							$existing->{$field} = $value;
						}
						$existing->save();

						// Campaign::save() only writes the linked post on creation, so
						// sync title/description here to keep the post from going stale.
						wp_update_post(
							[
								'ID'           => $existing->post_id,
								'post_title'   => $existing->title,
								'post_excerpt' => $existing->description,
							]
						);

						$this->apply_meta( $existing, $meta_pairs );
						++$updated;
						continue;
					}
				}

				$campaign = new Campaign( $prepared );
				$campaign->save();
				$this->apply_meta( $campaign, $meta_pairs );
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
	 * Convert a mapped row into the data array expected by the Campaign constructor.
	 *
	 * Excludes id, post_id, and aggregate fields (total_raised, transaction_count,
	 * donor_count, and their test_* variants) so they default to zero and recompute
	 * from real transactions. Status is derived from the date window when not given
	 * as a valid value.
	 *
	 * @param array<string, string> $row Mapped row keyed by canonical field.
	 * @return array<string, mixed>
	 */
	private function prepare( array $row ): array {
		$prepared = [];

		$title = trim( (string) ( $row['title'] ?? '' ) );
		if ( '' !== $title ) {
			$prepared['title'] = $title;
		}

		$description = trim( (string) ( $row['description'] ?? '' ) );
		if ( '' !== $description ) {
			$prepared['description'] = $description;
		}

		$currency = strtolower( trim( (string) ( $row['currency'] ?? '' ) ) );
		if ( '' !== $currency ) {
			$prepared['currency'] = $currency;
		}

		if ( isset( $row['goal_amount'] ) && '' !== trim( (string) $row['goal_amount'] ) ) {
			$prepared['goal_amount'] = $this->values->parse_amount( (string) $row['goal_amount'], $currency ?: null );
		}

		$goal_type = strtolower( trim( (string) ( $row['goal_type'] ?? '' ) ) );
		if ( in_array( $goal_type, [ 'amount', 'donations', 'donors' ], true ) ) {
			$prepared['goal_type'] = $goal_type;
		}

		// Only honor show_in_listings when a value is present; an empty cell must
		// not force it false (the model defaults to true).
		if ( isset( $row['show_in_listings'] ) && '' !== trim( (string) $row['show_in_listings'] ) ) {
			$prepared['show_in_listings'] = $this->values->parse_bool( $row['show_in_listings'] );
		}

		$date_start    = $this->values->parse_date( (string) ( $row['date_start'] ?? '' ) );
		$date_end      = $this->values->parse_date( (string) ( $row['date_end'] ?? '' ) );
		$date_created  = $this->values->parse_date( (string) ( $row['date_created'] ?? '' ) );
		$date_modified = $this->values->parse_date( (string) ( $row['date_modified'] ?? '' ) );

		if ( null !== $date_start ) {
			$prepared['date_start'] = $date_start;
		}
		if ( null !== $date_end ) {
			$prepared['date_end'] = $date_end;
		}
		if ( null !== $date_created ) {
			$prepared['date_created'] = $date_created;
		}
		if ( null !== $date_modified ) {
			$prepared['date_modified'] = $date_modified;
		}

		$prepared['status'] = $this->resolve_status( $row['status'] ?? '', $date_start, $date_end );

		return $prepared;
	}

	/**
	 * Resolve a campaign status: honor a valid given value, otherwise derive it
	 * from the date window.
	 *
	 * @param string      $raw        Raw status value from the row.
	 * @param string|null $date_start Parsed start datetime (MySQL, UTC) or null.
	 * @param string|null $date_end   Parsed end datetime (MySQL, UTC) or null.
	 */
	private function resolve_status( string $raw, ?string $date_start, ?string $date_end ): string {
		$status = strtolower( trim( $raw ) );

		if ( in_array( $status, Campaign::STATUSES, true ) ) {
			return $status;
		}

		$now = current_time( 'mysql', true );

		if ( null !== $date_start && $date_start > $now ) {
			return Campaign::STATUS_SCHEDULED;
		}

		if ( null !== $date_end && $date_end < $now ) {
			return Campaign::STATUS_ENDED;
		}

		return Campaign::STATUS_ACTIVE;
	}
}
