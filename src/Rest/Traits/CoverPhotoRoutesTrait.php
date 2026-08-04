<?php
/**
 * Shared cover-photo route handlers for donor-dashboard endpoints.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Traits;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the POST/DELETE {resource}/{id}/photo handlers for endpoints whose
 * model carries a cover_image attachment.
 *
 * The using class supplies resolve_photo_model() (the ownership check),
 * check_not_locked(), check_rate_limit(), and an uploader property exposing
 * handle() and cleanup_replaced_image().
 */
trait CoverPhotoRoutesTrait {

	/**
	 * Resolve the model owning the cover photo, confirming the current donor
	 * may edit it.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return object Model exposing cover_image and save(), or a WP_Error.
	 */
	abstract protected function resolve_photo_model( WP_REST_Request $request ): object;

	/**
	 * POST {resource}/{id}/photo — upload and set a new cover image.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_photo( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'p2p_photo_upload', 30, HOUR_IN_SECONDS );
		if ( $rate_error ) {
			return $rate_error;
		}

		$model = $this->resolve_photo_model( $request );

		if ( is_wp_error( $model ) ) {
			return $model;
		}

		$locked = $this->check_not_locked( $model );
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

		$previous = (string) $model->cover_image;

		$model->cover_image = (string) $attachment_id;
		$model->save();

		$this->uploader->cleanup_replaced_image( $previous );

		return new WP_REST_Response(
			[
				'cover_image'     => (int) $attachment_id,
				'cover_image_url' => wp_get_attachment_image_url( $attachment_id, 'large' ) ?: '',
			]
		);
	}

	/**
	 * DELETE {resource}/{id}/photo — remove the cover image.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_photo( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$model = $this->resolve_photo_model( $request );

		if ( is_wp_error( $model ) ) {
			return $model;
		}

		$locked = $this->check_not_locked( $model );
		if ( $locked ) {
			return $locked;
		}

		$previous = (string) $model->cover_image;

		$model->cover_image = '';
		$model->save();

		$this->uploader->cleanup_replaced_image( $previous );

		return new WP_REST_Response(
			[
				'cover_image'     => 0,
				'cover_image_url' => '',
			]
		);
	}
}
