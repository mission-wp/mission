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
	 * Meta fields that can be set during campaign creation.
	 *
	 * @var string[]
	 */
	private const CREATABLE_META = array(
		'amounts',
		'custom_amount',
		'minimum_amount',
		'recurring_enabled',
		'recurring_frequencies',
		'recurring_default',
		'fee_recovery',
	);

	/**
	 * All meta fields returned in the single-campaign response.
	 *
	 * @var string[]
	 */
	private const ALL_META = array(
		'amounts',
		'custom_amount',
		'minimum_amount',
		'recurring_enabled',
		'recurring_frequencies',
		'recurring_default',
		'fee_recovery',
		'tip_enabled',
		'tip_percentages',
		'anonymous_enabled',
		'tribute_enabled',
		'confirmation_message',
	);

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
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_campaigns' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_campaign' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_create_params(),
				),
			)
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/campaigns/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_campaign' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
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
				),
			)
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/campaigns/batch-delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'batch_delete_campaigns' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'ids' => array(
						'type'              => 'array',
						'required'          => true,
						'items'             => array(
							'type' => 'integer',
						),
						'sanitize_callback' => static function ( $ids ) {
							return array_map( 'absint', $ids );
						},
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
	 * Compute campaign status from start/end dates.
	 *
	 * @param string|null $date_start Start date (Y-m-d).
	 * @param string|null $date_end   End date (Y-m-d).
	 * @return string 'active', 'scheduled', or 'ended'.
	 */
	private function compute_status( ?string $date_start, ?string $date_end ): string {
		$today = wp_date( 'Y-m-d' );
		$start = $date_start ? substr( $date_start, 0, 10 ) : null;
		$end   = $date_end ? substr( $date_end, 0, 10 ) : null;

		if ( $end && $end < $today ) {
			return 'ended';
		}

		if ( $start && $start > $today ) {
			return 'scheduled';
		}

		return 'active';
	}

	/**
	 * POST handler — creates a new campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'mission_campaign',
				'post_title'   => $request->get_param( 'title' ),
				'post_excerpt' => $request->get_param( 'excerpt' ) ?? '',
				'post_status'  => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Save meta fields.
		foreach ( self::CREATABLE_META as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				update_post_meta( $post_id, "_mission_campaign_{$key}", $value );
			}
		}

		// goal_amount is stored in both meta and custom table. The save_post
		// hook already created the table row, but goal_amount meta wasn't set
		// yet at that point, so we sync it now along with date fields.
		$goal_amount = $request->get_param( 'goal_amount' );
		if ( null !== $goal_amount ) {
			update_post_meta( $post_id, '_mission_campaign_goal_amount', $goal_amount );
		}

		$date_start = $request->get_param( 'date_start' ) ?? wp_date( 'Y-m-d' );
		$date_end   = $request->get_param( 'date_end' );

		$campaign = $this->campaign_store->find_by_post_id( $post_id );
		if ( $campaign ) {
			if ( null !== $goal_amount ) {
				$campaign->goal_amount = $goal_amount;
			}
			$campaign->date_start = $date_start;
			if ( null !== $date_end ) {
				$campaign->date_end = $date_end;
			}
			$this->campaign_store->update( $campaign );
		}

		return new WP_REST_Response( $this->prepare_single_campaign( $post_id ), 201 );
	}

	/**
	 * GET handler — returns a single campaign with full detail.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'mission_campaign' !== $post->post_type ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Campaign not found.', 'mission' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $this->prepare_single_campaign( $post_id ), 200 );
	}

	/**
	 * Prepare a full single-campaign response combining post, meta, and custom table data.
	 *
	 * @param int $post_id The campaign post ID.
	 * @return array<string, mixed>
	 */
	private function prepare_single_campaign( int $post_id ): array {
		$post     = get_post( $post_id );
		$campaign = $this->campaign_store->find_by_post_id( $post_id );

		$meta = array();
		foreach ( self::ALL_META as $key ) {
			$meta[ $key ] = get_post_meta( $post_id, "_mission_campaign_{$key}", true );
		}

		$date_start = $campaign?->date_start;
		$date_end   = $campaign?->date_end;

		return array(
			'id'                => $post->ID,
			'title'             => $post->post_title,
			'excerpt'           => $post->post_excerpt,
			'status'            => $this->compute_status( $date_start, $date_end ),
			'edit_url'          => get_edit_post_link( $post->ID, 'raw' ),
			'view_url'          => get_permalink( $post->ID ),
			'date_created'      => $post->post_date_gmt,
			'goal_amount'       => $campaign?->goal_amount ?? 0,
			'total_raised'      => $campaign?->total_raised ?? 0,
			'transaction_count' => $campaign?->transaction_count ?? 0,
			'currency'          => $campaign?->currency ?? 'usd',
			'date_start'        => $date_start,
			'date_end'          => $date_end,
			'meta'              => $meta,
		);
	}

	/**
	 * GET handler — returns paginated campaigns with financial data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_campaigns( WP_REST_Request $request ): WP_REST_Response {
		$orderby_param = $request->get_param( 'orderby' ) ?? 'date';
		$order         = strtoupper( $request->get_param( 'order' ) ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$query_args = array(
			'post_type'      => 'mission_campaign',
			'posts_per_page' => $request->get_param( 'per_page' ) ?? 25,
			'paged'          => $request->get_param( 'page' ) ?? 1,
			'orderby'        => $this->map_orderby( $orderby_param ),
			'order'          => $order,
		);

		$query_args['post_status'] = 'publish';

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$query_args['s'] = $search;
		}

		// Build clauses filter for custom table join (needed for ordering and/or status filtering).
		$status         = $request->get_param( 'status' );
		$needs_join     = $this->is_custom_table_orderby( $orderby_param ) || ! empty( $status );
		$clauses_filter = null;

		if ( $needs_join ) {
			$table          = $this->campaign_store->get_table_name();
			$column         = $this->is_custom_table_orderby( $orderby_param ) ? $orderby_param : null;
			$clauses_filter = static function ( array $clauses ) use ( $table, $column, $order, $status ) {
				global $wpdb;
				$clauses['join'] .= " LEFT JOIN {$table} AS mc ON {$wpdb->posts}.ID = mc.post_id";

				if ( $column ) {
					$clauses['orderby'] = "mc.{$column} {$order}";
				}

				if ( ! empty( $status ) ) {
					$today = wp_date( 'Y-m-d' );
					$where = match ( $status ) {
						'active'    => $wpdb->prepare(
							' AND (mc.date_start IS NULL OR mc.date_start <= %s) AND (mc.date_end IS NULL OR mc.date_end >= %s)',
							$today,
							$today
						),
						'scheduled' => $wpdb->prepare( ' AND mc.date_start > %s', $today ),
						'ended'     => $wpdb->prepare( ' AND mc.date_end IS NOT NULL AND mc.date_end < %s', $today ),
						default     => '',
					};
					$clauses['where'] .= $where;
				}

				return $clauses;
			};
			add_filter( 'posts_clauses', $clauses_filter );
		}

		$query = new WP_Query( $query_args );

		if ( $clauses_filter ) {
			remove_filter( 'posts_clauses', $clauses_filter );
		}
		$items = array();

		foreach ( $query->posts as $post ) {
			$campaign   = $this->campaign_store->find_by_post_id( $post->ID );
			$date_start = $campaign?->date_start;
			$date_end   = $campaign?->date_end;

			$items[] = array(
				'id'                => $post->ID,
				'title'             => $post->post_title,
				'status'            => $this->compute_status( $date_start, $date_end ),
				'goal_amount'       => $campaign?->goal_amount ?? 0,
				'total_raised'      => $campaign?->total_raised ?? 0,
				'transaction_count' => $campaign?->transaction_count ?? 0,
				'edit_url'          => get_edit_post_link( $post->ID, 'raw' ),
				'date_start'        => $date_start,
				'date_end'          => $date_end,
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
	 * POST handler — trashes multiple campaign posts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function batch_delete_campaigns( WP_REST_Request $request ): WP_REST_Response {
		$ids     = $request->get_param( 'ids' );
		$deleted = array();
		$errors  = array();

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post || 'mission_campaign' !== $post->post_type ) {
				$errors[] = $post_id;
				continue;
			}

			$result = wp_trash_post( $post_id );

			if ( $result ) {
				$deleted[] = $post_id;
			} else {
				$errors[] = $post_id;
			}
		}

		return new WP_REST_Response(
			array(
				'deleted' => $deleted,
				'errors'  => $errors,
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
	/**
	 * Custom table columns that can be used for ordering.
	 *
	 * @var string[]
	 */
	private const CUSTOM_TABLE_ORDERBY = array(
		'date_start',
		'date_end',
		'goal_amount',
		'total_raised',
		'transaction_count',
	);

	/**
	 * Check if the orderby value refers to a custom table column.
	 *
	 * @param string $orderby The REST orderby param.
	 * @return bool
	 */
	private function is_custom_table_orderby( string $orderby ): bool {
		return in_array( $orderby, self::CUSTOM_TABLE_ORDERBY, true );
	}

	private function map_orderby( string $orderby ): string {
		return match ( $orderby ) {
			'title' => 'title',
			'date'  => 'date',
			default => 'date',
		};
	}

	/**
	 * Get create endpoint parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_create_params(): array {
		return array(
			'title'                 => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'excerpt'               => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'goal_amount'           => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'date_start'            => array(
				'type' => array( 'string', 'null' ),
			),
			'date_end'              => array(
				'type' => array( 'string', 'null' ),
			),
			'amounts'               => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'custom_amount'         => array(
				'type' => 'boolean',
			),
			'minimum_amount'        => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'recurring_enabled'     => array(
				'type' => 'boolean',
			),
			'recurring_frequencies' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'recurring_default'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'fee_recovery'          => array(
				'type' => 'boolean',
			),
		);
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
				'enum'              => array( 'date', 'title', 'date_start', 'date_end', 'goal_amount', 'total_raised', 'transaction_count' ),
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
				'enum'              => array( 'active', 'scheduled', 'ended' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
