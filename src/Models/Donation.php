<?php
/**
 * Donation model.
 *
 * @package Mission
 */

namespace Mission\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Typed data object representing a single donation.
 */
class Donation {

	public ?int $id;
	public string $status;
	public string $type;
	public int $donor_id;
	public ?int $subscription_id;
	public ?int $parent_id;
	public int $form_id;
	public ?int $campaign_id;
	public int $amount;
	public int $fee_amount;
	public int $tip_amount;
	public int $total_amount;
	public string $currency;
	public string $payment_gateway;
	public ?string $gateway_transaction_id;
	public ?string $gateway_subscription_id;
	public bool $is_anonymous;
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
	public function __construct( array $data = array() ) {
		$this->id                      = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->status                  = $data['status'] ?? 'pending';
		$this->type                    = $data['type'] ?? 'one_time';
		$this->donor_id                = (int) ( $data['donor_id'] ?? 0 );
		$this->subscription_id         = isset( $data['subscription_id'] ) ? (int) $data['subscription_id'] : null;
		$this->parent_id               = isset( $data['parent_id'] ) ? (int) $data['parent_id'] : null;
		$this->form_id                 = (int) ( $data['form_id'] ?? 0 );
		$this->campaign_id             = isset( $data['campaign_id'] ) ? (int) $data['campaign_id'] : null;
		$this->amount                  = (int) ( $data['amount'] ?? 0 );
		$this->fee_amount              = (int) ( $data['fee_amount'] ?? 0 );
		$this->tip_amount              = (int) ( $data['tip_amount'] ?? 0 );
		$this->total_amount            = (int) ( $data['total_amount'] ?? 0 );
		$this->currency                = $data['currency'] ?? 'usd';
		$this->payment_gateway         = $data['payment_gateway'] ?? '';
		$this->gateway_transaction_id  = $data['gateway_transaction_id'] ?? null;
		$this->gateway_subscription_id = $data['gateway_subscription_id'] ?? null;
		$this->is_anonymous            = (bool) ( $data['is_anonymous'] ?? false );
		$this->donor_ip                = $data['donor_ip'] ?? '';
		$this->date_created            = $data['date_created'] ?? current_time( 'mysql', true );
		$this->date_completed          = $data['date_completed'] ?? null;
		$this->date_refunded           = $data['date_refunded'] ?? null;
		$this->date_modified           = $data['date_modified'] ?? current_time( 'mysql', true );
	}
}
