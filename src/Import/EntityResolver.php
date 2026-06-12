<?php
/**
 * Resolves import row references (donor, campaign, transaction) to record IDs.
 *
 * @package MissionDP
 */

namespace MissionDP\Import;

use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Transaction;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ID resolution with per-batch lookup caches.
 *
 * Cache values are int IDs, or 0 for "we already looked and found nothing"
 * so we don't re-query.
 *
 * @internal Serves the import pipeline; ImportService is the public API.
 */
class EntityResolver {

	/**
	 * Per-batch cache for donor lookups, keyed by lookup string (email or ID).
	 *
	 * @var array<string, int>
	 */
	private array $donor_lookup_cache = [];

	/**
	 * Per-batch cache for campaign lookups, keyed by lookup string (title or ID).
	 *
	 * @var array<string, int>
	 */
	private array $campaign_lookup_cache = [];

	/**
	 * Per-batch cache for transaction lookups, keyed by lookup string (gateway ID or ID).
	 *
	 * @var array<string, int>
	 */
	private array $transaction_lookup_cache = [];

	/**
	 * Reset all lookup caches. Called at the top of each batch so caches don't
	 * grow unbounded across a multi-batch import.
	 */
	public function reset(): void {
		$this->donor_lookup_cache       = [];
		$this->campaign_lookup_cache    = [];
		$this->transaction_lookup_cache = [];
	}

	/**
	 * Resolve a row's donor reference to a donor ID.
	 *
	 * @param array<string, string> $row Mapped row.
	 * @return int|WP_Error
	 */
	public function resolve_donor_id( array $row ): int|WP_Error {
		if ( isset( $row['donor_id'] ) && '' !== trim( (string) $row['donor_id'] ) ) {
			$id = (int) trim( (string) $row['donor_id'] );

			if ( $id > 0 ) {
				/* translators: %d: donor ID */
				$not_found_message = __( 'No donor with ID %d.', 'mission-donation-platform' );

				$cache_key = "id:{$id}";
				if ( isset( $this->donor_lookup_cache[ $cache_key ] ) ) {
					if ( 0 === $this->donor_lookup_cache[ $cache_key ] ) {
						return new WP_Error( 'donor_not_found', sprintf( $not_found_message, $id ) );
					}
					return $this->donor_lookup_cache[ $cache_key ];
				}

				$donor                                  = Donor::find( $id );
				$this->donor_lookup_cache[ $cache_key ] = $donor ? $donor->id : 0;

				if ( $donor ) {
					return $donor->id;
				}

				return new WP_Error( 'donor_not_found', sprintf( $not_found_message, $id ) );
			}
		}

		$email = strtolower( trim( (string) ( $row['donor_email'] ?? '' ) ) );

		if ( '' === $email ) {
			return new WP_Error( 'donor_missing', __( 'Row has no donor_email or donor_id.', 'mission-donation-platform' ) );
		}

		/* translators: %s: donor email */
		$not_found_message = __( 'No donor found for email "%s". Import donors first.', 'mission-donation-platform' );

		$cache_key = "email:{$email}";
		if ( isset( $this->donor_lookup_cache[ $cache_key ] ) ) {
			if ( 0 === $this->donor_lookup_cache[ $cache_key ] ) {
				return new WP_Error( 'donor_not_found', sprintf( $not_found_message, $email ) );
			}
			return $this->donor_lookup_cache[ $cache_key ];
		}

		$donor                                  = Donor::find_by_email( $email );
		$this->donor_lookup_cache[ $cache_key ] = $donor ? $donor->id : 0;

		if ( $donor ) {
			return $donor->id;
		}

		return new WP_Error( 'donor_not_found', sprintf( $not_found_message, $email ) );
	}

	/**
	 * Resolve a row's campaign reference to a campaign ID, or null if no
	 * campaign is referenced (a valid case).
	 *
	 * @param array<string, string> $row Mapped row.
	 * @return int|null|WP_Error
	 */
	public function resolve_campaign_id( array $row ): int|null|WP_Error {
		if ( isset( $row['campaign_id'] ) && '' !== trim( (string) $row['campaign_id'] ) ) {
			$id = (int) trim( (string) $row['campaign_id'] );

			if ( $id > 0 ) {
				/* translators: %d: campaign ID */
				$not_found_message = __( 'No campaign with ID %d.', 'mission-donation-platform' );

				$cache_key = "id:{$id}";
				if ( isset( $this->campaign_lookup_cache[ $cache_key ] ) ) {
					if ( 0 === $this->campaign_lookup_cache[ $cache_key ] ) {
						return new WP_Error( 'campaign_not_found', sprintf( $not_found_message, $id ) );
					}
					return $this->campaign_lookup_cache[ $cache_key ];
				}

				$campaign                                  = Campaign::find( $id );
				$this->campaign_lookup_cache[ $cache_key ] = $campaign ? $campaign->id : 0;

				if ( $campaign ) {
					return $campaign->id;
				}

				return new WP_Error( 'campaign_not_found', sprintf( $not_found_message, $id ) );
			}
		}

		$title = trim( (string) ( $row['campaign_title'] ?? '' ) );

		if ( '' === $title ) {
			return null;
		}

		/* translators: %s: campaign title */
		$not_found_message = __( 'No campaign found with title "%s".', 'mission-donation-platform' );

		$cache_key = 'title:' . strtolower( $title );
		if ( isset( $this->campaign_lookup_cache[ $cache_key ] ) ) {
			if ( 0 === $this->campaign_lookup_cache[ $cache_key ] ) {
				return new WP_Error( 'campaign_not_found', sprintf( $not_found_message, $title ) );
			}
			return $this->campaign_lookup_cache[ $cache_key ];
		}

		$matches                                   = Campaign::find_all_by_title( $title );
		$this->campaign_lookup_cache[ $cache_key ] = $matches ? $matches[0]->id : 0;

		if ( $matches ) {
			return $matches[0]->id;
		}

		return new WP_Error( 'campaign_not_found', sprintf( $not_found_message, $title ) );
	}

	/**
	 * Resolve a dedication row's transaction, by Charge ID (gateway_transaction_id)
	 * first, then transaction_id. Caches lookups per batch.
	 *
	 * @param array<string, string> $row Mapped row.
	 * @return int|WP_Error Transaction ID, or error if the row references none / an unknown one.
	 */
	public function resolve_transaction_id( array $row ): int|WP_Error {
		$charge_id = trim( (string) ( $row['gateway_transaction_id'] ?? '' ) );

		if ( '' !== $charge_id ) {
			/* translators: %s: Charge ID (gateway transaction id) */
			$not_found_message = __( 'No transaction found for Charge ID "%s". Import transactions first.', 'mission-donation-platform' );
			$cache_key         = "charge:{$charge_id}";

			if ( isset( $this->transaction_lookup_cache[ $cache_key ] ) ) {
				if ( 0 === $this->transaction_lookup_cache[ $cache_key ] ) {
					return new WP_Error( 'transaction_not_found', sprintf( $not_found_message, $charge_id ) );
				}
				return $this->transaction_lookup_cache[ $cache_key ];
			}

			$transaction                                  = Transaction::find_by_gateway_transaction_id( $charge_id );
			$this->transaction_lookup_cache[ $cache_key ] = $transaction ? $transaction->id : 0;

			if ( $transaction ) {
				return $transaction->id;
			}

			return new WP_Error( 'transaction_not_found', sprintf( $not_found_message, $charge_id ) );
		}

		$id = (int) trim( (string) ( $row['transaction_id'] ?? '' ) );

		if ( $id <= 0 ) {
			return new WP_Error( 'transaction_missing', __( 'Row has no transaction_id or Charge ID.', 'mission-donation-platform' ) );
		}

		/* translators: %d: transaction ID */
		$not_found_message = __( 'No transaction with ID %d.', 'mission-donation-platform' );
		$cache_key         = "id:{$id}";

		if ( isset( $this->transaction_lookup_cache[ $cache_key ] ) ) {
			if ( 0 === $this->transaction_lookup_cache[ $cache_key ] ) {
				return new WP_Error( 'transaction_not_found', sprintf( $not_found_message, $id ) );
			}
			return $this->transaction_lookup_cache[ $cache_key ];
		}

		$transaction                                  = Transaction::find( $id );
		$this->transaction_lookup_cache[ $cache_key ] = $transaction ? $transaction->id : 0;

		if ( $transaction ) {
			return $transaction->id;
		}

		return new WP_Error( 'transaction_not_found', sprintf( $not_found_message, $id ) );
	}
}
