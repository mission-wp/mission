<?php
/**
 * Donor model.
 *
 * @package Mission
 */

namespace Mission\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Typed data object representing a single donor.
 */
class Donor {

	public ?int $id;
	public ?int $user_id;
	public string $email;
	public string $first_name;
	public string $last_name;
	public string $name_prefix;
	public string $phone;
	public int $total_donated;
	public int $total_tip;
	public int $transaction_count;
	public ?string $first_transaction;
	public ?string $last_transaction;
	public string $date_created;
	public string $date_modified;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = array() ) {
		$this->id                = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->user_id           = isset( $data['user_id'] ) ? (int) $data['user_id'] : null;
		$this->email             = $data['email'] ?? '';
		$this->first_name        = $data['first_name'] ?? '';
		$this->last_name         = $data['last_name'] ?? '';
		$this->name_prefix       = $data['name_prefix'] ?? '';
		$this->phone             = $data['phone'] ?? '';
		$this->total_donated     = (int) ( $data['total_donated'] ?? 0 );
		$this->total_tip         = (int) ( $data['total_tip'] ?? 0 );
		$this->transaction_count = (int) ( $data['transaction_count'] ?? 0 );
		$this->first_transaction = $data['first_transaction'] ?? null;
		$this->last_transaction  = $data['last_transaction'] ?? null;
		$this->date_created      = $data['date_created'] ?? current_time( 'mysql', true );
		$this->date_modified     = $data['date_modified'] ?? current_time( 'mysql', true );
	}
}
