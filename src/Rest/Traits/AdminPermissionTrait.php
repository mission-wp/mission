<?php
/**
 * Shared permission check for admin-only REST endpoints.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Traits;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Provides check_admin_permission() for endpoints that require manage_options.
 */
trait AdminPermissionTrait {

	/**
	 * Permission callback: require the manage_options capability.
	 *
	 * @return bool|WP_Error
	 */
	public function check_admin_permission(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			$this->permission_denied_message(),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Message returned when the capability check fails. Override per endpoint.
	 *
	 * @return string
	 */
	protected function permission_denied_message(): string {
		return __( 'You do not have permission to perform this action.', 'mission-donation-platform' );
	}
}
