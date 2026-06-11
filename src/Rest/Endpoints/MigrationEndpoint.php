<?php
/**
 * REST endpoints for the Migration tool.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Migration\MigrationService;
use MissionDP\Rest\RestModule;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Migration REST routes.
 */
class MigrationEndpoint {

	/**
	 * Constructor.
	 *
	 * @param MigrationService $service Migration service.
	 */
	public function __construct(
		private readonly MigrationService $service,
	) {}

	/**
	 * Register REST routes.
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/migration/sources',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_sources' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/migration/scan',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'scan' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'source' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/migration/start',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'start' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'source'       => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
					'include_test' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/migration/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'job_id' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/migration/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'job_id' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/migration/rollback',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rollback' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'job_id' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Permission check: admins only.
	 */
	public function check_permission(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to manage migrations.', 'mission-donation-platform' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * GET /migration/sources — available and coming-soon sources.
	 */
	public function get_sources(): WP_REST_Response {
		return new WP_REST_Response( $this->service->get_registry()->get_sources_payload() );
	}

	/**
	 * POST /migration/scan — run the pre-flight scan for a source.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function scan( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->scan( (string) $request->get_param( 'source' ) );

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/**
	 * POST /migration/start — start a migration run.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->start(
			(string) $request->get_param( 'source' ),
			[ 'include_test' => (bool) $request->get_param( 'include_test' ) ],
			get_current_user_id()
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 202 );
	}

	/**
	 * GET /migration/status — status payload for a job (default: active or latest).
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job_id = (string) $request->get_param( 'job_id' );
		$result = $this->service->get_status( $job_id ?: null );

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/**
	 * POST /migration/cancel — cancel a running job.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->cancel( (string) $request->get_param( 'job_id' ) );

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/**
	 * POST /migration/rollback — delete everything a finished run created.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function rollback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->start_rollback(
			(string) $request->get_param( 'job_id' ),
			get_current_user_id()
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 202 );
	}
}
