<?php
/**
 * REST endpoints for the review banner.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Rest\Args;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\AdminPermissionTrait;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Review banner endpoint class.
 *
 * Handles dismissal and rating submission for the dashboard review banner.
 */
class ReviewBannerEndpoint {

	use AdminPermissionTrait;

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/review-banner/dismiss',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'dismiss' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/review-banner/rate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rate' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'rating' => Args::integer(
						[
							'required' => true,
							'minimum'  => 1,
							'maximum'  => 5,
						]
					),
				],
			]
		);
	}

	/**
	 * Dismiss the review banner for the current user.
	 *
	 * @return WP_REST_Response
	 */
	public function dismiss(): WP_REST_Response {
		update_user_meta( get_current_user_id(), 'missiondp_review_banner_dismissed', 1 );

		return new WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * Submit a star rating and dismiss the banner.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function rate( WP_REST_Request $request ): WP_REST_Response {
		$rating = $request->get_param( 'rating' );

		update_user_meta( get_current_user_id(), 'missiondp_review_banner_dismissed', 1 );

		// Send rating to Mission API (non-blocking).
		wp_remote_post(
			'https://api.missionwp.com/v1/review-rating',
			[
				'blocking' => false,
				'body'     => [
					'rating'  => $rating,
					'domain'  => wp_parse_url( home_url(), PHP_URL_HOST ),
					'version' => MISSIONDP_VERSION,
				],
			]
		);

		return new WP_REST_Response( [ 'success' => true ] );
	}
}
