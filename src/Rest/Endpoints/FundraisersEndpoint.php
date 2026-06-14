<?php
/**
 * REST endpoint for peer-to-peer fundraisers.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
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
 * Fundraisers endpoint class.
 */
class FundraisersEndpoint {

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
			'/fundraisers',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_fundraisers' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_fundraiser' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => $this->get_create_params(),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/fundraisers/summary',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_summary' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/fundraisers/bulk',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'bulk_action' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => $this->get_bulk_params(),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/fundraisers/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_fundraiser' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [ 'id' => Args::id() ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_fundraiser' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => $this->get_update_params(),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_fundraiser' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [ 'id' => Args::id() ],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/fundraisers/(?P<id>\d+)/approve',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'approve_fundraiser' ],
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
		return __( 'You do not have permission to view fundraisers.', 'mission-donation-platform' );
	}

	/**
	 * GET handler — paginated fundraisers with participant/campaign/team names.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_fundraisers( WP_REST_Request $request ): WP_REST_Response {
		$per_page = $request->get_param( 'per_page' ) ?? 25;

		$result = $this->reporting->fundraisers_with_relations(
			[
				'per_page'    => $per_page,
				'page'        => $request->get_param( 'page' ) ?? 1,
				'orderby'     => $request->get_param( 'orderby' ) ?? 'date_created',
				'order'       => $request->get_param( 'order' ) ?? 'DESC',
				'search'      => $request->get_param( 'search' ),
				'campaign_id' => $request->get_param( 'campaign_id' ),
				'team_id'     => $request->get_param( 'team_id' ),
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
	 * GET handler — aggregate fundraiser stats.
	 *
	 * @return WP_REST_Response
	 */
	public function get_summary(): WP_REST_Response {
		return new WP_REST_Response( $this->reporting->fundraiser_summary(), 200 );
	}

	/**
	 * GET handler — a single fundraiser with detail fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_fundraiser( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$fundraiser = Fundraiser::find( $request->get_param( 'id' ) );

		if ( ! $fundraiser ) {
			return RestErrors::fundraiser_not_found();
		}

		return new WP_REST_Response( $this->prepare_fundraiser( $fundraiser ), 200 );
	}

	/**
	 * POST handler — creates a fundraiser for a P2P campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_fundraiser( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign = Campaign::find( (int) $request->get_param( 'campaign_id' ) );

		if ( ! $campaign ) {
			return RestErrors::campaign_not_found();
		}

		if ( ! $campaign->is_p2p() ) {
			return new WP_Error(
				'invalid_campaign_type',
				__( 'Fundraisers can only be added to peer-to-peer campaigns.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$donor = Donor::find( (int) $request->get_param( 'donor_id' ) );

		if ( ! $donor ) {
			return RestErrors::donor_not_found();
		}

		// One fundraiser record per person per campaign.
		$existing = Fundraiser::query(
			[
				'campaign_id' => $campaign->id,
				'donor_id'    => $donor->id,
				'per_page'    => 1,
			]
		);

		if ( $existing ) {
			return new WP_Error(
				'duplicate_fundraiser',
				__( 'This person is already a fundraiser for this campaign.', 'mission-donation-platform' ),
				[ 'status' => 409 ]
			);
		}

		$team_error = $this->validate_team( $request->get_param( 'team_id' ), $campaign->id );
		if ( $team_error ) {
			return $team_error;
		}

		$settings = $campaign->p2p_settings();
		$goal     = $request->get_param( 'goal' );

		$fundraiser = new Fundraiser(
			[
				'campaign_id' => $campaign->id,
				'donor_id'    => $donor->id,
				'team_id'     => $request->get_param( 'team_id' ) ?: null,
				'status'      => $request->get_param( 'status' ) ?? Fundraiser::STATUS_ACTIVE,
				'goal'        => null !== $goal ? max( 0, (int) $goal ) : (int) $settings['default_fundraiser_goal'],
				'headline'    => sanitize_text_field( $request->get_param( 'headline' ) ?? '' ),
				'story'       => wp_kses_post( $request->get_param( 'story' ) ?? '' ),
			]
		);

		if ( ! $fundraiser->save() ) {
			return new WP_Error(
				'rest_cannot_create',
				__( 'The fundraiser could not be created.', 'mission-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( $this->prepare_fundraiser( $fundraiser ), 201 );
	}

	/**
	 * PUT handler — updates a fundraiser's editable fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_fundraiser( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$fundraiser = Fundraiser::find( $request->get_param( 'id' ) );

		if ( ! $fundraiser ) {
			return RestErrors::fundraiser_not_found();
		}

		if ( $request->has_param( 'team_id' ) ) {
			$team_id    = $request->get_param( 'team_id' );
			$team_error = $this->validate_team( $team_id, $fundraiser->campaign_id );
			if ( $team_error ) {
				return $team_error;
			}
			$fundraiser->team_id = $team_id ? (int) $team_id : null;
		}

		if ( null !== $request->get_param( 'goal' ) ) {
			$fundraiser->goal = max( 0, (int) $request->get_param( 'goal' ) );
		}

		if ( null !== $request->get_param( 'headline' ) ) {
			$fundraiser->headline = sanitize_text_field( $request->get_param( 'headline' ) );
		}

		if ( null !== $request->get_param( 'story' ) ) {
			$fundraiser->story = wp_kses_post( $request->get_param( 'story' ) );
		}

		if ( null !== $request->get_param( 'status' ) ) {
			$fundraiser->status = $request->get_param( 'status' );
		}

		if ( null !== $request->get_param( 'is_team_captain' ) ) {
			$fundraiser->is_team_captain = (bool) $request->get_param( 'is_team_captain' );
		}

		$fundraiser->save();

		return new WP_REST_Response( $this->prepare_fundraiser( $fundraiser ), 200 );
	}

	/**
	 * DELETE handler — removes a fundraiser record.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_fundraiser( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$fundraiser = Fundraiser::find( $request->get_param( 'id' ) );

		if ( ! $fundraiser ) {
			return RestErrors::fundraiser_not_found();
		}

		$fundraiser->delete();

		return new WP_REST_Response(
			[
				'deleted' => true,
				'id'      => (int) $request->get_param( 'id' ),
			],
			200
		);
	}

	/**
	 * POST handler — approves a single fundraiser.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_fundraiser( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$fundraiser = Fundraiser::find( $request->get_param( 'id' ) );

		if ( ! $fundraiser ) {
			return RestErrors::fundraiser_not_found();
		}

		$fundraiser->approve();

		return new WP_REST_Response( $this->prepare_fundraiser( $fundraiser ), 200 );
	}

	/**
	 * POST handler — bulk approve or deactivate fundraisers.
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
			$fundraiser = Fundraiser::find( $id );

			if ( ! $fundraiser ) {
				$errors[] = $id;
				continue;
			}

			$result = 'approve' === $action ? $fundraiser->approve() : $fundraiser->deactivate();

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
	 * Validate a team selection: the team must exist and belong to the campaign.
	 *
	 * @param mixed $team_id     Raw team_id param (may be empty/null).
	 * @param int   $campaign_id Campaign the fundraiser belongs to.
	 * @return WP_Error|null Error when invalid, null when valid or unset.
	 */
	private function validate_team( mixed $team_id, int $campaign_id ): ?WP_Error {
		if ( empty( $team_id ) ) {
			return null;
		}

		$team = Team::find( (int) $team_id );

		if ( ! $team || $team->campaign_id !== $campaign_id ) {
			return new WP_Error(
				'invalid_team',
				__( 'The selected team does not belong to this campaign.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		return null;
	}

	/**
	 * Prepare a fundraiser model for a REST response.
	 *
	 * @param Fundraiser $fundraiser Fundraiser model.
	 * @return array<string, mixed>
	 */
	private function prepare_fundraiser( Fundraiser $fundraiser ): array {
		$is_test  = (bool) $this->settings->get( 'test_mode' );
		$donor    = $fundraiser->donor();
		$campaign = $fundraiser->campaign();
		$team     = $fundraiser->team();

		return [
			'id'              => (int) $fundraiser->id,
			'campaign_id'     => $fundraiser->campaign_id,
			'campaign_title'  => $campaign?->title ?? '',
			'donor_id'        => $fundraiser->donor_id,
			'donor_name'      => $donor?->full_name() ?: __( 'Anonymous', 'mission-donation-platform' ),
			'donor_email'     => $donor?->email ?? '',
			'team_id'         => $fundraiser->team_id,
			'team_name'       => $team?->name ?? '',
			'goal'            => $fundraiser->goal,
			'headline'        => $fundraiser->headline,
			'story'           => $fundraiser->story,
			'status'          => $fundraiser->status,
			'is_team_captain' => $fundraiser->is_team_captain,
			'raised'          => $fundraiser->amount_raised( $is_test ),
			'donor_count'     => $is_test ? $fundraiser->test_donor_count : $fundraiser->donor_count,
			'progress'        => $fundraiser->progress( $is_test ),
			'cover_image'     => $fundraiser->cover_image,
			'profile_image'   => $fundraiser->profile_image,
			'date_created'    => $fundraiser->date_created,
			'date_modified'   => $fundraiser->date_modified,
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
				orderby: [ 'date_created', 'goal', 'status', 'raised' ],
				default_orderby: 'date_created'
			),
			[
				'campaign_id' => Args::integer(),
				'team_id'     => Args::integer(),
				'status'      => Args::enum( Fundraiser::STATUSES ),
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
			'donor_id'    => Args::integer( [ 'required' => true ] ),
			'team_id'     => Args::integer(),
			'goal'        => Args::integer(),
			'headline'    => Args::string( [ 'default' => '' ] ),
			'story'       => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'wp_kses_post',
			],
			'status'      => Args::enum( Fundraiser::STATUSES, [ 'default' => Fundraiser::STATUS_ACTIVE ] ),
		];
	}

	/**
	 * Update endpoint parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_update_params(): array {
		return [
			'id'              => Args::id(),
			'team_id'         => [
				'type' => [ 'integer', 'null' ],
			],
			'goal'            => Args::integer(),
			'headline'        => Args::string(),
			'story'           => [
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			],
			'status'          => Args::enum( Fundraiser::STATUSES ),
			'is_team_captain' => Args::boolean(),
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
