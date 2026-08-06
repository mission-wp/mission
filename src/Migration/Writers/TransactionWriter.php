<?php
/**
 * Writes normalized transaction records.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Writers;

use MissionDP\Migration\TouchedEntities;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Creates Mission transactions via Transaction::save_silent() so no listener
 * fires and no per-row aggregate updates run. Touched donors and campaigns
 * accumulate for one recompute in the finalize phase. Renewal parents resolve
 * within the batch too, since source cursor order migrates parents first.
 */
class TransactionWriter extends AbstractWriter {

	/**
	 * Write a batch of transaction records.
	 *
	 * @param array<int, array<string, mixed>> $records Normalized transaction records.
	 *
	 * @return array{imported: int, skipped: int, errors: int, error_details: array<int, array{source_id: int, message: string}>}
	 */
	public function write_batch( array $records ): array {
		$result  = $this->empty_result();
		$touched = new TouchedEntities( $this->job_id );

		$payment_key = $this->source_key( 'payment_id' );

		$source_map       = Transaction::find_ids_by_meta( $payment_key, array_column( $records, 'source_id' ) );
		$parent_map       = Transaction::find_ids_by_meta( $payment_key, array_column( $records, 'source_parent_id' ) );
		$gateway_map      = Transaction::find_ids_by_gateway_transaction_ids( array_column( $records, 'gateway_transaction_id' ) );
		$donor_map        = $this->map_donors( $records );
		$campaign_maps    = $this->map_campaigns( $records );
		$subscription_map = Subscription::find_ids_by_meta(
			$this->source_key( 'subscription_id' ),
			array_column( $records, 'source_subscription_id' )
		);

		$touched_donors    = [];
		$touched_campaigns = [];

		foreach ( $records as $record ) {
			$source_id = (int) $record['source_id'];

			try {
				if ( isset( $source_map[ (string) $source_id ] ) ) {
					++$result['skipped'];
					continue;
				}

				// Reuse a pre-existing transaction for this gateway charge: stamp it
				// so references resolve, but never mark it created; rollback must not delete it.
				$gateway_id  = (string) ( $record['gateway_transaction_id'] ?? '' );
				$existing_id = '' !== $gateway_id ? ( $gateway_map[ $gateway_id ] ?? null ) : null;

				if ( null !== $existing_id ) {
					$existing = Transaction::find( $existing_id );
					if ( $existing ) {
						$existing->update_meta( $payment_key, $source_id );
						$parent_map[ (string) $source_id ] = $existing->id;
						++$result['skipped'];
						continue;
					}
				}

				$donor_id = $donor_map[ (string) (int) $record['source_donor_id'] ] ?? 0;

				if ( $donor_id <= 0 ) {
					++$result['errors'];
					$result['error_details'][] = $this->error_entry(
						$source_id,
						__( 'The donation donor was not migrated, so the donation was skipped.', 'mission-donation-platform' )
					);
					continue;
				}

				$campaign_id     = $this->resolve_campaign_id( $record, $campaign_maps );
				$subscription_id = $subscription_map[ (string) (int) $record['source_subscription_id'] ] ?? null;
				$parent_id       = $parent_map[ (string) (int) $record['source_parent_id'] ] ?? null;

				$transaction = new Transaction(
					[
						'status'                 => (string) $record['status'],
						'type'                   => (string) $record['type'],
						'donor_id'               => $donor_id,
						'campaign_id'            => $campaign_id,
						'subscription_id'        => $subscription_id,
						'parent_id'              => $parent_id,
						'amount'                 => (int) $record['amount'],
						'total_amount'           => (int) $record['total_amount'],
						'currency'               => (string) $record['currency'],
						'payment_gateway'        => (string) $record['payment_gateway'],
						'gateway_transaction_id' => $record['gateway_transaction_id'],
						'is_test'                => (bool) $record['is_test'],
						'is_anonymous'           => (bool) $record['is_anonymous'],
						'donor_ip'               => (string) $record['donor_ip'],
						'date_created'           => (string) $record['date_created'],
						'date_completed'         => $record['date_completed'],
					]
				);
				$transaction->save_silent();

				// Make renewals later in this batch resolve their parent without a
				// re-query, and dedupe source rows sharing a gateway ID in-batch.
				$parent_map[ (string) $source_id ] = $transaction->id;
				if ( '' !== (string) $transaction->gateway_transaction_id ) {
					$gateway_map[ (string) $transaction->gateway_transaction_id ] ??= $transaction->id;
				}

				$transaction->update_meta( $payment_key, $source_id );
				$transaction->update_meta( self::CREATED_META_KEY, $this->job_id );

				foreach ( (array) ( $record['meta'] ?? [] ) as $key => $value ) {
					$transaction->update_meta( (string) $key, $value );
				}

				$touched_donors[] = $donor_id;
				if ( null !== $campaign_id ) {
					$touched_campaigns[] = $campaign_id;
				}

				++$result['imported'];
			} catch ( Throwable $e ) {
				++$result['errors'];
				$result['error_details'][] = $this->error_entry( $source_id, $e->getMessage() );
			}
		}

		$touched->track_many( 'donors', $touched_donors );
		$touched->track_many( 'campaigns', $touched_campaigns );

		return $result;
	}
}
