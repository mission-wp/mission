<?php
/**
 * Campaign model.
 *
 * @package Mission
 */

namespace Mission\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Typed data object representing a campaign's financial aggregates.
 *
 * Title, slug, description, and status live on the associated wp_posts row
 * (mission_campaign CPT). This model tracks only the custom table data.
 */
class Campaign {

	public ?int $id;
	public int $post_id;
	public int $goal_amount;
	public int $total_raised;
	public int $transaction_count;
	public string $currency;
	public ?string $date_start;
	public ?string $date_end;
	public string $date_created;
	public string $date_modified;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = array() ) {
		$this->id                = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->post_id           = (int) ( $data['post_id'] ?? 0 );
		$this->goal_amount       = (int) ( $data['goal_amount'] ?? 0 );
		$this->total_raised      = (int) ( $data['total_raised'] ?? 0 );
		$this->transaction_count = (int) ( $data['transaction_count'] ?? 0 );
		$this->currency          = $data['currency'] ?? 'usd';
		$this->date_start        = $data['date_start'] ?? null;
		$this->date_end          = $data['date_end'] ?? null;
		$this->date_created      = $data['date_created'] ?? current_time( 'mysql', true );
		$this->date_modified     = $data['date_modified'] ?? current_time( 'mysql', true );
	}
}
