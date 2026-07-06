<?php
/**
 * REST endpoint for peer-to-peer teams.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Rest\Args;
use MissionDP\Rest\RestErrors;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Teams endpoint class.
 */
class TeamsEndpoint extends AbstractP2PAdminEndpoint {

	/**
	 * The model class this endpoint manages.
	 *
	 * @return class-string
	 */
	protected function model_class(): string {
		return Team::class;
	}

	/**
	 * The route slug.
	 *
	 * @return string
	 */
	protected function route_base(): string {
		return 'teams';
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
	 * Message for a create attempt against a non-P2P campaign.
	 *
	 * @return string
	 */
	protected function invalid_campaign_type_message(): string {
		return __( 'Teams can only be added to peer-to-peer campaigns.', 'mission-donation-platform' );
	}

	/**
	 * The not-found error for teams.
	 *
	 * @return WP_Error
	 */
	protected function not_found_error(): WP_Error {
		return RestErrors::team_not_found();
	}

	/**
	 * Allowed orderby values for the collection route.
	 *
	 * @return string[]
	 */
	protected function allowed_orderby(): array {
		return [ 'date_created', 'name', 'goal', 'status' ];
	}

	/**
	 * Run the list query with campaign/captain/aggregate data.
	 *
	 * @param array $args Query args.
	 * @return array{items: array, total: int}
	 */
	protected function query_list( array $args ): array {
		return $this->reporting->teams_with_relations( $args );
	}

	/**
	 * Aggregate team stats.
	 *
	 * @return array<string, mixed>
	 */
	protected function summary(): array {
		return $this->reporting->team_summary();
	}

	/**
	 * POST handler — creates a team for a P2P campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign = $this->find_p2p_campaign( (int) $request->get_param( 'campaign_id' ) );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
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

		if ( $captain_id ) {
			$captain = Fundraiser::find( (int) $captain_id );

			// move_to_team() vacates any previous captaincy before joining.
			if ( ! $captain->move_to_team( $team, true ) ) {
				$team->delete();

				return new WP_Error(
					'rest_cannot_create',
					__( 'The team could not be created.', 'mission-donation-platform' ),
					[ 'status' => 500 ]
				);
			}
		}

		return new WP_REST_Response( $this->prepare_item( $team ), 201 );
	}

	/**
	 * PUT handler — updates a team's editable fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$team = Team::find( $request->get_param( 'id' ) );

		if ( ! $team ) {
			return RestErrors::team_not_found();
		}

		// Handle the captain first: a rejected captain must not leave the other
		// field changes committed.
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

		$this->apply_status_transition( $team, $request->get_param( 'status' ) );

		return new WP_REST_Response( $this->prepare_item( $team ), 200 );
	}

	/**
	 * Validate a captain selection: must be a fundraiser in the same campaign.
	 *
	 * @param mixed $captain_id  Raw captain_id param (may be empty/null).
	 * @param int   $campaign_id Campaign the team belongs to.
	 * @return WP_Error|null Error when invalid, null when valid or unset.
	 */
	private function validate_captain( mixed $captain_id, int $campaign_id ): ?WP_Error {
		return $this->validate_campaign_relation(
			$captain_id,
			$campaign_id,
			Fundraiser::class,
			'invalid_captain',
			__( 'The selected captain is not a fundraiser in this campaign.', 'mission-donation-platform' )
		);
	}

	/**
	 * Prepare a team model for a REST response.
	 *
	 * @param object $item Team model.
	 * @return array<string, mixed>
	 */
	protected function prepare_item( object $item ): array {
		/** @var Team $item */
		$campaign = $item->campaign();
		$captain  = $item->captain();

		$totals   = $this->reporting->team_totals( (int) $item->id );
		$rank     = $this->reporting->team_rank( (int) $item->id );
		$progress = $item->goal > 0 ? min( 100.0, round( $totals['raised'] / $item->goal * 100, 2 ) ) : 0.0;

		return [
			'id'                 => (int) $item->id,
			'campaign_id'        => $item->campaign_id,
			'campaign_title'     => $campaign?->title ?? '',
			'campaign_end_date'  => $campaign?->date_end,
			'campaign_days_left' => $campaign?->days_left(),
			'name'               => $item->name,
			'description'        => $item->description,
			'goal'               => $item->goal,
			'status'             => $item->status,
			'access'             => $item->access,
			'captain_id'         => $item->captain_id,
			'captain_name'       => $captain?->donor()?->full_name() ?? '',
			'member_count'       => $totals['member_count'],
			'raised'             => $totals['raised'],
			'donation_count'     => $totals['donations'],
			'progress'           => $progress,
			'rank'               => $rank['rank'],
			'rank_total'         => $rank['total'],
			'page_url'           => $item->get_url() ?? '',
			'cover_image'        => $item->cover_image,
			'date_created'       => $item->date_created,
			'date_modified'      => $item->date_modified,
		];
	}

	/**
	 * Create endpoint parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_create_params(): array {
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
	protected function get_update_params(): array {
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
}
