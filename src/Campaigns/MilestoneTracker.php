<?php
/**
 * Milestone tracker for campaigns.
 *
 * Computes and stores milestone data (with dates) in campaign meta.
 * Milestones are recompiled when aggregates change or the goal is updated.
 *
 * @package Mission
 */

namespace Mission\Campaigns;

use Mission\Models\Campaign;
use Mission\Models\Transaction;

defined( 'ABSPATH' ) || exit;

class MilestoneTracker {

	/**
	 * Register event listeners.
	 */
	public function init(): void {
		add_action( 'mission_campaign_created', [ $this, 'on_campaign_created' ] );
		add_action( 'mission_campaign_aggregates_updated', [ $this, 'recompile' ], 10, 2 );
		add_action( 'mission_campaign_goal_updated', [ $this, 'recompile' ] );
	}

	/**
	 * Initialize milestones when a campaign is created.
	 *
	 * @param \Mission\Models\Campaign $campaign The campaign.
	 */
	public function on_campaign_created( \Mission\Models\Campaign $campaign ): void {
		$milestones = [
			[
				'id'      => 'created',
				'reached' => true,
				'date'    => $campaign->date_created,
			],
			[
				'id'      => 'first-donation',
				'reached' => false,
			],
		];

		if ( $campaign->goal_amount > 0 ) {
			foreach ( [ 25, 50, 75, 100 ] as $pct ) {
				$milestones[] = [
					'id'      => "{$pct}-pct",
					'reached' => false,
				];
			}
		}

		$campaign->update_meta( 'milestones', $milestones );
	}

	/**
	 * Recompile milestones for a campaign.
	 *
	 * Reads current aggregates and walks completed transactions to determine
	 * when each threshold was crossed. Stores the result in campaign meta.
	 *
	 * @param int $campaign_id The campaign ID.
	 */
	public function recompile( int $campaign_id, bool $is_test = false ): void {
		$campaign = Campaign::find( $campaign_id );
		if ( ! $campaign ) {
			return;
		}

		$goal      = $campaign->goal_amount;
		$progress  = $campaign->get_goal_progress( $is_test );
		$txn_count = $is_test ? $campaign->test_transaction_count : $campaign->transaction_count;

		$milestones = [
			[
				'id'      => 'created',
				'reached' => true,
				'date'    => $campaign->date_created,
			],
		];

		// First donation milestone.
		$first_donation = [
			'id'      => 'first-donation',
			'reached' => $txn_count > 0,
		];

		// Percentage thresholds (only relevant if there's a goal).
		$thresholds           = [ 25, 50, 75, 100 ];
		$threshold_milestones = [];

		if ( $goal > 0 ) {
			foreach ( $thresholds as $pct ) {
				$threshold_milestones[] = [
					'id'      => "{$pct}-pct",
					'target'  => (int) round( $goal * ( $pct / 100 ) ),
					'reached' => $progress >= round( $goal * ( $pct / 100 ) ),
				];
			}
		}

		// If we need dates, query transactions.
		$has_reached_thresholds = array_filter( $threshold_milestones, fn( $m ) => $m['reached'] );
		$needs_dates            = $first_donation['reached'] || $has_reached_thresholds;

		if ( $needs_dates ) {
			$dates                = $this->find_milestone_dates( $campaign_id, $first_donation, $threshold_milestones, $is_test, $campaign->goal_type );
			$first_donation       = $dates['first_donation'];
			$threshold_milestones = $dates['thresholds'];
		}

		$milestones[] = $first_donation;

		foreach ( $threshold_milestones as $tm ) {
			unset( $tm['target'] );
			$milestones[] = $tm;
		}

		// Detect newly-reached milestones before saving.
		$old_milestones = $campaign->get_meta( 'milestones' ) ?: [];
		$old_reached    = [];
		foreach ( $old_milestones as $m ) {
			if ( ! empty( $m['reached'] ) ) {
				$old_reached[ $m['id'] ] = true;
			}
		}

		$campaign->update_meta( 'milestones', $milestones );

		// Fire an action for each milestone that just transitioned to reached.
		foreach ( $milestones as $m ) {
			if ( ! empty( $m['reached'] ) && empty( $old_reached[ $m['id'] ] ) ) {
				/**
				 * Fires when a campaign milestone is reached for the first time.
				 *
				 * @param Campaign $campaign     The campaign.
				 * @param string   $milestone_id Milestone ID (e.g. 'first-donation', '25-pct', '100-pct').
				 * @param bool     $is_test      Whether the triggering transaction is a test.
				 */
				do_action( 'mission_campaign_milestone_reached', $campaign, $m['id'], $is_test );
			}
		}
	}

	/**
	 * Query completed transactions to find when milestones were crossed.
	 *
	 * @param int   $campaign_id          The campaign ID.
	 * @param array $first_donation       First donation milestone data.
	 * @param array $threshold_milestones Percentage threshold milestones.
	 *
	 * @return array{first_donation: array, thresholds: array}
	 */
	private function find_milestone_dates( int $campaign_id, array $first_donation, array $threshold_milestones, bool $is_test = false, string $goal_type = 'amount' ): array {
		$transactions = Transaction::query(
			[
				'campaign_id' => $campaign_id,
				'status'      => 'completed',
				'is_test'     => (int) $is_test,
				'orderby'     => 'date_completed',
				'order'       => 'ASC',
			]
		);

		if ( empty( $transactions ) ) {
			return [
				'first_donation' => $first_donation,
				'thresholds'     => $threshold_milestones,
			];
		}

		// First donation date.
		if ( $first_donation['reached'] ) {
			$first_donation['date'] = $transactions[0]->date_completed;
		}

		// Walk transactions with running total to find threshold dates.
		if ( ! empty( $threshold_milestones ) ) {
			$running_total   = 0;
			$seen_donors     = [];
			$threshold_idx   = 0;
			$threshold_count = count( $threshold_milestones );

			foreach ( $transactions as $txn ) {
				match ( $goal_type ) {
					'donations' => ++$running_total,
					'donors'    => $seen_donors[ $txn->donor_id ] = true,
					default     => $running_total                += (int) $txn->amount,
				};

				$current_progress = 'donors' === $goal_type ? count( $seen_donors ) : $running_total;

				while ( $threshold_idx < $threshold_count
					&& $threshold_milestones[ $threshold_idx ]['reached']
					&& $current_progress >= $threshold_milestones[ $threshold_idx ]['target']
				) {
					$threshold_milestones[ $threshold_idx ]['date'] = $txn->date_completed;
					++$threshold_idx;
				}

				if ( $threshold_idx >= $threshold_count ) {
					break;
				}
			}
		}

		return [
			'first_donation' => $first_donation,
			'thresholds'     => $threshold_milestones,
		];
	}
}
