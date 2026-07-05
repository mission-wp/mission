<?php
/**
 * Finalize phase: link subscriptions to their transactions and recompute aggregates.
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
 * Runs after all entities are written, when both sides of the
 * subscription/transaction relationship exist. Cursor-paginates over the
 * subscriptions this run created, fills in initial_transaction_id,
 * renewal_count, and total_renewed, then (once the cursor is exhausted)
 * recomputes every touched donor and campaign aggregate.
 */
class FinalizeWriter extends AbstractWriter {

	/**
	 * Process the next batch of created subscriptions.
	 *
	 * @param int $after_id Last processed Mission subscription ID.
	 * @param int $limit    Max subscriptions to process.
	 *
	 * @return array{processed: int, errors: int, error_details: array<int, array{source_id: int, message: string}>, max_id: int, done: bool}
	 */
	public function process_batch( int $after_id, int $limit ): array {
		$subscription_ids = Subscription::find_ids_with_meta( self::CREATED_META_KEY, $this->job_id, $limit, $after_id );

		if ( empty( $subscription_ids ) ) {
			( new TouchedEntities( $this->job_id ) )->recompute_and_clear();

			return [
				'processed'     => 0,
				'errors'        => 0,
				'error_details' => [],
				'max_id'        => $after_id,
				'done'          => true,
			];
		}

		$errors        = 0;
		$error_details = [];

		foreach ( $subscription_ids as $subscription_id ) {
			try {
				$subscription = Subscription::find( $subscription_id );

				if ( ! $subscription ) {
					continue;
				}

				$this->link_subscription( $subscription );
			} catch ( Throwable $e ) {
				++$errors;
				$error_details[] = $this->error_entry( $subscription_id, $e->getMessage() );
			}
		}

		return [
			'processed'     => count( $subscription_ids ),
			'errors'        => $errors,
			'error_details' => $error_details,
			'max_id'        => max( $subscription_ids ),
			'done'          => false,
		];
	}

	/**
	 * Fill in a subscription's transaction links and renewal totals.
	 *
	 * @param Subscription $subscription Subscription to update.
	 */
	private function link_subscription( Subscription $subscription ): void {
		$parent_payment_id = (int) $subscription->get_meta( $this->source_key( 'parent_payment_id' ) );

		$initial_id = null;
		if ( $parent_payment_id > 0 ) {
			$map        = Transaction::find_ids_by_meta( $this->source_key( 'payment_id' ), [ $parent_payment_id ] );
			$initial_id = $map[ (string) $parent_payment_id ] ?? null;
		}

		$transactions = Transaction::query(
			[
				'subscription_id' => $subscription->id,
				'per_page'        => 1000,
			]
		);

		$renewal_count = 0;
		$total_renewed = 0;
		foreach ( $transactions as $transaction ) {
			if ( Transaction::STATUS_COMPLETED !== $transaction->status || $transaction->id === $initial_id ) {
				continue;
			}

			++$renewal_count;
			$total_renewed += $transaction->total_amount;
		}

		if ( null === $initial_id && 0 === $renewal_count && 0 === $subscription->renewal_count ) {
			return;
		}

		$subscription->initial_transaction_id = $initial_id;
		$subscription->renewal_count          = $renewal_count;
		$subscription->total_renewed          = $total_renewed;

		$subscription->save_silent();
	}
}
