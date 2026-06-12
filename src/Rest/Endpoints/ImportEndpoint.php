<?php
/**
 * REST endpoints for the Import tool.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Import\ImportService;
use MissionDP\Models\ImportJob;
use MissionDP\Rest\Args;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\AdminPermissionTrait;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Import REST routes.
 */
class ImportEndpoint {

	use AdminPermissionTrait;

	/**
	 * Constructor.
	 *
	 * @param ImportService $import Import service.
	 */
	public function __construct(
		private ImportService $import,
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
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'type' => Args::string( [ 'required' => true ] ),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/import/template',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'download_template' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'type' => Args::string( [ 'required' => true ] ),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/import/validate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'validate_upload' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'type' => Args::string( [ 'required' => true ] ),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/import/start',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'start_import' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'file_id'            => Args::string( [ 'required' => true ] ),
					'duplicate_strategy' => Args::enum( [ 'skip', 'update' ], [ 'required' => true ] ),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/import/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'job_id' => Args::string( [ 'required' => true ] ),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/import/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'job_id' => Args::string( [ 'required' => true ] ),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/import/active',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_active' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'type' => Args::string( [ 'required' => true ] ),
				],
			]
		);
	}

	/**
	 * Message returned when the capability check fails.
	 *
	 * @return string
	 */
	protected function permission_denied_message(): string {
		return __( 'You do not have permission to import data.', 'mission-donation-platform' );
	}

	/**
	 * Return the expected columns for a type.
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
				'type'     => $type,
				'columns'  => $this->import->get_expected_columns( $type ),
				'required' => $this->import->get_required_columns( $type ),
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
	 * Start a background import job.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function start_import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->import->start_import(
			(string) $request->get_param( 'file_id' ),
			(string) $request->get_param( 'duplicate_strategy' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 202 );
	}

	/**
	 * Return status of a single job (scoped to the current user).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->import->get_job_status(
			(string) $request->get_param( 'job_id' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Safety net for environments where AS auto-dispatch never fires, and
		// for resuming after the runner hits its own time limit mid-job.
		if ( ! in_array( $result['status'], ImportJob::TERMINAL_STATUSES, true ) ) {
			$this->import->kick_queue_runner();
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Cancel an in-flight job.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->import->cancel_job(
			(string) $request->get_param( 'job_id' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Return the user's currently active job for the given type (if any).
	 *
	 * Used by the import panel on mount to resume after a navigate-away.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_active( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = $request->get_param( 'type' );

		if ( ! $this->import->is_valid_type( $type ) ) {
			return new WP_Error( 'invalid_type', __( 'Unsupported import type.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$active = $this->import->get_active_job( get_current_user_id(), $type );

		return new WP_REST_Response( [ 'job' => $active ] );
	}
}
