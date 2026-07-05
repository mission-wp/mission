<?php
/**
 * Rollback: delete the records a migration run created.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Writers;

use MissionDP\Migration\TouchedEntities;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes only records carrying the created marker for the target job, in
 * reverse dependency order (transactions, subscriptions, donors, campaigns).
 * Pre-existing Mission records that the migration merely matched (stamped
 * with a source ID but never marked created) are left untouched; their
 * aggregates are recomputed after the deletions.
 */
class RollbackWriter extends AbstractWriter {

	/**
	 * Entity phases in deletion order.
	 *
	 * @return string[]
	 */
	public static function get_phases(): array {
		return [ 'transactions', 'subscriptions', 'donors', 'campaigns' ];
	}

	/**
	 * Count records the target job created for one entity.
	 *
	 * @param string $entity Entity phase name.
	 */
	public function count( string $entity ): int {
		$count = 0;
		$after = 0;

		do {
			$ids    = $this->model_class( $entity )::find_ids_with_meta( self::CREATED_META_KEY, $this->job_id, 500, $after );
			$count += count( $ids );
			$after  = empty( $ids ) ? 0 : max( $ids );
		} while ( ! empty( $ids ) );

		return $count;
	}

	/**
	 * Delete the next batch of created records for an entity.
	 *
	 * Cursor-paginated over Mission IDs so a record that fails to delete is
	 * skipped past instead of retried forever.
	 *
	 * @param string $entity   Entity phase name.
	 * @param int    $after_id Last processed Mission ID.
	 * @param int    $limit    Max records to delete.
	 *
	 * @return array{processed: int, errors: int, error_details: array<int, array{source_id: int, message: string}>, max_id: int, done: bool}
	 */
	public function delete_batch( string $entity, int $after_id, int $limit ): array {
		$model_class = $this->model_class( $entity );
		$ids         = $model_class::find_ids_with_meta( self::CREATED_META_KEY, $this->job_id, $limit, $after_id );

		if ( empty( $ids ) ) {
			return [
				'processed'     => 0,
				'errors'        => 0,
				'error_details' => [],
				'max_id'        => $after_id,
				'done'          => true,
			];
		}

		$touched           = new TouchedEntities( 'rollback_' . $this->job_id );
		$touched_donors    = [];
		$touched_campaigns = [];
		$errors            = 0;
		$error_details     = [];

		foreach ( $ids as $id ) {
			try {
				$model = $model_class::find( $id );

				if ( ! $model ) {
					continue;
				}

				if ( $model instanceof Transaction ) {
					$touched_donors[] = $model->donor_id;
					if ( $model->campaign_id ) {
						$touched_campaigns[] = $model->campaign_id;
					}
				}

				if ( $model instanceof Campaign ) {
					// CampaignDataStore::delete() does not cascade meta; remove our stamps first.
					$model->delete_meta( self::CREATED_META_KEY );
					$model->delete_meta( $this->source_key( 'campaign_id' ) );
					$model->delete_meta( $this->source_key( 'form_id' ) );
					$model->trash();
				} else {
					$model->delete();
				}
			} catch ( Throwable $e ) {
				++$errors;
				$error_details[] = $this->error_entry( $id, $e->getMessage() );
			}
		}

		$touched->track_many( 'donors', $touched_donors );
		$touched->track_many( 'campaigns', $touched_campaigns );

		return [
			'processed'     => count( $ids ),
			'errors'        => $errors,
			'error_details' => $error_details,
			'max_id'        => max( $ids ),
			'done'          => false,
		];
	}

	/**
	 * Recompute aggregates for surviving donors/campaigns whose transactions
	 * were deleted. Called once after all entity deletions finish.
	 */
	public function finish(): void {
		( new TouchedEntities( 'rollback_' . $this->job_id ) )->recompute_and_clear();
		// The migration run's own touched transient is now stale too.
		( new TouchedEntities( $this->job_id ) )->clear();
	}

	/**
	 * Model class for an entity phase.
	 *
	 * @param string $entity Entity phase name.
	 *
	 * @return class-string<Transaction|Subscription|Donor|Campaign>
	 */
	private function model_class( string $entity ): string {
		return match ( $entity ) {
			'transactions'  => Transaction::class,
			'subscriptions' => Subscription::class,
			'donors'        => Donor::class,
			default         => Campaign::class,
		};
	}
}
