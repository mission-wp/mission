<?php
/**
 * REST endpoint for cleanup operations.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Cleanup\CleanupService;
use MissionDP\Rest\RestModule;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Cleanup endpoint class.
 */
class CleanupEndpoint {

	/**
	 * Allowed cleanup actions.
	 *
	 * @var string[]
	 */
	private const ACTIONS = [
		'clear_dashboard_cache',
		'clear_email_template_cache',
		'clear_stripe_sync_cache',
		'clear_activity_log',
		'delete_log_files',
		'delete_test_transactions',
		'delete_test_donors',
		'delete_test_subscriptions',
		'delete_all_test_data',
		'reset_onboarding',
		'reset_all_settings',
		'delete_all_data',
	];

	/**
	 * Constructor.
	 *
	 * @param CleanupService $cleanup Cleanup service.
	 */
	public function __construct(
		private readonly CleanupService $cleanup,
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/cleanup/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/cleanup/(?P<action>[a-z_]+)',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'run_action' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'action'       => [
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => [ $this, 'validate_action' ],
					],
					'confirmation' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Check if the current user has permission.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform cleanup operations.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Validate an action parameter.
	 *
	 * @param string $value Action slug.
	 *
	 * @return bool
	 */
	public function validate_action( string $value ): bool {
		return in_array( $value, self::ACTIONS, true );
	}

	/**
	 * Get cleanup stats.
	 *
	 * @return WP_REST_Response
	 */
	public function get_stats(): WP_REST_Response {
		return new WP_REST_Response( $this->cleanup->get_stats() );
	}

	/**
	 * Run a cleanup action.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$action = $request->get_param( 'action' );

		// Nuclear option requires typed confirmation.
		if ( 'delete_all_data' === $action ) {
			$confirmation = $request->get_param( 'confirmation' );
			if ( 'DELETE' !== $confirmation ) {
				return new WP_Error(
					'confirmation_required',
					__( 'You must type DELETE to confirm this action.', 'mission-donation-platform' ),
					[ 'status' => 400 ]
				);
			}
		}

		$method = match ( $action ) {
			'clear_dashboard_cache'      => 'clear_dashboard_cache',
			'clear_email_template_cache' => 'clear_email_template_cache',
			'clear_stripe_sync_cache'    => 'clear_stripe_sync_cache',
			'clear_activity_log'         => 'clear_activity_log',
			'delete_log_files'           => 'delete_log_files',
			'delete_test_transactions'   => 'delete_test_transactions',
			'delete_test_donors'         => 'delete_test_donors',
			'delete_test_subscriptions'  => 'delete_test_subscriptions',
			'delete_all_test_data'       => 'delete_all_test_data',
			'reset_onboarding'           => 'reset_onboarding',
			'reset_all_settings'         => 'reset_all_settings',
			'delete_all_data'            => 'delete_all_data',
			default                      => null,
		};

		if ( ! $method ) {
			return new WP_Error(
				'invalid_action',
				__( 'Invalid cleanup action.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$result = $this->cleanup->$method();

		return new WP_REST_Response( $result );
	}
}
