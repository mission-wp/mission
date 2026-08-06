<?php
/**
 * Admin REST endpoint for managing team members.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Rest\Args;
use MissionDP\Rest\RestErrors;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\AdminPermissionTrait;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Team members endpoint class.
 *
 * Admin-side promote/remove for team members. Business rules (captain guard,
 * flag syncing, domain events) live in the Team model.
 */
class TeamMembersEndpoint {

	use AdminPermissionTrait;

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/teams/(?P<id>\d+)/members/(?P<fundraiser_id>\d+)/promote',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'promote_member' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => $this->get_member_params(),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/teams/(?P<id>\d+)/members/(?P<fundraiser_id>\d+)/remove',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'remove_member' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => $this->get_member_params(),
			]
		);
	}

	/**
	 * Message returned when the capability check fails.
	 *
	 * @return string
	 */
	protected function permission_denied_message(): string {
		return __( 'You do not have permission to manage teams.', 'mission-donation-platform' );
	}

	/**
	 * POST handler — promotes a member to team captain.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function promote_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run_member_action( $request, 'promote_captain' );
	}

	/**
	 * POST handler — removes a member from the team.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run_member_action( $request, 'remove_member' );
	}

	/**
	 * Resolve the team and member, then run a Team model member action.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $method  Team model method: promote_captain or remove_member.
	 * @return WP_REST_Response|WP_Error
	 */
	private function run_member_action( WP_REST_Request $request, string $method ): WP_REST_Response|WP_Error {
		$team = Team::find( (int) $request->get_param( 'id' ) );

		if ( ! $team ) {
			return RestErrors::team_not_found();
		}

		$member = Fundraiser::find( (int) $request->get_param( 'fundraiser_id' ) );

		if ( ! $member ) {
			return new WP_Error( 'member_not_found', __( 'Member not found.', 'mission-donation-platform' ), [ 'status' => 404 ] );
		}

		$result = $team->$method( $member );

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 400 ] );
			return $result;
		}

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Route parameters shared by both member actions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_member_params(): array {
		return [
			'id'            => Args::id(),
			'fundraiser_id' => Args::id(),
		];
	}
}
