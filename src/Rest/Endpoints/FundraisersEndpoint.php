<?php
/**
 * REST endpoint for peer-to-peer fundraisers.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Rest\Args;
use MissionDP\Rest\RestErrors;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Fundraisers endpoint class.
 */
class FundraisersEndpoint extends AbstractP2PAdminEndpoint {

	/**
	 * The model class this endpoint manages.
	 *
	 * @return class-string
	 */
	protected function model_class(): string {
		return Fundraiser::class;
	}

	/**
	 * The route slug.
	 *
	 * @return string
	 */
	protected function route_base(): string {
		return 'fundraisers';
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
	 * Message for a create attempt against a non-P2P campaign.
	 *
	 * @return string
	 */
	protected function invalid_campaign_type_message(): string {
		return __( 'Fundraisers can only be added to peer-to-peer campaigns.', 'mission-donation-platform' );
	}

	/**
	 * The not-found error for fundraisers.
	 *
	 * @return WP_Error
	 */
	protected function not_found_error(): WP_Error {
		return RestErrors::fundraiser_not_found();
	}

	/**
	 * Allowed orderby values for the collection route.
	 *
	 * @return string[]
	 */
	protected function allowed_orderby(): array {
		return [ 'date_created', 'goal', 'status', 'raised' ];
	}

	/**
	 * Fundraisers can also be filtered by team.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function extra_collection_params(): array {
		return [ 'team_id' => Args::integer() ];
	}

	/**
	 * Pass the team filter through to the list query.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>
	 */
	protected function extra_list_args( WP_REST_Request $request ): array {
		return [ 'team_id' => $request->get_param( 'team_id' ) ];
	}

	/**
	 * Run the list query with participant/campaign/team names.
	 *
	 * @param array $args Query args.
	 * @return array{items: array, total: int}
	 */
	protected function query_list( array $args ): array {
		return $this->reporting->fundraisers_with_relations( $args );
	}

	/**
	 * Aggregate fundraiser stats.
	 *
	 * @return array<string, mixed>
	 */
	protected function summary(): array {
		return $this->reporting->fundraiser_summary();
	}

	/**
	 * POST handler — creates a fundraiser for a P2P campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign = $this->find_p2p_campaign( (int) $request->get_param( 'campaign_id' ) );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$donor = Donor::find( (int) $request->get_param( 'donor_id' ) );

		if ( ! $donor ) {
			return RestErrors::donor_not_found();
		}

		if ( $this->find_existing( $campaign->id, $donor->id ) ) {
			return $this->duplicate_error();
		}

		$team_id    = $request->get_param( 'team_id' );
		$team_error = $this->validate_team( $team_id, $campaign->id );
		if ( $team_error ) {
			return $team_error;
		}

		$settings = $campaign->p2p_settings();
		$goal     = $request->get_param( 'goal' );

		$fundraiser = Fundraiser::register(
			$campaign->id,
			$donor->id,
			null !== $goal ? max( 0, (int) $goal ) : (int) $settings['default_fundraiser_goal'],
			wp_kses_post( $request->get_param( 'story' ) ?? '' ),
			sanitize_text_field( $request->get_param( 'headline' ) ?? '' ),
			$request->get_param( 'status' ) ?? Fundraiser::STATUS_ACTIVE
		);

		if ( ! $fundraiser->id ) {
			// A lost create race on the (campaign, donor) pair is a duplicate;
			// anything else is a plain failed insert.
			if ( $this->find_existing( $campaign->id, $donor->id ) ) {
				return $this->duplicate_error();
			}

			return new WP_Error(
				'rest_cannot_create',
				__( 'The fundraiser could not be created.', 'mission-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		$team = $team_id ? Team::find( (int) $team_id ) : null;
		if ( $team ) {
			$fundraiser->join_team( $team );
		}

		return new WP_REST_Response( $this->prepare_item( $fundraiser ), 201 );
	}

	/**
	 * PUT handler — updates a fundraiser's editable fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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
			$fundraiser->move_to_team( $team_id ? Team::find( (int) $team_id ) : null );
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

		$fundraiser->save();

		if ( $request->has_param( 'dedication_type' ) || $request->has_param( 'dedication_name' ) ) {
			// Merge the stored dedication so a partial update changes one
			// field instead of clearing both (matches the donor dashboard).
			$existing = $fundraiser->dedication();

			$fundraiser->set_dedication(
				$request->has_param( 'dedication_type' )
					? $request->get_param( 'dedication_type' )
					: ( $existing['type'] ?? '' ),
				$request->has_param( 'dedication_name' )
					? sanitize_text_field( (string) $request->get_param( 'dedication_name' ) )
					: ( $existing['name'] ?? '' )
			);
		}

		$this->apply_status_transition( $fundraiser, $request->get_param( 'status' ) );

		return new WP_REST_Response( $this->prepare_item( $fundraiser ), 200 );
	}

	/**
	 * Find an existing fundraiser for a (campaign, donor) pair.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $donor_id    Donor ID.
	 * @return Fundraiser|null
	 */
	private function find_existing( int $campaign_id, int $donor_id ): ?Fundraiser {
		$existing = Fundraiser::query(
			[
				'campaign_id' => $campaign_id,
				'donor_id'    => $donor_id,
				'per_page'    => 1,
			]
		);

		return $existing[0] ?? null;
	}

	/**
	 * The error for a second fundraiser on the same campaign.
	 *
	 * @return WP_Error
	 */
	private function duplicate_error(): WP_Error {
		return new WP_Error(
			'duplicate_fundraiser',
			__( 'This person is already a fundraiser for this campaign.', 'mission-donation-platform' ),
			[ 'status' => 409 ]
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
		return $this->validate_campaign_relation(
			$team_id,
			$campaign_id,
			Team::class,
			'invalid_team',
			__( 'The selected team does not belong to this campaign.', 'mission-donation-platform' )
		);
	}

	/**
	 * Prepare a fundraiser model for a REST response.
	 *
	 * @param object $item Fundraiser model.
	 * @return array<string, mixed>
	 */
	protected function prepare_item( object $item ): array {
		/** @var Fundraiser $item */
		$is_test  = (bool) $this->settings->get( 'test_mode' );
		$donor    = $item->donor();
		$campaign = $item->campaign();
		$team     = $item->team();

		return [
			'id'                 => (int) $item->id,
			'campaign_id'        => $item->campaign_id,
			'campaign_title'     => $campaign?->title ?? '',
			'campaign_end_date'  => $campaign?->date_end,
			'campaign_days_left' => $campaign?->days_left(),
			'donor_id'           => $item->donor_id,
			'donor_name'         => $donor?->full_name() ?: __( 'Anonymous', 'mission-donation-platform' ),
			'donor_email'        => $donor?->email ?? '',
			'team_id'            => $item->team_id,
			'team_name'          => $team?->name ?? '',
			'goal'               => $item->goal,
			'headline'           => $item->headline,
			'story'              => $item->story,
			'status'             => $item->status,
			'is_team_captain'    => $item->is_captain(),
			'raised'             => $item->amount_raised( $is_test ),
			'donor_count'        => $is_test ? $item->test_donor_count : $item->donor_count,
			'transaction_count'  => $is_test ? $item->test_transaction_count : $item->transaction_count,
			'progress'           => $item->progress( $is_test ),
			'dedication'         => $item->dedication(),
			'page_url'           => $item->get_url() ?? '',
			'cover_image'        => $item->cover_image,
			'profile_image'      => $item->profile_image,
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
	protected function get_update_params(): array {
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
			'dedication_type' => Args::enum( [ 'honor', 'memory', '' ] ),
			'dedication_name' => Args::string(),
		];
	}
}
