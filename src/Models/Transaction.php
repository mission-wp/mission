<?php
/**
 * Transaction model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\TransactionDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Transaction model with relationship and meta access.
 */
class Transaction extends Model {

	use HasMeta;

	public string $status;
	public string $type;
	public int $donor_id;
	public ?int $subscription_id;
	public ?int $parent_id;
	public int $source_post_id;
	public ?int $campaign_id;
	public int $amount;
	public int $fee_amount;
	public int $tip_amount;
	public int $total_amount;
	public int $amount_refunded;
	public string $currency;
	public string $payment_gateway;
	public ?string $gateway_transaction_id;
	public ?string $gateway_subscription_id;
	public string $gateway_customer_id;
	public bool $is_anonymous;
	public bool $is_test;
	public string $donor_ip;
	public string $date_created;
	public ?string $date_completed;
	public ?string $date_refunded;
	public string $date_modified;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id                      = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->status                  = $data['status'] ?? 'pending';
		$this->type                    = $data['type'] ?? 'one_time';
		$this->donor_id                = (int) ( $data['donor_id'] ?? 0 );
		$this->subscription_id         = isset( $data['subscription_id'] ) ? (int) $data['subscription_id'] : null;
		$this->parent_id               = isset( $data['parent_id'] ) ? (int) $data['parent_id'] : null;
		$this->source_post_id          = (int) ( $data['source_post_id'] ?? 0 );
		$this->campaign_id             = isset( $data['campaign_id'] ) ? (int) $data['campaign_id'] : null;
		$this->amount                  = (int) ( $data['amount'] ?? 0 );
		$this->fee_amount              = (int) ( $data['fee_amount'] ?? 0 );
		$this->tip_amount              = (int) ( $data['tip_amount'] ?? 0 );
		$this->total_amount            = (int) ( $data['total_amount'] ?? 0 );
		$this->amount_refunded         = (int) ( $data['amount_refunded'] ?? 0 );
		$this->currency                = $data['currency'] ?? 'usd';
		$this->payment_gateway         = $data['payment_gateway'] ?? '';
		$this->gateway_transaction_id  = $data['gateway_transaction_id'] ?? null;
		$this->gateway_subscription_id = $data['gateway_subscription_id'] ?? null;
		$this->gateway_customer_id     = $data['gateway_customer_id'] ?? '';
		$this->is_anonymous            = (bool) ( $data['is_anonymous'] ?? false );
		$this->is_test                 = (bool) ( $data['is_test'] ?? false );
		$this->donor_ip                = $data['donor_ip'] ?? '';
		$this->date_created            = $data['date_created'] ?? current_time( 'mysql', true );
		$this->date_completed          = $data['date_completed'] ?? null;
		$this->date_refunded           = $data['date_refunded'] ?? null;
		$this->date_modified           = $data['date_modified'] ?? current_time( 'mysql', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new TransactionDataStore();
	}

	/**
	 * Get the donor for this transaction.
	 *
	 * @return Donor|null
	 */
	public function donor(): ?Donor {
		return $this->donor_id ? Donor::find( $this->donor_id ) : null;
	}

	/**
	 * Get the campaign for this transaction.
	 *
	 * @return Campaign|null
	 */
	public function campaign(): ?Campaign {
		return $this->campaign_id ? Campaign::find( $this->campaign_id ) : null;
	}

	/**
	 * Get the subscription for this transaction.
	 *
	 * @return Subscription|null
	 */
	public function subscription(): ?Subscription {
		return $this->subscription_id ? Subscription::find( $this->subscription_id ) : null;
	}

	/**
	 * Get notes for this transaction.
	 *
	 * @param string|null $type Optional note type filter ('internal' or 'donor').
	 *
	 * @return Note[]
	 */
	public function notes( ?string $type = null ): array {
		$args = [
			'object_type' => 'transaction',
			'object_id'   => $this->id,
		];

		if ( $type ) {
			$args['type'] = $type;
		}

		return Note::query( $args );
	}

	/**
	 * Get the tribute for this transaction.
	 *
	 * @return Tribute|null
	 */
	public function tribute(): ?Tribute {
		return $this->id ? Tribute::find_by_transaction_id( $this->id ) : null;
	}

	/**
	 * Get history entries for this transaction.
	 *
	 * @return TransactionHistory[]
	 */
	public function history(): array {
		return TransactionHistory::query(
			[
				'transaction_id' => $this->id,
				'order'          => 'DESC',
			]
		);
	}
}
