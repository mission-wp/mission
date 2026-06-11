<?php
/**
 * Writes normalized donor records.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Writers;

use MissionDP\Models\Donor;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Creates Mission donors from normalized records. A pre-existing Mission donor
 * with the same email is reused (stamped with the source ID for reference
 * resolution, but not marked as created). Aggregate fields stay at zero and
 * are recomputed in the finalize phase.
 */
class DonorWriter extends AbstractWriter {

	/**
	 * Write a batch of donor records.
	 *
	 * @param array<int, array<string, mixed>> $records Normalized donor records.
	 *
	 * @return array{imported: int, skipped: int, errors: int, error_details: array<int, array{source_id: int, message: string}>}
	 */
	public function write_batch( array $records ): array {
		$result = $this->empty_result();

		$source_map = Donor::find_ids_by_meta(
			$this->source_key( 'donor_id' ),
			array_column( $records, 'source_id' )
		);

		foreach ( $records as $record ) {
			$source_id = (int) $record['source_id'];

			try {
				if ( isset( $source_map[ (string) $source_id ] ) ) {
					++$result['skipped'];
					continue;
				}

				$existing = Donor::find_by_email( (string) $record['email'] );

				if ( $existing ) {
					// Reuse the Mission donor; stamp it so references resolve, but
					// never mark it created — rollback must not delete it.
					$existing->update_meta( $this->source_key( 'donor_id' ), $source_id );
					++$result['skipped'];
					continue;
				}

				$data = [
					'email'      => (string) $record['email'],
					'first_name' => (string) $record['first_name'],
					'last_name'  => (string) $record['last_name'],
					'address_1'  => (string) $record['address_1'],
					'address_2'  => (string) $record['address_2'],
					'city'       => (string) $record['city'],
					'state'      => (string) $record['state'],
					'zip'        => (string) $record['zip'],
				];

				// Leave these unset when empty so the model defaults apply.
				if ( ! empty( $record['user_id'] ) ) {
					$data['user_id'] = (int) $record['user_id'];
				}
				if ( '' !== (string) $record['country'] ) {
					$data['country'] = (string) $record['country'];
				}
				if ( '' !== (string) $record['date_created'] ) {
					$data['date_created'] = (string) $record['date_created'];
				}

				$donor = new Donor( $data );
				$donor->save();

				$donor->update_meta( $this->source_key( 'donor_id' ), $source_id );
				$donor->update_meta( self::CREATED_META_KEY, $this->job_id );

				foreach ( (array) ( $record['meta'] ?? [] ) as $key => $value ) {
					$donor->update_meta( (string) $key, $value );
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
