<?php
/**
 * Row validator. Checks individual rows during import preview.
 *
 * @package MissionDP
 */

namespace MissionDP\Import\Validators;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a single row of imported data against the rules for its type.
 */
class RowValidator {

	/**
	 * Required fields per data type.
	 *
	 * @return string[]
	 */
	public function required_fields( string $type ): array {
		return match ( $type ) {
			'donors'        => [ 'email' ],
			'transactions'  => [ 'amount' ],
			'campaigns'     => [ 'title' ],
			'subscriptions' => [ 'amount' ],
			default         => [],
		};
	}

	/**
	 * Validate a single mapped row.
	 *
	 * Each issue carries a severity. 'error' means the row will be skipped at
	 * import time; 'warning' means the row will be imported but a value may need
	 * cleanup.
	 *
	 * @param array<string, string> $row        Row keyed by canonical field name.
	 * @param string                $type       Data type.
	 * @param int                   $row_number Display row number (1-based, header excluded).
	 *
	 * @return array<int, array{row: int, column: string, message: string, value: string, severity: string}>
	 */
	public function validate( array $row, string $type, int $row_number ): array {
		$warnings = [];

		foreach ( $this->required_fields( $type ) as $field ) {
			$value = trim( $row[ $field ] ?? '' );

			if ( '' === $value ) {
				$warnings[] = [
					'row'      => $row_number,
					'column'   => $field,
					'message'  => sprintf(
						/* translators: %s: column name */
						__( 'Missing required value for "%s". Row will be skipped.', 'mission-donation-platform' ),
						$field
					),
					'value'    => '',
					'severity' => 'error',
				];
			}
		}

		foreach ( [ 'email', 'donor_email' ] as $email_field ) {
			if ( ! isset( $row[ $email_field ] ) ) {
				continue;
			}

			$value = trim( $row[ $email_field ] );

			if ( '' === $value || is_email( $value ) ) {
				continue;
			}

			$warnings[] = [
				'row'      => $row_number,
				'column'   => $email_field,
				'message'  => sprintf(
					/* translators: 1: column name, 2: invalid email value */
					__( 'Column "%1$s" has invalid email \'%2$s\'. Row will be skipped.', 'mission-donation-platform' ),
					$email_field,
					$value
				),
				'value'    => $value,
				'severity' => 'error',
			];
		}

		$is_transactions = 'transactions' === $type;

		if ( $is_transactions && isset( $row['status'] ) ) {
			$status  = trim( (string) $row['status'] );
			$allowed = [ 'pending', 'completed', 'refunded', 'cancelled', 'failed' ];

			if ( '' !== $status && ! in_array( strtolower( $status ), $allowed, true ) ) {
				$warnings[] = [
					'row'      => $row_number,
					'column'   => 'status',
					'message'  => sprintf(
						/* translators: %s: invalid status value */
						__( 'Status \'%s\' is not one of pending/completed/refunded/cancelled/failed. Row will be skipped.', 'mission-donation-platform' ),
						$status
					),
					'value'    => $status,
					'severity' => 'error',
				];
			}
		}

		if ( 'campaigns' === $type && isset( $row['status'] ) ) {
			$status  = trim( (string) $row['status'] );
			$allowed = [ 'active', 'scheduled', 'ended' ];

			if ( '' !== $status && ! in_array( strtolower( $status ), $allowed, true ) ) {
				$warnings[] = [
					'row'      => $row_number,
					'column'   => 'status',
					'message'  => sprintf(
						/* translators: %s: invalid status value */
						__( 'Status \'%s\' is not one of active/scheduled/ended. It will be set from the campaign dates.', 'mission-donation-platform' ),
						$status
					),
					'value'    => $status,
					'severity' => 'warning',
				];
			}
		}

		foreach ( [ 'amount', 'fee_amount', 'tip_amount', 'total_amount', 'goal_amount', 'total_donated', 'total_tip' ] as $numeric ) {
			if ( ! isset( $row[ $numeric ] ) ) {
				continue;
			}

			$value = trim( $row[ $numeric ] );

			if ( '' === $value ) {
				continue;
			}

			if ( ! $this->looks_like_number( $value ) ) {
				$is_error = $is_transactions;

				$warnings[] = [
					'row'      => $row_number,
					'column'   => $numeric,
					'message'  => sprintf(
						/* translators: 1: column name, 2: invalid value, 3: trailing sentence */
						__( 'Column "%1$s" has non-numeric value \'%2$s\'. %3$s', 'mission-donation-platform' ),
						$numeric,
						$value,
						$is_error
							? __( 'Row will be skipped.', 'mission-donation-platform' )
							: __( 'Will be imported as zero.', 'mission-donation-platform' )
					),
					'value'    => $value,
					'severity' => $is_error ? 'error' : 'warning',
				];
			}
		}

		foreach ( [ 'date_created', 'date_modified', 'first_transaction', 'last_transaction', 'transaction_date', 'date_start', 'date_end' ] as $date_key ) {
			if ( ! isset( $row[ $date_key ] ) ) {
				continue;
			}

			$value = trim( $row[ $date_key ] );

			if ( '' === $value ) {
				continue;
			}

			if ( false === strtotime( $value ) ) {
				$is_error = $is_transactions;

				$warnings[] = [
					'row'      => $row_number,
					'column'   => $date_key,
					'message'  => sprintf(
						/* translators: 1: column name, 2: invalid value, 3: trailing sentence */
						__( 'Column "%1$s" has unparseable date \'%2$s\'. %3$s', 'mission-donation-platform' ),
						$date_key,
						$value,
						$is_error
							? __( 'Row will be skipped.', 'mission-donation-platform' )
							: __( 'Will be left blank.', 'mission-donation-platform' )
					),
					'value'    => $value,
					'severity' => $is_error ? 'error' : 'warning',
				];
			}
		}

		/**
		 * Allow third parties to add custom row-level validation warnings.
		 *
		 * @param array  $warnings   Existing warnings for this row.
		 * @param array  $row        Mapped row data.
		 * @param int    $row_number Row number.
		 * @param string $type       Data type.
		 */
		return apply_filters( "missiondp_import_{$type}_validate_row", $warnings, $row, $row_number, $type );
	}

	/**
	 * Lightweight numeric check that accepts currency-formatted strings.
	 *
	 * Strips leading currency symbols, commas, and surrounding whitespace before
	 * checking with is_numeric so values like "$1,820" still validate.
	 *
	 * @param string $value Raw value.
	 */
	private function looks_like_number( string $value ): bool {
		$cleaned = preg_replace( '/[^0-9.\-]/', '', $value );

		return null !== $cleaned && '' !== $cleaned && is_numeric( $cleaned );
	}
}
