<?php
/**
 * Resolves campaign/fundraiser/team attribution for a donation.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest;

use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the raw campaign/fundraiser/team IDs a donation form submits into a
 * consistent, validated attribution triple.
 *
 * A fundraiser or team is authoritative for its campaign, so the campaign is
 * derived from it (the submitted campaign_id can't disagree). A fundraiser-page
 * gift credits the fundraiser only; its team total rolls up live from member
 * fundraisers. Unknown fundraiser/team IDs are dropped, leaving a plain campaign
 * donation rather than a broken attribution.
 */
class DonationAttribution {

	/**
	 * Resolve the attribution for a donation.
	 *
	 * @param int $campaign_id   Submitted campaign ID (table ID).
	 * @param int $fundraiser_id Submitted fundraiser ID.
	 * @param int $team_id       Submitted team ID.
	 * @return array{campaign_id: int|null, fundraiser_id: int|null, team_id: int|null}
	 */
	public static function resolve( int $campaign_id, int $fundraiser_id, int $team_id ): array {
		if ( $fundraiser_id ) {
			$fundraiser = Fundraiser::find( $fundraiser_id );

			if ( $fundraiser ) {
				return [
					'campaign_id'   => $fundraiser->campaign_id,
					'fundraiser_id' => $fundraiser->id,
					'team_id'       => null,
				];
			}
		} elseif ( $team_id ) {
			$team = Team::find( $team_id );

			if ( $team ) {
				return [
					'campaign_id'   => $team->campaign_id,
					'fundraiser_id' => null,
					'team_id'       => $team->id,
				];
			}
		}

		$campaign = $campaign_id ? Campaign::find( $campaign_id ) : null;

		return [
			'campaign_id'   => $campaign?->id,
			'fundraiser_id' => null,
			'team_id'       => null,
		];
	}
}
