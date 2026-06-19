<?php
/**
 * Donor dashboard fundraiser endpoint.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints\DonorDashboard;

use MissionDP\Currency\Currency;
use MissionDP\Models\Fundraiser;
use MissionDP\P2P\FundraiserImageUploader;
use MissionDP\Reporting\ReportingService;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\ResolveDonorTrait;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Owner-scoped read/edit routes for a donor's own fundraiser pages.
 *
 * Every route resolves the fundraiser from its ID and confirms the current
 * donor owns it before doing anything; an ID from the client is never trusted
 * on its own. Editing never changes a fundraiser's status (a pending page stays
 * pending) — the cover photo is set through the dedicated upload route.
 */
class FundraiserEndpoint {

	use ResolveDonorTrait;

	/**
	 * Constructor.
	 *
	 * @param ReportingService        $reporting Reporting service for the donor list.
	 * @param SettingsService         $settings  Settings service (test-mode aware totals).
	 * @param FundraiserImageUploader $uploader  Server-side cover photo uploader.
	 */
	public function __construct(
		private ReportingService $reporting,
		private SettingsService $settings,
		private FundraiserImageUploader $uploader,
	) {}

	/**
	 * Register REST routes.
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/fundraisers',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_fundraisers' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/fundraisers/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_fundraiser' ],
					'permission_callback' => [ $this, 'check_donor_permission' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_fundraiser' ],
					'permission_callback' => [ $this, 'check_donor_permission' ],
					'args'                => $this->get_update_params(),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/fundraisers/(?P<id>\d+)/photo',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'upload_photo' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/fundraisers/(?P<id>\d+)/donors',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_donors' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);
	}

	/**
	 * GET /donor-dashboard/fundraisers
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_fundraisers(): WP_REST_Response|WP_Error {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$items = array_map(
			[ $this, 'prepare_fundraiser' ],
			$donor->fundraisers(
				[
					'orderby' => 'date_created',
					'order'   => 'DESC',
				]
			)
		);

		return new WP_REST_Response( $items );
	}

	/**
	 * GET /donor-dashboard/fundraisers/{id}
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_fundraiser( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$fundraiser = $this->resolve_owned_fundraiser( $request );

		if ( is_wp_error( $fundraiser ) ) {
			return $fundraiser;
		}

		return new WP_REST_Response( $this->prepare_fundraiser( $fundraiser ) );
	}

	/**
	 * PUT /donor-dashboard/fundraisers/{id}
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_fundraiser( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$fundraiser = $this->resolve_owned_fundraiser( $request );

		if ( is_wp_error( $fundraiser ) ) {
			return $fundraiser;
		}

		if ( null !== $request->get_param( 'goal' ) ) {
			// The goal arrives in major units (what the fundraiser entered); convert here.
			$currency         = $fundraiser->campaign()?->currency ?: 'USD';
			$fundraiser->goal = Currency::major_to_minor( max( 0, (float) $request->get_param( 'goal' ) ), $currency );
		}

		if ( null !== $request->get_param( 'headline' ) ) {
			$fundraiser->headline = sanitize_text_field( $request->get_param( 'headline' ) );
		}

		if ( null !== $request->get_param( 'story' ) ) {
			$fundraiser->story = wp_kses_post( $request->get_param( 'story' ) );
		}

		$fundraiser->save();

		/**
		 * Fires after a fundraiser edits their own page from the dashboard.
		 *
		 * @param Fundraiser $fundraiser The updated fundraiser.
		 */
		do_action( 'mission_fundraiser_page_edited', $fundraiser );

		return new WP_REST_Response( $this->prepare_fundraiser( $fundraiser ) );
	}

	/**
	 * POST /donor-dashboard/fundraisers/{id}/photo
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_photo( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$fundraiser = $this->resolve_owned_fundraiser( $request );

		if ( is_wp_error( $fundraiser ) ) {
			return $fundraiser;
		}

		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'no_file', __( 'No image was uploaded.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$attachment_id = $this->uploader->handle( $files['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$fundraiser->cover_image = (string) $attachment_id;
		$fundraiser->save();

		return new WP_REST_Response(
			[
				'cover_image'     => (int) $attachment_id,
				'cover_image_url' => wp_get_attachment_image_url( $attachment_id, 'large' ) ?: '',
			]
		);
	}

	/**
	 * GET /donor-dashboard/fundraisers/{id}/donors
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_donors( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$fundraiser = $this->resolve_owned_fundraiser( $request );

		if ( is_wp_error( $fundraiser ) ) {
			return $fundraiser;
		}

		$per_page = min( 50, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 10 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );

		$result   = $this->reporting->fundraiser_donations_query( (int) $fundraiser->id, $per_page, $page );
		$currency = strtoupper( (string) ( $this->settings->get( 'currency' ) ?: 'USD' ) );

		$items = array_map(
			static function ( array $row ) use ( $currency ): array {
				$name = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );

				return [
					'name'    => $row['is_anonymous'] || '' === $name
						? __( 'Anonymous', 'mission-donation-platform' )
						: $name,
					'amount'  => Currency::format_amount( $row['amount'], $currency ),
					'date'    => $row['date'] ? date_i18n( 'M j, Y', strtotime( $row['date'] ) ) : '',
					'comment' => $row['comment'],
				];
			},
			$result['items']
		);

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $result['total'] / $per_page ) : 0 ) );

		return $response;
	}

	/**
	 * Resolve a fundraiser from the request and confirm the current donor owns it.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return Fundraiser|WP_Error
	 */
	private function resolve_owned_fundraiser( WP_REST_Request $request ): Fundraiser|WP_Error {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$fundraiser = Fundraiser::find( (int) $request->get_param( 'id' ) );

		if ( ! $fundraiser || (int) $fundraiser->donor_id !== (int) $donor->id ) {
			return new WP_Error(
				'fundraiser_not_found',
				__( 'Fundraiser not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		return $fundraiser;
	}

	/**
	 * Prepare a fundraiser for the dashboard.
	 *
	 * @param Fundraiser $fundraiser Fundraiser model.
	 * @return array<string, mixed>
	 */
	private function prepare_fundraiser( Fundraiser $fundraiser ): array {
		$is_test  = (bool) $this->settings->get( 'test_mode' );
		$currency = strtoupper( (string) ( $this->settings->get( 'currency' ) ?: 'USD' ) );
		$campaign = $fundraiser->campaign();
		$team     = $fundraiser->team();
		$cover    = $fundraiser->cover_image;
		$raised   = $fundraiser->amount_raised( $is_test );

		$goal_display   = $fundraiser->goal > 0 ? Currency::format_amount( $fundraiser->goal, $currency ) : '';
		$raised_display = Currency::format_amount( $raised, $currency );

		return [
			'id'              => (int) $fundraiser->id,
			'campaign_id'     => $fundraiser->campaign_id,
			'campaign_title'  => $campaign?->title ?? '',
			'goal'            => $fundraiser->goal,
			'goal_major'      => (string) Currency::minor_to_major( $fundraiser->goal, $currency ),
			'goal_display'    => $goal_display,
			'headline'        => $fundraiser->headline,
			'story'           => $fundraiser->story,
			'status'          => $fundraiser->status,
			'raised'          => $raised,
			'raised_display'  => $raised_display,
			'donor_count'     => $is_test ? $fundraiser->test_donor_count : $fundraiser->donor_count,
			'progress'        => $fundraiser->progress( $is_test ),
			'progress_label'  => $goal_display
				/* translators: 1: amount raised, 2: goal amount */
				? sprintf( __( '%1$s raised of %2$s goal', 'mission-donation-platform' ), $raised_display, $goal_display )
				/* translators: %s: amount raised */
				: sprintf( __( '%s raised', 'mission-donation-platform' ), $raised_display ),
			'cover_image'     => ctype_digit( $cover ) ? (int) $cover : 0,
			'cover_image_url' => '' !== $cover && ctype_digit( $cover )
				? ( wp_get_attachment_image_url( (int) $cover, 'large' ) ?: '' )
				: $cover,
			'url'             => $fundraiser->get_url() ?? '',
			'team_id'         => $fundraiser->team_id,
			'team_name'       => $team?->name ?? '',
			'team_url'        => $team?->get_url() ?? '',
		];
	}

	/**
	 * Parameters for the fundraiser update endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_update_params(): array {
		return [
			'goal'     => [
				'type' => 'number',
			],
			'headline' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'story'    => [
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			],
		];
	}
}
