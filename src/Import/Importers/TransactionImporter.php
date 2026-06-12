<?php
/**
 * Imports transaction rows.
 *
 * @package MissionDP
 */

namespace MissionDP\Import\Importers;

use MissionDP\Import\EntityResolver;
use MissionDP\Import\RowValueParser;
use MissionDP\Import\TouchedEntityTracker;
use MissionDP\Import\Validators\RowValidator;
use MissionDP\Models\ImportJob;
use MissionDP\Models\Transaction;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Writes transaction rows through Transaction::save_silent() so we don't fire
 * mission_transaction_created or per-row aggregate updates. Touched donor and
 * campaign IDs are accumulated in the job's transient so the end-of-job
 * recompute can rebuild aggregates in a single pass. Duplicates are matched
 * by gateway_transaction_id.
 *
 * @internal Serves the import pipeline; ImportService is the public API.
 */
class TransactionImporter extends RowImporter {

	/**
	 * Constructor.
	 *
	 * @param RowValidator         $validator Row validator.
	 * @param RowValueParser       $values    Value coercion for raw cell values.
	 * @param EntityResolver       $resolver  ID resolution with per-batch lookup caches.
	 * @param TouchedEntityTracker $touched   Touched-entity accumulation for the recompute.
	 */
	public function __construct(
		RowValidator $validator,
		RowValueParser $values,
		EntityResolver $resolver,
		private TouchedEntityTracker $touched,
	) {
		parent::__construct( $validator, $values, $resolver );
	}

	/**
	 * Process a batch of mapped transaction rows.
	 *
	 * @param array<int, array<string, string>> $rows      Mapped rows.
	 * @param string                            $strategy  Duplicate strategy (skip/update).
	 * @param int                               $start_row 1-based row number of the first row.
	 * @param ImportJob                         $job       The job (numeric id stamps provenance, token keys the touched transient).
	 *
	 * @return array{imported: int, skipped: int, updated: int, errors: int, error_details: array<int, array{row: int, message: string}>}
	 */
	public function process( array $rows, string $strategy, int $start_row, ImportJob $job ): array {
		$job_id        = $job->job_id;
		$imported      = 0;
		$skipped       = 0;
		$updated       = 0;
		$errors        = 0;
		$error_details = [];

		$this->resolver->reset();

		foreach ( $rows as $i => $row ) {
			$row_number = $start_row + $i;

			$row_warnings = $this->validator->validate( $row, 'transactions', $row_number );

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
			 * Filter a transaction row right before it is saved.
			 *
			 * @param array  $prepared Prepared row data.
			 * @param array  $row      Original mapped row.
			 * @param string $strategy Duplicate strategy.
			 */
			$prepared = apply_filters( 'mission_import_transactions_row', $prepared, $row, $strategy );

			$meta_pairs = $this->extract_meta_pairs( $row );

			try {
				$gateway_id = (string) ( $prepared['gateway_transaction_id'] ?? '' );
				$existing   = '' !== $gateway_id ? Transaction::find_by_gateway_transaction_id( $gateway_id ) : null;

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
						$this->touched->mark_from_transaction( $existing, $job_id );
						++$updated;
						continue;
					}
				}

				// Stamp provenance on created rows only. An import that updates
				// an existing transaction must not claim it.
				$prepared['import_job_id'] = (int) $job->id;

				$transaction = new Transaction( $prepared );
				$transaction->save_silent();
				$this->apply_meta( $transaction, $meta_pairs );
				$this->touched->mark_from_transaction( $transaction, $job_id );
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
	 * Convert a mapped transaction row into the data array for the Transaction constructor.
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

		$amount          = $this->values->parse_amount( (string) ( $row['amount'] ?? '0' ), $currency );
		$fee_amount      = $this->values->parse_amount( (string) ( $row['fee_amount'] ?? '0' ), $currency );
		$tip_amount      = $this->values->parse_amount( (string) ( $row['tip_amount'] ?? '0' ), $currency );
		$amount_refunded = $this->values->parse_amount( (string) ( $row['amount_refunded'] ?? '0' ), $currency );

		$total_amount = isset( $row['total_amount'] ) && '' !== trim( (string) $row['total_amount'] )
			? $this->values->parse_amount( (string) $row['total_amount'], $currency )
			: $amount + $fee_amount + $tip_amount;

		$status = strtolower( trim( (string) ( $row['status'] ?? '' ) ) );
		if ( ! in_array( $status, Transaction::STATUSES, true ) ) {
			$status = Transaction::STATUS_COMPLETED;
		}

		$date_created   = $this->values->parse_date( (string) ( $row['date_created'] ?? '' ) );
		$date_completed = $this->values->parse_date( (string) ( $row['date_completed'] ?? '' ) );
		$date_refunded  = $this->values->parse_date( (string) ( $row['date_refunded'] ?? '' ) );

		if ( Transaction::STATUS_COMPLETED === $status && null === $date_completed ) {
			$date_completed = $date_created ?? current_time( 'mysql', true );
		}

		$prepared = [
			'status'                  => $status,
			'donor_id'                => $donor_id,
			'campaign_id'             => $campaign_id,
			'amount'                  => $amount,
			'fee_amount'              => $fee_amount,
			'tip_amount'              => $tip_amount,
			'total_amount'            => $total_amount,
			'amount_refunded'         => $amount_refunded,
			'currency'                => $currency,
			'payment_gateway'         => trim( (string) ( $row['payment_gateway'] ?? '' ) ),
			'gateway_transaction_id'  => trim( (string) ( $row['gateway_transaction_id'] ?? '' ) ) ?: null,
			'gateway_subscription_id' => trim( (string) ( $row['gateway_subscription_id'] ?? '' ) ) ?: null,
			'gateway_customer_id'     => trim( (string) ( $row['gateway_customer_id'] ?? '' ) ),
			'is_anonymous'            => $this->values->parse_bool( $row['is_anonymous'] ?? '' ),
			'is_test'                 => $this->values->parse_bool( $row['is_test'] ?? '' ),
			'donor_ip'                => trim( (string) ( $row['donor_ip'] ?? '' ) ),
		];

		if ( null !== $date_created ) {
			$prepared['date_created'] = $date_created;
		}
		if ( null !== $date_completed ) {
			$prepared['date_completed'] = $date_completed;
		}
		if ( null !== $date_refunded ) {
			$prepared['date_refunded'] = $date_refunded;
		}

		if ( isset( $row['type'] ) && '' !== trim( (string) $row['type'] ) ) {
			$prepared['type'] = trim( (string) $row['type'] );
		}

		return $prepared;
	}
}
