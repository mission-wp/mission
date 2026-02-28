<?php
/**
 * REST endpoint for campaigns.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Database\DataStore\CampaignDataStore;
use Mission\Rest\RestModule;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Campaigns endpoint class.
 */
class CampaignsEndpoint {

	/**
	 * Constructor.
	 *
	 * @param CampaignDataStore $campaign_store Campaign data store.
	 */
	public function __construct(
		private readonly CampaignDataStore $campaign_store,
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/campaigns',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_campaigns' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_collection_params(),
			)
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/campaigns/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_campaign' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
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
				__( 'You do not have permission to view campaigns.', 'mission' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * GET handler — returns paginated campaigns with financial data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_campaigns( WP_REST_Request $request ): WP_REST_Response {
		$query_args = array(
			'post_type'      => 'mission_campaign',
			'posts_per_page' => $request->get_param( 'per_page' ) ?? 25,
			'paged'          => $request->get_param( 'page' ) ?? 1,
			'orderby'        => $this->map_orderby( $request->get_param( 'orderby' ) ?? 'date' ),
			'order'          => strtoupper( $request->get_param( 'order' ) ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
		);

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$query_args['s'] = $search;
		}

		$status = $request->get_param( 'status' );
		if ( ! empty( $status ) ) {
			$query_args['post_status'] = $status;
		} else {
			$query_args['post_status'] = array( 'publish', 'draft' );
		}

		$query = new WP_Query( $query_args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$campaign = $this->campaign_store->find_by_post_id( $post->ID );

			$items[] = array(
				'id'                => $post->ID,
				'title'             => $post->post_title,
				'status'            => $post->post_status,
				'goal_amount'       => $campaign?->goal_amount ?? 0,
				'total_raised'      => $campaign?->total_raised ?? 0,
				'transaction_count' => $campaign?->transaction_count ?? 0,
				'edit_url'          => get_edit_post_link( $post->ID, 'raw' ),
				'date_created'      => $post->post_date_gmt,
			);
		}

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );

		return $response;
	}

	/**
	 * DELETE handler — trashes a campaign post and deletes its custom table row.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'mission_campaign' !== $post->post_type ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Campaign not found.', 'mission' ),
				array( 'status' => 404 )
			);
		}

		$result = wp_trash_post( $post_id );

		if ( ! $result ) {
			return new WP_Error(
				'rest_cannot_delete',
				__( 'The campaign could not be deleted.', 'mission' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $post_id,
			),
			200
		);
	}

	/**
	 * Map REST orderby param to WP_Query orderby value.
	 *
	 * @param string $orderby The REST orderby param.
	 * @return string
	 */
	private function map_orderby( string $orderby ): string {
		return match ( $orderby ) {
			'title' => 'title',
			'date'  => 'date',
			default => 'date',
		};
	}

	/**
	 * Get collection query parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_collection_params(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 25,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'orderby'  => array(
				'type'              => 'string',
				'default'           => 'date',
				'enum'              => array( 'date', 'title' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'order'    => array(
				'type'              => 'string',
				'default'           => 'DESC',
				'enum'              => array( 'ASC', 'DESC' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'   => array(
				'type'              => 'string',
				'enum'              => array( 'publish', 'draft' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
