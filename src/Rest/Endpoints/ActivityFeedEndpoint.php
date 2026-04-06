<?php
/**
 * REST endpoint for activity feed.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Models\ActivityLog;
use Mission\Rest\RestModule;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Activity feed endpoint class.
 */
class ActivityFeedEndpoint {

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service.
	 */
	public function __construct(
		private readonly SettingsService $settings,
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/activity',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_items' ],
					'permission_callback' => [ $this, 'check_permission' ],
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
				__( 'You do not have permission to view the activity feed.', 'mission' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * GET handler — returns paginated activity log entries.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$per_page = $request->get_param( 'per_page' ) ?? 25;
		$page     = $request->get_param( 'page' ) ?? 1;

		$query_args = [
			'per_page' => $per_page,
			'page'     => $page,
			'orderby'  => 'date_created',
			'order'    => 'DESC',
		];

		$optional_filters = [ 'object_type', 'object_id', 'event', 'date_after', 'level', 'category', 'search' ];

		foreach ( $optional_filters as $filter ) {
			$value = $request->get_param( $filter );

			if ( $value ) {
				$query_args[ $filter ] = $value;
			}
		}

		$query_args['is_test'] = (int) (bool) $this->settings->get( 'test_mode' );

		$entries = ActivityLog::query( $query_args );
		$total   = ActivityLog::count( $query_args );

		$items       = array_map( [ $this, 'prepare_entry' ], $entries );
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * Prepare an activity log entry for REST response.
	 *
	 * @param ActivityLog $entry Activity log entry.
	 * @return array<string, mixed>
	 */
	/**
	 * DELETE handler — clears all activity log entries for the current mode.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_items(): WP_REST_Response {
		/** @var \Mission\Database\DataStore\ActivityLogDataStore $store */
		$store   = ActivityLog::store();
		$deleted = $store->delete_all(
			[
				'is_test' => (int) (bool) $this->settings->get( 'test_mode' ),
			]
		);

		return new WP_REST_Response( [ 'deleted' => $deleted ], 200 );
	}

	private function prepare_entry( ActivityLog $entry ): array {
		return [
			'id'           => $entry->id,
			'object_type'  => $entry->object_type,
			'object_id'    => $entry->object_id,
			'event'        => $entry->event,
			'actor_id'     => $entry->actor_id,
			'data'         => $entry->data ? json_decode( $entry->data, true ) : null,
			'level'        => $entry->level,
			'category'     => $entry->category,
			'date_created' => $entry->date_created,
		];
	}

	/**
	 * Get collection query parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_collection_params(): array {
		return [
			'page'        => [
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page'    => [
				'type'              => 'integer',
				'default'           => 25,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			],
			'object_type' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'object_id'   => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'event'       => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_after'  => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'level'       => [
				'type'              => 'string',
				'enum'              => [ 'info', 'warning', 'error' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'category'    => [
				'type'              => 'string',
				'enum'              => [ 'payment', 'webhook', 'email', 'subscription', 'system' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'search'      => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}
}
