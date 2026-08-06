<?php
/**
 * Writes normalized subscription records.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Writers;

use MissionDP\Models\Subscription;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Creates Mission subscriptions via Subscription::save_silent() so no listener
 * (emails, webhooks, activity feed) fires for historical rows. Renewal counts
 * and the initial transaction link are filled in by the finalize phase, after
 * transactions exist.
 */
class SubscriptionWriter extends AbstractWriter {

	/**
	 * Write a batch of subscription records.
	 *
	 * @param array<int, array<string, mixed>> $records Normalized subscription records.
	 *
	 * @return array{imported: int, skipped: int, errors: int, error_details: array<int, array{source_id: int, message: string}>}
	 */
	public function write_batch( array $records ): array {
		$result = $this->empty_result();

		$source_map    = Subscription::find_ids_by_meta(
			$this->source_key( 'subscription_id' ),
			array_column( $records, 'source_id' )
		);
		$gateway_map   = Subscription::find_ids_by_gateway_subscription_ids(
			array_column( $records, 'gateway_subscription_id' )
		);
		$donor_map     = $this->map_donors( $records );
		$campaign_maps = $this->map_campaigns( $records );

		foreach ( $records as $record ) {
			$source_id = (int) $record['source_id'];

			try {
				if ( isset( $source_map[ (string) $source_id ] ) ) {
					++$result['skipped'];
					continue;
				}

				// A subscription may already exist for this gateway ID (e.g. CSV-imported earlier).
				// Stamp it so transactions resolve, but never mark it created: rollback must not delete it.
				$gateway_id  = (string) ( $record['gateway_subscription_id'] ?? '' );
				$existing_id = '' !== $gateway_id ? ( $gateway_map[ $gateway_id ] ?? null ) : null;

				if ( null !== $existing_id ) {
					$existing = Subscription::find( $existing_id );
					if ( $existing ) {
						$existing->update_meta( $this->source_key( 'subscription_id' ), $source_id );
						++$result['skipped'];
						continue;
					}
				}

				$donor_id = $donor_map[ (string) (int) $record['source_donor_id'] ] ?? 0;

				if ( $donor_id <= 0 ) {
					++$result['errors'];
					$result['error_details'][] = $this->error_entry(
						$source_id,
						__( 'The subscription donor was not migrated, so the subscription was skipped.', 'mission-donation-platform' )
					);
					continue;
				}

				$subscription = new Subscription(
					[
						'status'                  => (string) $record['status'],
						'donor_id'                => $donor_id,
						'campaign_id'             => $this->resolve_campaign_id( $record, $campaign_maps ),
						'amount'                  => (int) $record['amount'],
						'total_amount'            => (int) $record['total_amount'],
						'currency'                => (string) $record['currency'],
						'frequency'               => (string) $record['frequency'],
						'payment_gateway'         => (string) $record['payment_gateway'],
						'gateway_subscription_id' => $record['gateway_subscription_id'],
						'gateway_customer_id'     => $record['gateway_customer_id'],
						'is_test'                 => (bool) $record['is_test'],
						'date_created'            => (string) $record['date_created'],
						'date_next_renewal'       => $record['date_next_renewal'],
					]
				);
				$subscription->save_silent();

				// Dedupe source rows sharing a gateway ID within the batch.
				if ( '' !== (string) $subscription->gateway_subscription_id ) {
					$gateway_map[ (string) $subscription->gateway_subscription_id ] ??= $subscription->id;
				}

				$subscription->update_meta( $this->source_key( 'subscription_id' ), $source_id );
				$subscription->update_meta( self::CREATED_META_KEY, $this->job_id );

				foreach ( (array) ( $record['meta'] ?? [] ) as $key => $value ) {
					$subscription->update_meta( (string) $key, $value );
				}

				foreach ( (array) ( $record['warnings'] ?? [] ) as $warning ) {
					// Informational only — surfaced in the receipt without counting as an error.
					$result['error_details'][] = $this->error_entry( $source_id, (string) $warning );
				}

				++$result['imported'];
			} catch ( Throwable $e ) {
				++$result['errors'];
				$result['error_details'][] = $this->error_entry( $source_id, $e->getMessage() );
			}
		}

		return $result;
	}
}
