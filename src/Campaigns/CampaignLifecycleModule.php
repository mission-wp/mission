<?php
/**
 * Campaign lifecycle module — manages status transitions and end-of-campaign actions.
 *
 * Owns all campaign status transition logic. Status transitions are triggered by:
 * - Hourly cron: date-based transitions (scheduled->active, active->ended).
 * - Aggregate update hook: close-on-goal (active->ended when goal is reached).
 * - REST save hook: immediate recomputation when admin changes dates.
 * - Campaign creation hook: sets initial status based on start date.
 *
 * @package MissionDP
 */

namespace MissionDP\Campaigns;

use MissionDP\Models\Campaign;
use MissionDP\Models\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Campaign lifecycle module class.
 */
class CampaignLifecycleModule {

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'missiondp_campaign_lifecycle';

	/**
	 * Number of subscriptions to process per batch during end-of-campaign actions.
	 *
	 * @var int
	 */
	private const SUBSCRIPTION_BATCH_SIZE = 50;

	/**
	 * Initialize the module.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::CRON_HOOK, [ $this, 'process_transitions' ] );
		add_action( 'init', [ $this, 'ensure_cron_scheduled' ] );
		add_action( 'mission_campaign_aggregates_updated', [ $this, 'check_close_on_goal' ] );
		add_action( 'mission_campaign_updated', [ $this, 'maybe_update_status_on_save' ], 10, 2 );
		add_action( 'mission_campaign_created', [ $this, 'set_initial_status' ] );
	}

	/**
	 * Ensure the lifecycle cron is scheduled.
	 *
	 * @return void
	 */
	public function ensure_cron_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Transition a campaign to a new status.
	 *
	 * Central method for all status changes. Updates the DB, fires hooks,
	 * and executes end-of-campaign actions when transitioning to 'ended'.
	 *
	 * @param Campaign $campaign   The campaign.
	 * @param string   $new_status The new status ('active', 'scheduled', or 'ended').
	 * @param string   $reason     Why the transition occurred (e.g. 'cron', 'goal_reached', 'date_change').
	 *
	 * @return bool True if the transition occurred, false if no change.
	 */
	public function transition_status( Campaign $campaign, string $new_status, string $reason = 'cron' ): bool {
		$old_status = $campaign->status;

		if ( $old_status === $new_status ) {
			return false;
		}

		$campaign->status = $new_status;
		$campaign->save();

		/**
		 * Fires when a campaign's status changes.
		 *
		 * @param Campaign $campaign   The campaign.
		 * @param string   $old_status Previous status.
		 * @param string   $new_status New status.
		 * @param string   $reason     Reason for the transition.
		 */
		do_action( 'mission_campaign_status_changed', $campaign, $old_status, $new_status, $reason );

		if ( Campaign::STATUS_ENDED === $new_status ) {
			$this->execute_end_actions( $campaign );
		}

		return true;
	}

	/**
	 * Cron callback: scan for date-based status transitions.
	 *
	 * @return void
	 */
	public function process_transitions(): void {
		$today = wp_date( 'Y-m-d' );

		foreach ( Campaign::find_ids_to_activate( $today ) as $id ) {
			$campaign = Campaign::find( $id );
			if ( $campaign ) {
				$this->transition_status( $campaign, Campaign::STATUS_ACTIVE, 'cron' );
			}
		}

		foreach ( Campaign::find_ids_to_end( $today ) as $id ) {
			$campaign = Campaign::find( $id );
			if ( $campaign ) {
				$this->transition_status( $campaign, Campaign::STATUS_ENDED, 'cron' );
			}
		}
	}

	/**
	 * Check if a campaign should be closed because its goal was reached.
	 *
	 * Called synchronously when campaign aggregates are updated (transaction completed).
	 *
	 * @param int $campaign_id The campaign ID.
	 *
	 * @return void
	 */
	public function check_close_on_goal( int $campaign_id ): void {
		$campaign = Campaign::find( $campaign_id );

		if ( ! $campaign || Campaign::STATUS_ACTIVE !== $campaign->status ) {
			return;
		}

		if ( ! $campaign->get_meta( 'close_on_goal' ) ) {
			return;
		}

		if ( $campaign->goal_amount <= 0 ) {
			return;
		}

		// Only check live progress — test donations should not end a campaign.
		if ( $campaign->get_goal_progress( false ) >= $campaign->goal_amount ) {
			$this->transition_status( $campaign, Campaign::STATUS_ENDED, 'goal_reached' );
		}
	}

	/**
	 * Recompute campaign status when dates change via REST update.
	 *
	 * @param int      $campaign_id The campaign ID.
	 * @param Campaign $campaign    The updated campaign.
	 *
	 * @return void
	 */
	public function maybe_update_status_on_save( int $campaign_id, Campaign $campaign ): void {
		$today      = wp_date( 'Y-m-d' );
		$start      = $campaign->date_start ? substr( $campaign->date_start, 0, 10 ) : null;
		$end        = $campaign->date_end ? substr( $campaign->date_end, 0, 10 ) : null;
		$new_status = null;

		switch ( $campaign->status ) {
			case Campaign::STATUS_ACTIVE:
				if ( $start && $start > $today ) {
					$new_status = Campaign::STATUS_SCHEDULED;
				} elseif ( $end && $end < $today ) {
					$new_status = Campaign::STATUS_ENDED;
				}
				break;

			case Campaign::STATUS_SCHEDULED:
				if ( ! $start || $start <= $today ) {
					$new_status = Campaign::STATUS_ACTIVE;
				}
				break;

			case Campaign::STATUS_ENDED:
				if ( ! $end || $end >= $today ) {
					$new_status = ( $start && $start > $today ) ? Campaign::STATUS_SCHEDULED : Campaign::STATUS_ACTIVE;
				}
				break;
		}

		if ( $new_status ) {
			$this->transition_status( $campaign, $new_status, 'date_change' );
		}
	}

	/**
	 * Set the initial status for a newly created campaign.
	 *
	 * @param Campaign $campaign The new campaign.
	 *
	 * @return void
	 */
	public function set_initial_status( Campaign $campaign ): void {
		$today = wp_date( 'Y-m-d' );
		$start = $campaign->date_start ? substr( $campaign->date_start, 0, 10 ) : null;

		if ( $start && $start > $today ) {
			$campaign->status = Campaign::STATUS_SCHEDULED;
			$campaign->save();
		}
	}

	/**
	 * Execute end-of-campaign actions based on campaign meta settings.
	 *
	 * @param Campaign $campaign The ended campaign.
	 *
	 * @return void
	 */
	private function execute_end_actions( Campaign $campaign ): void {
		// stop_donations_on_end defaults to enabled when the meta is missing.
		$stop_donations = $campaign->get_meta( 'stop_donations_on_end' );
		if ( '' === $stop_donations || $stop_donations ) {
			$campaign->set_campaign_page_enabled( false );
		}

		if ( $campaign->get_meta( 'remove_from_listings_on_end' ) ) {
			$campaign->show_in_listings = false;
			$campaign->save();
		}

		$behavior = $campaign->get_meta( 'recurring_end_behavior' ) ?: 'keep';
		if ( 'keep' === $behavior ) {
			return;
		}

		$redirect_campaign_id = null;
		if ( 'redirect' === $behavior ) {
			$redirect_campaign_id = (int) $campaign->get_meta( 'recurring_redirect_campaign' );
			if ( $redirect_campaign_id ) {
				$target = Campaign::find( $redirect_campaign_id );
				if ( ! $target || Campaign::STATUS_ACTIVE !== $target->status ) {
					$redirect_campaign_id = null;
				}
			}
		}

		$this->process_subscriptions( $campaign, $behavior, $redirect_campaign_id );
	}

	/**
	 * Process subscriptions for an ended campaign.
	 *
	 * Each successful cancel or redirect removes the subscription from the
	 * filtered query, so every batch re-queries page 1. Failed cancels stay
	 * active (the API failure surfaces via mission_subscription_api_call_failed),
	 * and a batch with zero successes stops the loop so a down Mission API
	 * can't spin it forever.
	 *
	 * @param Campaign $campaign              The ended campaign.
	 * @param string   $behavior              'cancel' or 'redirect'.
	 * @param int|null $redirect_campaign_id  Target campaign ID for redirects.
	 *
	 * @return void
	 */
	private function process_subscriptions( Campaign $campaign, string $behavior, ?int $redirect_campaign_id ): void {
		while ( true ) {
			$subscriptions = Subscription::query(
				[
					'campaign_id' => $campaign->id,
					'status'      => Subscription::STATUS_ACTIVE,
					'per_page'    => self::SUBSCRIPTION_BATCH_SIZE,
					'page'        => 1,
				]
			);

			if ( empty( $subscriptions ) ) {
				break;
			}

			$succeeded = 0;

			foreach ( $subscriptions as $subscription ) {
				if ( 'cancel' === $behavior ) {
					if ( ! is_wp_error( $subscription->cancel() ) ) {
						++$succeeded;
					}
				} elseif ( 'redirect' === $behavior && $redirect_campaign_id ) {
					$subscription->campaign_id = $redirect_campaign_id;
					$subscription->save();
					++$succeeded;
				}
			}

			if ( 0 === $succeeded ) {
				break;
			}
		}
	}
}
