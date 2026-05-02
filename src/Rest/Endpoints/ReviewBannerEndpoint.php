<?php
/**
 * REST endpoints for the review banner.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Rest\RestModule;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Review banner endpoint class.
 *
 * Handles dismissal and rating submission for the dashboard review banner.
 */
class ReviewBannerEndpoint {

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
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/review-banner/rate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rate' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'rating' => [
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'maximum'           => 5,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Permission check — requires manage_options.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return true;
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
