<?php
/**
 * Subscription model.
 *
 * @package Mission
 */

namespace Mission\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Typed data object representing a recurring subscription.
 */
class Subscription {

	public ?int $id;
	public string $status;
	public int $donor_id;
	public int $form_id;
	public ?int $campaign_id;
	public ?int $initial_donation_id;
	public int $amount;
	public int $fee_amount;
	public int $tip_amount;
	public int $total_amount;
	public string $currency;
	public string $frequency;
	public string $payment_gateway;
	public ?string $gateway_subscription_id;
	public ?string $gateway_customer_id;
	public int $renewal_count;
	public int $total_renewed;
	public string $date_created;
	public ?string $date_next_renewal;
	public ?string $date_cancelled;
	public ?string $date_expired;
	public string $date_modified;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = array() ) {
		$this->id                      = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->status                  = $data['status'] ?? 'pending';
		$this->donor_id                = (int) ( $data['donor_id'] ?? 0 );
		$this->form_id                 = (int) ( $data['form_id'] ?? 0 );
		$this->campaign_id             = isset( $data['campaign_id'] ) ? (int) $data['campaign_id'] : null;
		$this->initial_donation_id     = isset( $data['initial_donation_id'] ) ? (int) $data['initial_donation_id'] : null;
		$this->amount                  = (int) ( $data['amount'] ?? 0 );
		$this->fee_amount              = (int) ( $data['fee_amount'] ?? 0 );
		$this->tip_amount              = (int) ( $data['tip_amount'] ?? 0 );
		$this->total_amount            = (int) ( $data['total_amount'] ?? 0 );
		$this->currency                = $data['currency'] ?? 'usd';
		$this->frequency               = $data['frequency'] ?? 'monthly';
		$this->payment_gateway         = $data['payment_gateway'] ?? '';
		$this->gateway_subscription_id = $data['gateway_subscription_id'] ?? null;
		$this->gateway_customer_id     = $data['gateway_customer_id'] ?? null;
		$this->renewal_count           = (int) ( $data['renewal_count'] ?? 0 );
		$this->total_renewed           = (int) ( $data['total_renewed'] ?? 0 );
		$this->date_created            = $data['date_created'] ?? current_time( 'mysql', true );
		$this->date_next_renewal       = $data['date_next_renewal'] ?? null;
		$this->date_cancelled          = $data['date_cancelled'] ?? null;
		$this->date_expired            = $data['date_expired'] ?? null;
		$this->date_modified           = $data['date_modified'] ?? current_time( 'mysql', true );
	}
}
