<?php
/**
 * Transaction history module — logs lifecycle events for transactions.
 *
 * @package MissionDP
 */

namespace MissionDP\TransactionHistory;

use MissionDP\Models\TransactionHistory;

defined( 'ABSPATH' ) || exit;

/**
 * Transaction history module class.
 */
class TransactionHistoryModule {

	/**
	 * Specific status transitions handled by dedicated listeners.
	 *
	 * @var array<string>
	 */
	private const HANDLED_TRANSITIONS = [
		'pending_to_completed',
		'pending_to_failed',
		'completed_to_refunded',
	];

	/**
	 * Initialize the module.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->register_event_listeners();
	}

	/**
	 * Log a transaction history event.
	 *
	 * @param int                  $transaction_id Transaction ID.
	 * @param string               $event_type     Event type.
	 * @param string|null          $actor_type     Actor type (system, gateway, user).
	 * @param int|null             $actor_id       Actor ID (user ID when actor_type is user).
	 * @param array<string, mixed> $context        Optional context data.
	 *
	 * @return int The new history entry ID.
	 */
	public function log( int $transaction_id, string $event_type, ?string $actor_type = null, ?int $actor_id = null, array $context = [] ): int {
		$entry = new TransactionHistory(
			[
				'transaction_id' => $transaction_id,
				'event_type'     => $event_type,
				'actor_type'     => $actor_type,
				'actor_id'       => $actor_id,
				'context'        => $context ? wp_json_encode( $context ) : null,
			]
		);

		return $entry->save();
	}

	/**
	 * Log a system event.
	 *
	 * @param int                  $transaction_id Transaction ID.
	 * @param string               $event_type     Event type.
	 * @param array<string, mixed> $context        Optional context data.
	 *
	 * @return int
	 */
	public function log_system_event( int $transaction_id, string $event_type, array $context = [] ): int {
		return $this->log( $transaction_id, $event_type, 'system', null, $context );
	}

	/**
	 * Log a gateway event.
	 *
	 * @param int                  $transaction_id Transaction ID.
	 * @param string               $event_type     Event type.
	 * @param array<string, mixed> $context        Optional context data.
	 *
	 * @return int
	 */
	public function log_gateway_event( int $transaction_id, string $event_type, array $context = [] ): int {
		return $this->log( $transaction_id, $event_type, 'gateway', null, $context );
	}

	/**
	 * Log a user event.
	 *
	 * @param int                  $transaction_id Transaction ID.
	 * @param string               $event_type     Event type.
	 * @param int                  $user_id        User ID.
	 * @param array<string, mixed> $context        Optional context data.
	 *
	 * @return int
	 */
	public function log_user_event( int $transaction_id, string $event_type, int $user_id, array $context = [] ): int {
		return $this->log( $transaction_id, $event_type, 'user', $user_id, $context );
	}

	/**
	 * Register all event listeners.
	 *
	 * @return void
	 */
	private function register_event_listeners(): void {
		add_action( 'missiondp_transaction_created', [ $this, 'on_transaction_created' ] );
		add_action( 'missiondp_transaction_status_pending_to_completed', [ $this, 'on_payment_completed' ] );
		add_action( 'missiondp_transaction_status_pending_to_failed', [ $this, 'on_payment_failed' ] );
		add_action( 'missiondp_transaction_status_completed_to_refunded', [ $this, 'on_refund_completed' ] );
		add_action( 'missiondp_transaction_refund_applied', [ $this, 'on_refund_applied' ], 10, 2 );
		add_action( 'missiondp_transaction_status_transition', [ $this, 'on_status_transition' ], 10, 3 );
	}

	/**
	 * Handle transaction created.
	 *
	 * @param object $transaction Transaction model.
	 *
	 * @return void
	 */
	public function on_transaction_created( object $transaction ): void {
		$this->log_system_event(
			$transaction->id,
			'payment_initiated',
			[
				'amount'          => $transaction->amount,
				'payment_gateway' => $transaction->payment_gateway,
			]
		);
	}

	/**
	 * Handle payment completed.
	 *
	 * @param object $transaction Transaction model.
	 *
	 * @return void
	 */
	public function on_payment_completed( object $transaction ): void {
		$this->log_gateway_event(
			$transaction->id,
			'payment_completed',
			[
				'gateway_transaction_id' => $transaction->gateway_transaction_id,
			]
		);
	}

	/**
	 * Handle payment failed.
	 *
	 * @param object $transaction Transaction model.
	 *
	 * @return void
	 */
	public function on_payment_failed( object $transaction ): void {
		$this->log_gateway_event( $transaction->id, 'payment_failed' );
	}

	/**
	 * Handle refund completed.
	 *
	 * @param object $transaction Transaction model.
	 *
	 * @return void
	 */
	public function on_refund_completed( object $transaction ): void {
		$this->log_gateway_event(
			$transaction->id,
			'refund_completed',
			[
				'amount' => $transaction->amount,
			]
		);
	}

	/**
	 * Handle a refund applied event (partial or full).
	 *
	 * Only logs for partial refunds — full refunds are logged by on_refund_completed
	 * via the status transition hook.
	 *
	 * @param object $transaction  Transaction model.
	 * @param int    $refund_delta Amount refunded in this event (minor units).
	 *
	 * @return void
	 */
	public function on_refund_applied( object $transaction, int $refund_delta ): void {
		// Full refunds are logged by on_refund_completed via the status transition.
		if ( 'refunded' === $transaction->status ) {
			return;
		}

		$this->log_gateway_event(
			$transaction->id,
			'partial_refund',
			[
				'amount'            => $refund_delta,
				'total_refunded'    => $transaction->amount_refunded,
				'transaction_total' => $transaction->total_amount,
			]
		);
	}

	/**
	 * Handle generic status transition (skip transitions with dedicated handlers).
	 *
	 * @param object $transaction Transaction model.
	 * @param string $old_status  Previous status.
	 * @param string $new_status  New status.
	 *
	 * @return void
	 */
	public function on_status_transition( object $transaction, string $old_status, string $new_status ): void {
		$transition_key = "{$old_status}_to_{$new_status}";

		if ( in_array( $transition_key, self::HANDLED_TRANSITIONS, true ) ) {
			return;
		}

		$user_id    = get_current_user_id();
		$actor_type = $user_id > 0 ? 'user' : 'system';
		$actor_id   = $user_id > 0 ? $user_id : null;

		$this->log(
			$transaction->id,
			'status_changed',
			$actor_type,
			$actor_id,
			[
				'old_status' => $old_status,
				'new_status' => $new_status,
			]
		);
	}
}
