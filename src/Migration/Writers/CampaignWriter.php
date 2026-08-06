<?php
/**
 * Writes normalized campaign records.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Writers;

use MissionDP\Models\Campaign;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Creates Mission campaigns from normalized records (the model creates the
 * linked WP post). A pre-existing Mission campaign with the same title is
 * reused, mirroring the donor email dedupe.
 */
class CampaignWriter extends AbstractWriter {

	/**
	 * Write a batch of campaign records.
	 *
	 * @param array<int, array<string, mixed>> $records Normalized campaign records.
	 *
	 * @return array{imported: int, skipped: int, errors: int, error_details: array<int, array{source_id: int, message: string}>}
	 */
	public function write_batch( array $records ): array {
		$result = $this->empty_result();

		$maps = [];
		foreach ( $records as $record ) {
			$maps[ (string) $record['source_meta_key'] ][] = $record['source_id'];
		}
		foreach ( $maps as $meta_key => $source_ids ) {
			$maps[ $meta_key ] = Campaign::find_ids_by_meta( $meta_key, $source_ids );
		}

		foreach ( $records as $record ) {
			$source_id = (int) $record['source_id'];
			$stamp_key = (string) $record['source_meta_key'];

			try {
				if ( isset( $maps[ $stamp_key ][ (string) $source_id ] ) ) {
					++$result['skipped'];
					continue;
				}

				$existing = Campaign::find_by_title( (string) $record['title'] );

				if ( $existing ) {
					// Reuse the Mission campaign; stamp it so references resolve,
					// but never mark it created — rollback must not delete it.
					$existing->update_meta( $stamp_key, $source_id );
					++$result['skipped'];
					continue;
				}

				$data = [
					'title'       => (string) $record['title'],
					'description' => (string) $record['description'],
					'goal_amount' => (int) $record['goal_amount'],
					'goal_type'   => (string) $record['goal_type'],
					'status'      => (string) $record['status'],
					'date_start'  => $record['date_start'],
					'date_end'    => $record['date_end'],
				];

				if ( '' !== (string) ( $record['date_created'] ?? '' ) ) {
					$data['date_created'] = (string) $record['date_created'];
				}

				$campaign = new Campaign( $data );
				$campaign->save();

				$campaign->update_meta( $stamp_key, $source_id );
				$campaign->update_meta( self::CREATED_META_KEY, $this->job_id );

				++$result['imported'];
			} catch ( Throwable $e ) {
				++$result['errors'];
				$result['error_details'][] = $this->error_entry( $source_id, $e->getMessage() );
			}
		}

		return $result;
	}
}
