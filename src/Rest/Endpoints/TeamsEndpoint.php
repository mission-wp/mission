<?php
/**
 * REST endpoint for peer-to-peer teams.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Reporting\ReportingService;
use MissionDP\Rest\Args;
use MissionDP\Rest\CollectionParams;
use MissionDP\Rest\RestErrors;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\AdminPermissionTrait;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Teams endpoint class.
 */
class TeamsEndpoint {

	use AdminPermissionTrait;

	/**
	 * Constructor.
	 *
	 * @param ReportingService $reporting Reporting service.
	 * @param SettingsService  $settings  Settings service.
	 */
	public function __construct(
		private ReportingService $reporting,
		private SettingsService $settings,
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/teams',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_teams' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_team' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => $this->get_create_params(),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/teams/summary',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_summary' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/teams/bulk',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'bulk_action' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => $this->get_bulk_params(),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/teams/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_team' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [ 'id' => Args::id() ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_team' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => $this->get_update_params(),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_team' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [ 'id' => Args::id() ],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/teams/(?P<id>\d+)/approve',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'approve_team' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [ 'id' => Args::id() ],
			]
		);
	}

	/**
	 * Message returned when the capability check fails.
	 *
	 * @return string
	 */
	protected function permission_denied_message(): string {
		return __( 'You do not have permission to view teams.', 'mission-donation-platform' );
	}

	/**
	 * GET handler — paginated teams with campaign/captain/aggregate data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_teams( WP_REST_Request $request ): WP_REST_Response {
		$per_page = $request->get_param( 'per_page' ) ?? 25;

		$result = $this->reporting->teams_with_relations(
			[
				'per_page'    => $per_page,
				'page'        => $request->get_param( 'page' ) ?? 1,
				'orderby'     => $request->get_param( 'orderby' ) ?? 'date_created',
				'order'       => $request->get_param( 'order' ) ?? 'DESC',
				'search'      => $request->get_param( 'search' ),
				'campaign_id' => $request->get_param( 'campaign_id' ),
				'status'      => $request->get_param( 'status' ),
			]
		);

		$total       = $result['total'];
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		$response = new WP_REST_Response( $result['items'], 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * GET handler — aggregate team stats.
	 *
	 * @return WP_REST_Response
	 */
	public function get_summary(): WP_REST_Response {
		return new WP_REST_Response( $this->reporting->team_summary(), 200 );
	}

	/**
	 * GET handler — a single team with detail fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_team( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$team = Team::find( $request->get_param( 'id' ) );

		if ( ! $team ) {
			return RestErrors::team_not_found();
		}

		return new WP_REST_Response( $this->prepare_team( $team ), 200 );
	}

	/**
	 * POST handler — creates a team for a P2P campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_team( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign = Campaign::find( (int) $request->get_param( 'campaign_id' ) );

		if ( ! $campaign ) {
			return RestErrors::campaign_not_found();
		}

		if ( ! $campaign->is_p2p() ) {
			return new WP_Error(
				'invalid_campaign_type',
				__( 'Teams can only be added to peer-to-peer campaigns.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$captain_id    = $request->get_param( 'captain_id' );
		$captain_error = $this->validate_captain( $captain_id, $campaign->id );
		if ( $captain_error ) {
			return $captain_error;
		}

		$settings = $campaign->p2p_settings();
		$goal     = $request->get_param( 'goal' );

		$team = new Team(
			[
				'campaign_id' => $campaign->id,
				'name'        => sanitize_text_field( $request->get_param( 'name' ) ),
				'description' => wp_kses_post( $request->get_param( 'description' ) ?? '' ),
				'goal'        => null !== $goal ? max( 0, (int) $goal ) : (int) $settings['default_team_goal'],
				'access'      => $request->get_param( 'access' ) ?? Team::ACCESS_PUBLIC,
				'status'      => $request->get_param( 'status' ) ?? Team::STATUS_ACTIVE,
				'cover_image' => sanitize_text_field( $request->get_param( 'cover_image' ) ?? '' ),
			]
		);

		if ( ! $team->save() ) {
			return new WP_Error(
				'rest_cannot_create',
				__( 'The team could not be created.', 'mission-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		// The captain joins through the model path so their team_id and captain
		// flag stay in sync with the team's captain_id.
		if ( $captain_id ) {
			$captain = Fundraiser::find( (int) $captain_id );
			$captain->join_team( $team, true );
			$team->set_captain( $captain );
		}

		return new WP_REST_Response( $this->prepare_team( $team ), 201 );
	}

	/**
	 * PUT handler — updates a team's editable fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_team( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$team = Team::find( $request->get_param( 'id' ) );

		if ( ! $team ) {
			return RestErrors::team_not_found();
		}

		if ( null !== $request->get_param( 'name' ) ) {
			$team->name = sanitize_text_field( $request->get_param( 'name' ) );
		}

		if ( null !== $request->get_param( 'description' ) ) {
			$team->description = wp_kses_post( $request->get_param( 'description' ) );
		}

		if ( null !== $request->get_param( 'goal' ) ) {
			$team->goal = max( 0, (int) $request->get_param( 'goal' ) );
		}

		if ( null !== $request->get_param( 'access' ) ) {
			$team->access = $request->get_param( 'access' );
		}

		if ( null !== $request->get_param( 'cover_image' ) ) {
			$team->cover_image = sanitize_text_field( $request->get_param( 'cover_image' ) );
		}

		$team->save();

		// Captain changes go through the model so both sides of the association
		// stay in sync (captain_id and the members' is_team_captain flags).
		if ( $request->has_param( 'captain_id' ) ) {
			$captain_id = $request->get_param( 'captain_id' );

			if ( empty( $captain_id ) ) {
				$team->clear_captain();
			} else {
				$captain = Fundraiser::find( (int) $captain_id );

				if ( ! $captain ) {
					return new WP_Error(
						'invalid_captain',
						__( 'The selected captain is not a member of this team.', 'mission-donation-platform' ),
						[ 'status' => 400 ]
					);
				}

				$result = $team->promote_captain( $captain );
				if ( is_wp_error( $result ) ) {
					$result->add_data( [ 'status' => 400 ] );
					return $result;
				}
			}
		}

		// Status transitions go through the model methods so approval fires the
		// email/activity events (a plain field write would approve silently).
		$status = $request->get_param( 'status' );
		if ( null !== $status && $status !== $team->status ) {
			if ( Team::STATUS_ACTIVE === $status ) {
				$team->approve();
			} elseif ( Team::STATUS_INACTIVE === $status ) {
				$team->deactivate();
			} else {
				$team->status = $status;
				$team->save();
			}
		}

		return new WP_REST_Response( $this->prepare_team( $team ), 200 );
	}

	/**
	 * DELETE handler — removes a team record.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_team( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$team = Team::find( $request->get_param( 'id' ) );

		if ( ! $team ) {
			return RestErrors::team_not_found();
		}

		$team->delete();

		return new WP_REST_Response(
			[
				'deleted' => true,
				'id'      => (int) $request->get_param( 'id' ),
			],
			200
		);
	}

	/**
	 * POST handler — approves a single team.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_team( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$team = Team::find( $request->get_param( 'id' ) );

		if ( ! $team ) {
			return RestErrors::team_not_found();
		}

		$team->approve();

		return new WP_REST_Response( $this->prepare_team( $team ), 200 );
	}

	/**
	 * POST handler — bulk approve or deactivate teams.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function bulk_action( WP_REST_Request $request ): WP_REST_Response {
		$action  = $request->get_param( 'action' );
		$ids     = $request->get_param( 'ids' );
		$updated = [];
		$errors  = [];

		foreach ( $ids as $id ) {
			$team = Team::find( $id );

			if ( ! $team ) {
				$errors[] = $id;
				continue;
			}

			$result = 'approve' === $action ? $team->approve() : $team->deactivate();

			if ( $result ) {
				$updated[] = $id;
			} else {
				$errors[] = $id;
			}
		}

		return new WP_REST_Response(
			[
				'updated' => $updated,
				'errors'  => $errors,
			],
			200
		);
	}

	/**
	 * Validate a captain selection: must be a fundraiser in the same campaign.
	 *
	 * @param mixed $captain_id  Raw captain_id param (may be empty/null).
	 * @param int   $campaign_id Campaign the team belongs to.
	 * @return WP_Error|null Error when invalid, null when valid or unset.
	 */
	private function validate_captain( mixed $captain_id, int $campaign_id ): ?WP_Error {
		if ( empty( $captain_id ) ) {
			return null;
		}

		$captain = Fundraiser::find( (int) $captain_id );

		if ( ! $captain || $captain->campaign_id !== $campaign_id ) {
			return new WP_Error(
				'invalid_captain',
				__( 'The selected captain is not a fundraiser in this campaign.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		return null;
	}

	/**
	 * Prepare a team model for a REST response.
	 *
	 * @param Team $team Team model.
	 * @return array<string, mixed>
	 */
	private function prepare_team( Team $team ): array {
		$is_test  = (bool) $this->settings->get( 'test_mode' );
		$campaign = $team->campaign();
		$captain  = $team->captain();

		return [
			'id'             => (int) $team->id,
			'campaign_id'    => $team->campaign_id,
			'campaign_title' => $campaign?->title ?? '',
			'name'           => $team->name,
			'description'    => $team->description,
			'goal'           => $team->goal,
			'status'         => $team->status,
			'access'         => $team->access,
			'captain_id'     => $team->captain_id,
			'captain_name'   => $captain?->donor()?->full_name() ?? '',
			'member_count'   => $team->member_count(),
			'raised'         => $team->amount_raised( $is_test ),
			'progress'       => $team->progress( $is_test ),
			'cover_image'    => $team->cover_image,
			'date_created'   => $team->date_created,
			'date_modified'  => $team->date_modified,
		];
	}

	/**
	 * Collection query parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_collection_params(): array {
		return array_merge(
			CollectionParams::base(
				orderby: [ 'date_created', 'name', 'goal', 'status' ],
				default_orderby: 'date_created'
			),
			[
				'campaign_id' => Args::integer(),
				'status'      => Args::enum( Team::STATUSES ),
			]
		);
	}

	/**
	 * Create endpoint parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_create_params(): array {
		return [
			'campaign_id' => Args::integer( [ 'required' => true ] ),
			'name'        => Args::string( [ 'required' => true ] ),
			'description' => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'wp_kses_post',
			],
			'goal'        => Args::integer(),
			'access'      => Args::enum( Team::ACCESS_LEVELS, [ 'default' => Team::ACCESS_PUBLIC ] ),
			'status'      => Args::enum( Team::STATUSES, [ 'default' => Team::STATUS_ACTIVE ] ),
			'captain_id'  => Args::integer(),
			'cover_image' => Args::string( [ 'default' => '' ] ),
		];
	}

	/**
	 * Update endpoint parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_update_params(): array {
		return [
			'id'          => Args::id(),
			'name'        => Args::string(),
			'description' => [
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			],
			'goal'        => Args::integer(),
			'access'      => Args::enum( Team::ACCESS_LEVELS ),
			'status'      => Args::enum( Team::STATUSES ),
			'captain_id'  => [
				'type' => [ 'integer', 'null' ],
			],
			'cover_image' => Args::string(),
		];
	}

	/**
	 * Bulk-action endpoint parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_bulk_params(): array {
		return [
			'action' => Args::enum( [ 'approve', 'deactivate' ], [ 'required' => true ] ),
			'ids'    => [
				'type'              => 'array',
				'required'          => true,
				'items'             => [ 'type' => 'integer' ],
				'sanitize_callback' => static fn( $ids ) => array_map( 'absint', (array) $ids ),
			],
		];
	}
}
