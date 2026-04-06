<?php
/**
 * Tribute model.
 *
 * @package Mission
 */

namespace Mission\Models;

use Mission\Database\DataStore\DataStoreInterface;
use Mission\Database\DataStore\TributeDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Tribute model with relationship access.
 */
class Tribute extends Model {

	public int $transaction_id;
	public string $tribute_type;
	public string $honoree_name;
	public string $notify_name;
	public string $notify_email;
	public string $notify_address_1;
	public string $notify_address_2;
	public string $notify_city;
	public string $notify_state;
	public string $notify_zip;
	public string $notify_country;
	public string $notify_method;
	public string $message;
	public ?string $notification_sent_at;
	public string $date_created;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id                   = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->transaction_id       = (int) ( $data['transaction_id'] ?? 0 );
		$this->tribute_type         = $data['tribute_type'] ?? 'in_honor';
		$this->honoree_name         = $data['honoree_name'] ?? '';
		$this->notify_name          = $data['notify_name'] ?? '';
		$this->notify_email         = $data['notify_email'] ?? '';
		$this->notify_address_1     = $data['notify_address_1'] ?? '';
		$this->notify_address_2     = $data['notify_address_2'] ?? '';
		$this->notify_city          = $data['notify_city'] ?? '';
		$this->notify_state         = $data['notify_state'] ?? '';
		$this->notify_zip           = $data['notify_zip'] ?? '';
		$this->notify_country       = $data['notify_country'] ?? '';
		$this->notify_method        = $data['notify_method'] ?? '';
		$this->message              = $data['message'] ?? '';
		$this->notification_sent_at = $data['notification_sent_at'] ?? null;
		$this->date_created         = $data['date_created'] ?? current_time( 'mysql', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new TributeDataStore();
	}

	/**
	 * Get the transaction this tribute belongs to.
	 *
	 * @return Transaction|null
	 */
	public function transaction(): ?Transaction {
		return $this->transaction_id ? Transaction::find( $this->transaction_id ) : null;
	}

	/**
	 * Find the tribute for a given transaction.
	 *
	 * @param int $transaction_id Transaction ID.
	 *
	 * @return static|null
	 */
	public static function find_by_transaction_id( int $transaction_id ): ?static {
		$results = static::query(
			[
				'transaction_id' => $transaction_id,
				'per_page'       => 1,
			]
		);

		return $results[0] ?? null;
	}
}
