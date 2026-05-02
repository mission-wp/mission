<?php
/**
 * Shared permission check for donor-facing REST endpoints.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Traits;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Provides check_donor_permission() for endpoints that require an authenticated donor.
 */
trait DonorPermissionTrait {

	/**
	 * Permission callback: require an authenticated donor.
	 *
	 * @return bool|WP_Error
	 */
	public function check_donor_permission(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'mission-donation-platform' ), [ 'status' => 401 ] );
		}

		$user = wp_get_current_user();

		if ( ! in_array( 'missiondp_donor', $user->roles, true ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Access denied.', 'mission-donation-platform' ), [ 'status' => 403 ] );
		}

		return true;
	}
}
