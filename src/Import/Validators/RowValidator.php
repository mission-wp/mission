<?php
/**
 * Row validator. Checks individual rows during import preview.
 *
 * @package MissionDP
 */

namespace MissionDP\Import\Validators;

use MissionDP\Constants\Frequency;
use MissionDP\Models\Campaign;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;

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
			'subscriptions' => [ 'amount', 'status' ],
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
			$allowed = Transaction::STATUSES;

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
			$allowed = Campaign::STATUSES;

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

		$is_subscriptions = 'subscriptions' === $type;

		if ( $is_subscriptions ) {
			$status = trim( (string) ( $row['status'] ?? '' ) );

			// Imports never create past_due rows; that status only comes from Stripe.
			$allowed = [
				Subscription::STATUS_PENDING,
				Subscription::STATUS_ACTIVE,
				Subscription::STATUS_PAUSED,
				Subscription::STATUS_CANCELLED,
			];

			if ( '' !== $status && ! in_array( strtolower( $status ), $allowed, true ) ) {
				$warnings[] = [
					'row'      => $row_number,
					'column'   => 'status',
					'message'  => sprintf(
						/* translators: %s: invalid status value */
						__( 'Status \'%s\' is not one of pending/active/paused/cancelled. Row will be skipped.', 'mission-donation-platform' ),
						$status
					),
					'value'    => $status,
					'severity' => 'error',
				];
			}

			$frequency = trim( (string) ( $row['frequency'] ?? '' ) );
			$allowed_f = Frequency::RECURRING;

			if ( '' !== $frequency && ! in_array( strtolower( $frequency ), $allowed_f, true ) ) {
				$warnings[] = [
					'row'      => $row_number,
					'column'   => 'frequency',
					'message'  => sprintf(
						/* translators: %s: invalid frequency value */
						__( 'Frequency \'%s\' is not one of weekly/monthly/quarterly/annually. Will default to monthly.', 'mission-donation-platform' ),
						$frequency
					),
					'value'    => $frequency,
					'severity' => 'warning',
				];
			}

			$status_lower = strtolower( $status );
			$gateway_id   = trim( (string) ( $row['gateway_subscription_id'] ?? '' ) );

			if ( Subscription::STATUS_ACTIVE === $status_lower && '' === $gateway_id ) {
				$warnings[] = [
					'row'      => $row_number,
					'column'   => 'gateway_subscription_id',
					'message'  => __( 'Active subscription has no Subscription ID, so it cannot renew automatically. It will be imported as a record only.', 'mission-donation-platform' ),
					'value'    => '',
					'severity' => 'warning',
				];
			}
		}

		if ( 'tributes' === $type ) {
			$tribute_type = trim( (string) ( $row['tribute_type'] ?? '' ) );
			$allowed      = [ 'in_honor', 'in_memory' ];

			if ( '' !== $tribute_type && ! in_array( strtolower( $tribute_type ), $allowed, true ) ) {
				$warnings[] = [
					'row'      => $row_number,
					'column'   => 'tribute_type',
					'message'  => sprintf(
						/* translators: %s: invalid tribute type value */
						__( 'Type \'%s\' is not one of in_honor/in_memory. Will default to in_honor.', 'mission-donation-platform' ),
						$tribute_type
					),
					'value'    => $tribute_type,
					'severity' => 'warning',
				];
			}

			$notify_email = trim( (string) ( $row['notify_email'] ?? '' ) );

			if ( '' !== $notify_email && ! is_email( $notify_email ) ) {
				$warnings[] = [
					'row'      => $row_number,
					'column'   => 'notify_email',
					'message'  => sprintf(
						/* translators: %s: invalid email value */
						__( 'Notify email \'%s\' is not a valid email. It will be imported as-is; no notification is sent on import.', 'mission-donation-platform' ),
						$notify_email
					),
					'value'    => $notify_email,
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
				$is_error = $is_transactions || ( $is_subscriptions && 'amount' === $numeric );

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

				continue;
			}

			if ( $this->is_negative_number( $value ) ) {
				// Refunds are modeled as a refunded status plus amount_refunded, so a
				// negative primary amount would corrupt donor/campaign aggregates.
				$is_error = in_array( $numeric, [ 'amount', 'total_amount' ], true ) && ( $is_transactions || $is_subscriptions );

				$warnings[] = [
					'row'      => $row_number,
					'column'   => $numeric,
					'message'  => sprintf(
						/* translators: 1: column name, 2: negative value, 3: trailing sentence */
						__( 'Column "%1$s" has negative value \'%2$s\'. %3$s', 'mission-donation-platform' ),
						$numeric,
						$value,
						$is_error
							? __( 'Import refunds with the refunded status instead. Row will be skipped.', 'mission-donation-platform' )
							: __( 'It will be imported as a negative value.', 'mission-donation-platform' )
					),
					'value'    => $value,
					'severity' => $is_error ? 'error' : 'warning',
				];

				continue;
			}

			if ( $this->looks_like_decimal_comma( $value ) ) {
				$warnings[] = [
					'row'      => $row_number,
					'column'   => $numeric,
					'message'  => sprintf(
						/* translators: 1: column name, 2: ambiguous value */
						__( 'Column "%1$s" value \'%2$s\' may use a decimal comma. It will be read with the comma as a thousands separator; use a decimal point if that is wrong.', 'mission-donation-platform' ),
						$numeric,
						$value
					),
					'value'    => $value,
					'severity' => 'warning',
				];
			}
		}

		foreach ( [ 'date_created', 'date_modified', 'first_transaction', 'last_transaction', 'transaction_date', 'date_start', 'date_end', 'date_next_renewal', 'date_cancelled', 'notification_sent_at' ] as $date_key ) {
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
		return apply_filters( "mission_import_{$type}_validate_row", $warnings, $row, $row_number, $type );
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

	/**
	 * Whether a numeric-looking value is negative.
	 *
	 * @param string $value Raw value.
	 */
	private function is_negative_number( string $value ): bool {
		$cleaned = preg_replace( '/[^0-9.\-]/', '', $value );

		return is_numeric( $cleaned ) && (float) $cleaned < 0;
	}

	/**
	 * Whether a value likely uses a comma as its decimal separator ("1,50").
	 *
	 * Values with both separators are unambiguous (the parser handles them),
	 * so this only flags a lone comma followed by 1-2 trailing digits. Three
	 * trailing digits ("1,500") reads as a thousands separator and is not
	 * flagged.
	 *
	 * @param string $value Raw value.
	 */
	private function looks_like_decimal_comma( string $value ): bool {
		return ! str_contains( $value, '.' ) && 1 === preg_match( '/,\d{1,2}$/', trim( $value ) );
	}
}
