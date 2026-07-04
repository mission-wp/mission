<?php
/**
 * Donor dashboard team (captain) endpoint.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints\DonorDashboard;

use MissionDP\Currency\Currency;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\TeamInvitation;
use MissionDP\P2P\FundraiserImageUploader;
use MissionDP\Settings\SettingsService;
use MissionDP\Rest\Args;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\DonorDashboardPrepareTrait;
use MissionDP\Rest\Traits\RateLimitTrait;
use MissionDP\Rest\Traits\ResolveDonorTrait;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Captain-scoped read/manage routes for a donor's own team.
 *
 * Every route resolves the team from its ID and confirms the current donor is
 * the team's captain before doing anything; a member who is not the captain is
 * rejected with a 403. Editing never changes a team's status (a pending team
 * stays pending) — the cover image is set through the dedicated upload route.
 */
class TeamEndpoint {

	use DonorDashboardPrepareTrait;
	use RateLimitTrait;
	use ResolveDonorTrait;

	/**
	 * Constructor.
	 *
	 * @param SettingsService         $settings Settings service (test-mode aware totals).
	 * @param FundraiserImageUploader $uploader Server-side cover image uploader.
	 */
	public function __construct(
		private SettingsService $settings,
		private FundraiserImageUploader $uploader,
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/teams/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_team' ],
					'permission_callback' => [ $this, 'check_donor_permission' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_team' ],
					'permission_callback' => [ $this, 'check_donor_permission' ],
					'args'                => $this->get_update_params(),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/teams/(?P<id>\d+)/photo',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'upload_photo' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/teams/(?P<id>\d+)/invite',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'invite_member' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
				'args'                => [
					'email' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/teams/(?P<id>\d+)/members/(?P<fundraiser_id>\d+)/remove',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'remove_member' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/teams/(?P<id>\d+)/members/(?P<fundraiser_id>\d+)/promote',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'promote_member' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);
	}

	/**
	 * GET /donor-dashboard/teams/{id}
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_team( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$team = $this->resolve_captained_team( $request );

		if ( is_wp_error( $team ) ) {
			return $team;
		}

		return new WP_REST_Response( $this->prepare_team( $team ) );
	}

	/**
	 * PUT /donor-dashboard/teams/{id}
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_team( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$team = $this->resolve_captained_team( $request );

		if ( is_wp_error( $team ) ) {
			return $team;
		}

		$locked = $this->check_not_locked( $team );
		if ( $locked ) {
			return $locked;
		}

		if ( null !== $request->get_param( 'name' ) ) {
			$name = sanitize_text_field( $request->get_param( 'name' ) );
			if ( '' !== $name ) {
				$team->name = $name;
			}
		}

		if ( null !== $request->get_param( 'description' ) ) {
			$team->description = wp_kses_post( $request->get_param( 'description' ) );
		}

		if ( null !== $request->get_param( 'goal' ) ) {
			// The goal arrives in major units (what the captain entered); convert here.
			$currency   = $team->campaign()?->currency ?: 'USD';
			$team->goal = Currency::major_to_minor( max( 0, (float) $request->get_param( 'goal' ) ), $currency );
		}

		$access = $request->get_param( 'access' );
		if ( null !== $access && in_array( $access, Team::ACCESS_LEVELS, true ) ) {
			$team->access = $access;
		}

		$team->save();

		/**
		 * Fires after a captain edits their own team from the dashboard.
		 *
		 * @param Team $team The updated team.
		 */
		do_action( 'mission_team_page_edited', $team );

		return new WP_REST_Response( $this->prepare_team( $team ) );
	}

	/**
	 * POST /donor-dashboard/teams/{id}/photo
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_photo( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'p2p_photo_upload', 30, HOUR_IN_SECONDS );
		if ( $rate_error ) {
			return $rate_error;
		}

		$team = $this->resolve_captained_team( $request );

		if ( is_wp_error( $team ) ) {
			return $team;
		}

		$locked = $this->check_not_locked( $team );
		if ( $locked ) {
			return $locked;
		}

		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'no_file', __( 'No image was uploaded.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$attachment_id = $this->uploader->handle( $files['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$previous = (string) $team->cover_image;

		$team->cover_image = (string) $attachment_id;
		$team->save();

		$this->uploader->cleanup_replaced_image( $previous );

		return new WP_REST_Response(
			[
				'cover_image'     => (int) $attachment_id,
				'cover_image_url' => wp_get_attachment_image_url( $attachment_id, 'large' ) ?: '',
			]
		);
	}

	/**
	 * POST /donor-dashboard/teams/{id}/invite
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function invite_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'team_invite', 20, 3600 );
		if ( $rate_error ) {
			return $rate_error;
		}

		$team = $this->resolve_captained_team( $request );

		if ( is_wp_error( $team ) ) {
			return $team;
		}

		$locked = $this->check_not_locked( $team );
		if ( $locked ) {
			return $locked;
		}

		$result = $team->invite( (string) $request->get_param( 'email' ) );

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 400 ] );
			return $result;
		}

		return new WP_REST_Response( [ 'invitations' => $this->prepare_invitations( $team ) ], 201 );
	}

	/**
	 * POST /donor-dashboard/teams/{id}/members/{fundraiser_id}/remove
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$team = $this->resolve_captained_team( $request );

		if ( is_wp_error( $team ) ) {
			return $team;
		}

		$locked = $this->check_not_locked( $team );
		if ( $locked ) {
			return $locked;
		}

		$member = Fundraiser::find( (int) $request->get_param( 'fundraiser_id' ) );

		if ( ! $member ) {
			return new WP_Error( 'member_not_found', __( 'Member not found.', 'mission-donation-platform' ), [ 'status' => 404 ] );
		}

		$result = $team->remove_member( $member );

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 400 ] );
			return $result;
		}

		return new WP_REST_Response( $this->prepare_team( $team ) );
	}

	/**
	 * POST /donor-dashboard/teams/{id}/members/{fundraiser_id}/promote
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function promote_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$team = $this->resolve_captained_team( $request );

		if ( is_wp_error( $team ) ) {
			return $team;
		}

		$locked = $this->check_not_locked( $team );
		if ( $locked ) {
			return $locked;
		}

		$member = Fundraiser::find( (int) $request->get_param( 'fundraiser_id' ) );

		if ( ! $member ) {
			return new WP_Error( 'member_not_found', __( 'Member not found.', 'mission-donation-platform' ), [ 'status' => 404 ] );
		}

		$result = $team->promote_captain( $member );

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 400 ] );
			return $result;
		}

		return new WP_REST_Response( $this->prepare_team( $team ) );
	}

	/**
	 * Resolve a team from the request and confirm the current donor is its captain.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return Team|WP_Error
	 */
	private function resolve_captained_team( WP_REST_Request $request ): Team|WP_Error {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$team = Team::find( (int) $request->get_param( 'id' ) );

		if ( ! $team || (int) ( $team->captain()?->donor_id ?? 0 ) !== (int) $donor->id ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not the captain of this team.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return $team;
	}

	/**
	 * Reject writes to a team whose campaign has ended.
	 *
	 * @param Team $team Team model.
	 * @return WP_Error|null An error when locked, null when editable.
	 */
	private function check_not_locked( Team $team ): ?WP_Error {
		if ( ! $team->is_locked() ) {
			return null;
		}

		return new WP_Error(
			'team_locked',
			__( 'This campaign has ended, so the team can no longer be edited.', 'mission-donation-platform' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Prepare a team for the captain dashboard.
	 *
	 * @param Team $team Team model.
	 * @return array<string, mixed>
	 */
	private function prepare_team( Team $team ): array {
		$is_test  = (bool) $this->settings->get( 'test_mode' );
		$currency = $this->dashboard_currency();
		$cover    = $team->cover_image;
		$raised   = $team->amount_raised( $is_test );

		$goal_display   = $team->goal > 0 ? Currency::format_amount( $team->goal, $currency ) : '';
		$raised_display = Currency::format_amount( $raised, $currency );

		return [
			'id'              => (int) $team->id,
			'campaign_id'     => $team->campaign_id,
			'name'            => $team->name,
			'description'     => $team->description,
			'goal'            => $team->goal,
			'goal_major'      => (string) Currency::minor_to_major( $team->goal, $currency ),
			'goal_display'    => $goal_display,
			'status'          => $team->status,
			'access'          => $team->access,
			'raised'          => $raised,
			'raised_display'  => $raised_display,
			'progress'        => $team->progress( $is_test ),
			'progress_label'  => $this->progress_label( $raised_display, $goal_display ),
			'cover_image'     => $this->cover_image_id( $cover ),
			'cover_image_url' => $this->cover_image_url( $cover ),
			'url'             => $team->get_url() ?? '',
			'members'         => $this->prepare_members( $team, $is_test, $currency ),
			'invitations'     => $this->prepare_invitations( $team ),
		];
	}

	/**
	 * Prepare a team's member list.
	 *
	 * @param Team   $team     Team model.
	 * @param bool   $is_test  Whether to use test-mode totals.
	 * @param string $currency Currency code for formatting.
	 * @return array<int, array<string, mixed>>
	 */
	private function prepare_members( Team $team, bool $is_test, string $currency ): array {
		return array_map(
			function ( Fundraiser $member ) use ( $is_test, $currency ): array {
				$donor  = $member->donor();
				$name   = trim( (string) ( $donor?->full_name() ?? '' ) );
				$raised = $member->amount_raised( $is_test );

				$raised_display = Currency::format_amount( $raised, $currency );
				$goal_display   = $member->goal > 0 ? Currency::format_amount( $member->goal, $currency ) : '';

				return [
					'fundraiser_id'        => (int) $member->id,
					'donor_id'             => (int) $member->donor_id,
					'name'                 => '' !== $name ? $name : __( 'Participant', 'mission-donation-platform' ),
					'initials'             => $this->person_initials( (string) ( $donor?->first_name ?? '' ), (string) ( $donor?->last_name ?? '' ) ),
					'is_captain'           => (bool) $member->is_team_captain,
					'raised'               => $raised_display,
					'raised_minor'         => $raised,
					'goal'                 => $member->goal,
					'progress'             => $member->progress( $is_test ),
					'raised_of_goal_label' => $goal_display
						/* translators: 1: amount raised, 2: personal goal */
						? sprintf( __( '%1$s of %2$s', 'mission-donation-platform' ), $raised_display, $goal_display )
						: $raised_display,
				];
			},
			$team->members(
				[
					'orderby'  => 'total_raised',
					'order'    => 'DESC',
					'per_page' => -1,
				]
			)
		);
	}

	/**
	 * Prepare a team's pending invitations.
	 *
	 * @param Team $team Team model.
	 * @return array<int, array<string, mixed>>
	 */
	private function prepare_invitations( Team $team ): array {
		return array_map(
			static fn( TeamInvitation $invitation ): array => [
				'id'    => (int) $invitation->id,
				'email' => $invitation->email,
				'sent'  => ! empty( $invitation->sent_at ),
			],
			$team->invitations(
				[
					'status'   => TeamInvitation::STATUS_PENDING,
					'per_page' => -1,
				]
			)
		);
	}

	/**
	 * Parameters for the team update endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_update_params(): array {
		return [
			'name'        => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'description' => [
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			],
			'goal'        => [
				'type' => 'number',
			],
			'access'      => Args::enum( Team::ACCESS_LEVELS ),
		];
	}
}
