<?php
/**
 * Transaction history model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\TransactionHistoryDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Transaction history model for immutable event log entries.
 */
class TransactionHistory extends Model {

	public int $transaction_id;
	public string $event_type;
	public ?string $actor_type;
	public ?int $actor_id;
	public ?string $context;
	public string $created_at;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id             = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->transaction_id = (int) ( $data['transaction_id'] ?? 0 );
		$this->event_type     = $data['event_type'] ?? '';
		$this->actor_type     = $data['actor_type'] ?? null;
		$this->actor_id       = isset( $data['actor_id'] ) ? (int) $data['actor_id'] : null;
		$this->context        = $data['context'] ?? null;
		$this->created_at     = $data['created_at'] ?? current_time( 'mysql', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new TransactionHistoryDataStore();
	}

	/**
	 * Get decoded context data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_context_data(): array {
		if ( ! $this->context ) {
			return [];
		}

		$decoded = json_decode( $this->context, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Get the transaction this history entry belongs to.
	 *
	 * @return Transaction|null
	 */
	public function transaction(): ?Transaction {
		return $this->transaction_id ? Transaction::find( $this->transaction_id ) : null;
	}
}
