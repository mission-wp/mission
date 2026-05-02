<?php
/**
 * Shared donor resolution for donor-facing REST endpoints.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Traits;

use MissionDP\Models\Donor;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Provides resolve_donor() and check_donor_permission() for donor dashboard endpoints.
 */
trait ResolveDonorTrait {

	use DonorPermissionTrait;

	/**
	 * Resolve the current donor from the authenticated user.
	 *
	 * @return Donor|WP_Error
	 */
	private function resolve_donor(): Donor|WP_Error {
		$donor = Donor::find_by_user_id( get_current_user_id() );

		if ( ! $donor ) {
			return new WP_Error( 'donor_not_found', __( 'Donor record not found.', 'mission-donation-platform' ), [ 'status' => 404 ] );
		}

		return $donor;
	}
}
