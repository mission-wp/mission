<?php
/**
 * REST endpoint for the Import tool (Phase 1: validate + template).
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Import\ImportService;
use MissionDP\Rest\RestModule;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Import REST routes.
 */
class ImportEndpoint {

	/**
	 * Constructor.
	 *
	 * @param ImportService $import Import service.
	 */
	public function __construct(
		private readonly ImportService $import,
	) {}

	/**
	 * Register REST routes.
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/import/columns',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_columns' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'type' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/import/template',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'download_template' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'type' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/import/validate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'validate_upload' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'type' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/import/execute',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'execute_import' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'file_id'            => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'duplicate_strategy' => [
						'type'              => 'string',
						'required'          => true,
						'enum'              => [ 'skip', 'update', 'create' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Permission check. Must be a site admin.
	 */
	public function check_permission(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to import data.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Return the expected columns for a type (used by the UI callout).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_columns( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = $request->get_param( 'type' );

		if ( ! $this->import->is_valid_type( $type ) ) {
			return new WP_Error( 'invalid_type', __( 'Unsupported import type.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		return new WP_REST_Response(
			[
				'type'    => $type,
				'columns' => $this->import->get_expected_columns( $type ),
			]
		);
	}

	/**
	 * Stream a blank CSV template for the type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void
	 */
	public function download_template( WP_REST_Request $request ) {
		$type = $request->get_param( 'type' );

		if ( ! $this->import->is_valid_type( $type ) ) {
			return new WP_Error( 'invalid_type', __( 'Unsupported import type.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$content  = $this->import->build_template( $type );
		$filename = sanitize_file_name(
			sprintf( 'mission-%s-template.csv', $type )
		);

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $content ) );
		header( 'Cache-Control: private, max-age=0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV content.
		echo $content;
		exit;
	}

	/**
	 * Validate an uploaded file and return the preview payload.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function validate_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type  = $request->get_param( 'type' );
		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$result = $this->import->validate_file( $files['file'], $type );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Run the import against a previously validated file.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function execute_import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->import->execute_import(
			(string) $request->get_param( 'file_id' ),
			(string) $request->get_param( 'duplicate_strategy' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result );
	}
}
