<?php
/**
 * Imports subscription rows.
 *
 * @package MissionDP
 */

namespace MissionDP\Import\Importers;

use MissionDP\Constants\Frequency;
use MissionDP\Models\ImportJob;
use MissionDP\Models\Subscription;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Writes subscription rows through Subscription::save_silent() so the activity
 * feed and status-transition emails don't fire for historical rows being
 * backfilled. Subscriptions don't touch donor/campaign aggregates, so there's
 * no end-of-job recompute. Duplicates are matched by gateway_subscription_id.
 *
 * @internal Serves the import pipeline; ImportService is the public API.
 */
class SubscriptionImporter extends RowImporter {

	/**
	 * Process a batch of mapped subscription rows.
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

			$row_warnings = $this->validator->validate( $row, 'subscriptions', $row_number );

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
			 * Filter a subscription row right before it is saved.
			 *
			 * @param array  $prepared Prepared row data.
			 * @param array  $row      Original mapped row.
			 * @param string $strategy Duplicate strategy.
			 */
			$prepared = apply_filters( 'mission_import_subscriptions_row', $prepared, $row, $strategy );

			$meta_pairs = $this->extract_meta_pairs( $row );

			try {
				$gateway_id = (string) ( $prepared['gateway_subscription_id'] ?? '' );
				$existing   = '' !== $gateway_id ? Subscription::find_by_gateway_subscription_id( $gateway_id ) : null;

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
						$this->apply_meta( $existing, $meta_pairs );
						++$updated;
						continue;
					}
				}

				$subscription = new Subscription( $prepared );
				$subscription->save_silent();
				$this->apply_meta( $subscription, $meta_pairs );
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
	 * Convert a mapped row into the data array expected by the Subscription constructor.
	 *
	 * Resolves donor (required) and campaign (optional). Zeroes out renewal_count
	 * and total_renewed, and omits id, source_post_id, and initial_transaction_id
	 * (local references that won't survive an import). Status is assumed valid —
	 * rows with a missing/invalid status are flagged as errors and skipped before
	 * this runs.
	 *
	 * @param array<string, string> $row Mapped row keyed by canonical field.
	 * @return array<string, mixed>|WP_Error
	 */
	private function prepare( array $row ): array|WP_Error {
		$donor_id = $this->resolver->resolve_donor_id( $row );
		if ( is_wp_error( $donor_id ) ) {
			return $donor_id;
		}

		$campaign_id = $this->resolver->resolve_campaign_id( $row );
		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		$currency = strtolower( trim( (string) ( $row['currency'] ?? '' ) ) );
		if ( '' === $currency ) {
			$currency = strtolower( $this->values->site_currency() );
		}

		$amount     = $this->values->parse_amount( (string) ( $row['amount'] ?? '0' ), $currency );
		$fee_amount = $this->values->parse_amount( (string) ( $row['fee_amount'] ?? '0' ), $currency );
		$tip_amount = $this->values->parse_amount( (string) ( $row['tip_amount'] ?? '0' ), $currency );

		$total_amount = isset( $row['total_amount'] ) && '' !== trim( (string) $row['total_amount'] )
			? $this->values->parse_amount( (string) $row['total_amount'], $currency )
			: $amount + $fee_amount + $tip_amount;

		$status = strtolower( trim( (string) ( $row['status'] ?? '' ) ) );

		$frequency = strtolower( trim( (string) ( $row['frequency'] ?? '' ) ) );
		if ( ! in_array( $frequency, Frequency::RECURRING, true ) ) {
			$frequency = Frequency::MONTHLY;
		}

		$date_created      = $this->values->parse_date( (string) ( $row['date_created'] ?? '' ) );
		$date_next_renewal = $this->values->parse_date( (string) ( $row['date_next_renewal'] ?? '' ) );
		$date_cancelled    = $this->values->parse_date( (string) ( $row['date_cancelled'] ?? '' ) );
		$date_modified     = $this->values->parse_date( (string) ( $row['date_modified'] ?? '' ) );

		if ( Subscription::STATUS_CANCELLED === $status && null === $date_cancelled ) {
			$date_cancelled = $date_created ?? current_time( 'mysql', true );
		}

		$prepared = [
			'status'                  => $status,
			'donor_id'                => $donor_id,
			'campaign_id'             => $campaign_id,
			'amount'                  => $amount,
			'fee_amount'              => $fee_amount,
			'tip_amount'              => $tip_amount,
			'total_amount'            => $total_amount,
			'currency'                => $currency,
			'frequency'               => $frequency,
			'payment_gateway'         => trim( (string) ( $row['payment_gateway'] ?? '' ) ),
			'gateway_subscription_id' => trim( (string) ( $row['gateway_subscription_id'] ?? '' ) ) ?: null,
			'gateway_customer_id'     => trim( (string) ( $row['gateway_customer_id'] ?? '' ) ) ?: null,
			'is_test'                 => $this->values->parse_bool( $row['is_test'] ?? '' ),
		];

		if ( null !== $date_created ) {
			$prepared['date_created'] = $date_created;
		}
		if ( null !== $date_next_renewal ) {
			$prepared['date_next_renewal'] = $date_next_renewal;
		}
		if ( null !== $date_cancelled ) {
			$prepared['date_cancelled'] = $date_cancelled;
		}
		if ( null !== $date_modified ) {
			$prepared['date_modified'] = $date_modified;
		}

		return $prepared;
	}
}
