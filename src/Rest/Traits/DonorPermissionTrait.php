<?php
/**
 * Shared permission check for donor-facing REST endpoints.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Traits;

use MissionDP\Models\Donor;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Provides check_donor_permission() for endpoints that require an authenticated donor.
 */
trait DonorPermissionTrait {

	/**
	 * Permission callback: require a logged-in user linked to a donor record.
	 *
	 * @return bool|WP_Error
	 */
	public function check_donor_permission(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'mission-donation-platform' ), [ 'status' => 401 ] );
		}

		if ( ! Donor::find_by_user_id( get_current_user_id() ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Access denied.', 'mission-donation-platform' ), [ 'status' => 403 ] );
		}

		return true;
	}
}
