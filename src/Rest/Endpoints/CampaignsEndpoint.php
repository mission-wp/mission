<?php
/**
 * REST endpoint for campaigns.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Models\Campaign;
use MissionDP\Reporting\ReportingService;
use MissionDP\Rest\RestModule;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
defined( 'ABSPATH' ) || exit;

/**
 * Campaigns endpoint class.
 */
class CampaignsEndpoint {

	/**
	 * Constructor.
	 *
	 * @param ReportingService $reporting Reporting service.
	 * @param SettingsService  $settings  Settings service.
	 */
	public function __construct(
		private readonly ReportingService $reporting,
		private readonly SettingsService $settings,
	) {}

	/**
	 * Meta fields that can be set during campaign creation.
	 *
	 * @var string[]
	 */
	private const CREATABLE_META = [];

	/**
	 * Meta fields that can be updated via PUT.
	 *
	 * @var string[]
	 */
	private const UPDATABLE_META = [
		'close_on_goal',
		'stop_donations_on_end',
		'show_ended_message',
		'remove_from_listings_on_end',
		'recurring_end_behavior',
		'recurring_redirect_campaign',
	];

	/**
	 * All meta fields returned in the single-campaign response.
	 *
	 * @var string[]
	 */
	private const ALL_META = [
		'close_on_goal',
		'stop_donations_on_end',
		'show_ended_message',
		'remove_from_listings_on_end',
		'recurring_end_behavior',
		'recurring_redirect_campaign',
	];

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/campaigns',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_campaigns' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_campaign' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->get_create_params(),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/campaigns/summary',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_summary' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/campaigns/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_campaign' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'id' => [
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
					],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_campaign' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->get_update_params(),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_campaign' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'id' => [
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/campaigns/batch-delete',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'batch_delete_campaigns' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'ids' => [
						'type'              => 'array',
						'required'          => true,
						'items'             => [
							'type' => 'integer',
						],
						'sanitize_callback' => static function ( $ids ) {
							return array_map( 'absint', $ids );
						},
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
				__( 'You do not have permission to view campaigns.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * GET handler — returns aggregate campaign statistics.
	 *
	 * @return WP_REST_Response
	 */
	/**
	 * Whether test mode is active.
	 *
	 * @return bool
	 */
	private function is_test_mode(): bool {
		return (bool) $this->settings->get( 'test_mode' );
	}

	public function get_summary(): WP_REST_Response {
		return new WP_REST_Response( $this->reporting->campaign_summary(), 200 );
	}

	/**
	 * POST handler — creates a new campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign = new Campaign(
			[
				'title'       => $request->get_param( 'title' ),
				'description' => $request->get_param( 'excerpt' ) ?? '',
				'goal_amount' => $request->get_param( 'goal_amount' ),
				'goal_type'   => $request->get_param( 'goal_type' ) ?? 'amount',
				'date_start'  => $request->get_param( 'date_start' ) ?? wp_date( 'Y-m-d' ),
				'date_end'    => $request->get_param( 'date_end' ),
			]
		);

		$result = $campaign->save();

		if ( ! $result ) {
			return new WP_Error(
				'rest_cannot_create',
				__( 'The campaign could not be created.', 'mission-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		// Save campaign image to campaign meta.
		$image = $request->get_param( 'image' );
		if ( $image ) {
			$campaign->set_image( $image );
		}

		// Save meta fields to campaign_meta table.
		foreach ( self::CREATABLE_META as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$campaign->update_meta( $key, $value );
			}
		}

		return new WP_REST_Response( $this->prepare_single_campaign( $campaign ), 201 );
	}

	/**
	 * GET handler — returns a single campaign with full detail.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign_id = $request->get_param( 'id' );
		$campaign    = Campaign::find( $campaign_id );

		if ( ! $campaign ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Campaign not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $this->prepare_single_campaign( $campaign ), 200 );
	}

	/**
	 * Prepare a full single-campaign response from a Campaign model.
	 *
	 * @param Campaign $campaign The campaign model.
	 * @return array<string, mixed>
	 */
	private function prepare_single_campaign( Campaign $campaign ): array {
		$all_meta = $campaign->get_all_meta();
		$meta     = [];
		foreach ( self::ALL_META as $key ) {
			$meta[ $key ] = array_key_exists( $key, $all_meta ) ? $all_meta[ $key ] : null;
		}

		$milestones = $all_meta['milestones'] ?? [];
		$is_test    = $this->is_test_mode();

		return [
			'id'                => $campaign->id,
			'post_id'           => $campaign->post_id,
			'title'             => $campaign->title,
			'excerpt'           => $campaign->description,
			'status'            => $campaign->status,
			'has_campaign_page' => $campaign->has_campaign_page(),
			'show_in_listings'  => $campaign->show_in_listings,
			'slug'              => $campaign->slug,
			'edit_url'          => $campaign->get_edit_url(),
			'url'               => $campaign->get_url(),
			'image'             => $campaign->get_image_id(),
			'image_url'         => $campaign->get_image_url(),
			'image_urls'        => self::get_all_image_urls( $campaign ),
			'date_created'      => $campaign->date_created,
			'goal_amount'       => $campaign->goal_amount,
			'goal_type'         => $campaign->goal_type,
			'goal_progress'     => $campaign->get_goal_progress( $is_test ),
			'total_raised'      => $is_test ? $campaign->test_total_raised : $campaign->total_raised,
			'transaction_count' => $is_test ? $campaign->test_transaction_count : $campaign->transaction_count,
			'donor_count'       => $is_test ? $campaign->test_donor_count : $campaign->donor_count,
			'currency'          => $campaign->currency,
			'date_start'        => $campaign->date_start,
			'date_end'          => $campaign->date_end,
			'meta'              => $meta,
			'milestones'        => $milestones ?: [],
		];
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
		$per_page      = $request->get_param( 'per_page' ) ?? 25;
		$page          = $request->get_param( 'page' ) ?? 1;

		$orderby = 'date' === $orderby_param ? 'date_created' : $orderby_param;

		if ( $this->is_test_mode() ) {
			$test_map = [
				'total_raised'      => 'test_total_raised',
				'transaction_count' => 'test_transaction_count',
			];
			$orderby  = $test_map[ $orderby ] ?? $orderby;
		}

		$query_args = [
			'per_page' => $per_page,
			'page'     => $page,
			'orderby'  => $orderby,
			'order'    => $order,
		];

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$query_args['search'] = $search;
		}

		$status = $request->get_param( 'status' );
		if ( ! empty( $status ) ) {
			$query_args['status'] = $status;
		}

		$campaigns = Campaign::query( $query_args );
		$total     = Campaign::count( $query_args );

		$is_test = $this->is_test_mode();
		$items   = [];
		foreach ( $campaigns as $campaign ) {
			$items[] = [
				'id'                => $campaign->id,
				'post_id'           => $campaign->post_id,
				'title'             => $campaign->title,
				'description'       => $campaign->description,
				'status'            => $campaign->status,
				'goal_amount'       => $campaign->goal_amount,
				'goal_type'         => $campaign->goal_type,
				'goal_progress'     => $campaign->get_goal_progress( $is_test ),
				'total_raised'      => $is_test ? $campaign->test_total_raised : $campaign->total_raised,
				'transaction_count' => $is_test ? $campaign->test_transaction_count : $campaign->transaction_count,
				'donor_count'       => $is_test ? $campaign->test_donor_count : $campaign->donor_count,
				'edit_url'          => $campaign->get_edit_url(),
				'image'             => $campaign->get_image_id(),
				'image_urls'        => self::get_all_image_urls( $campaign ),
				'url'               => $campaign->get_url(),
				'date_start'        => $campaign->date_start,
				'date_end'          => $campaign->date_end,
			];
		}

		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * Get all registered image size URLs for a campaign.
	 *
	 * @param Campaign $campaign The campaign model.
	 * @return array<string, string> Map of size name to URL.
	 */
	private static function get_all_image_urls( Campaign $campaign ): array {
		$image_id = $campaign->get_image_id();
		if ( ! $image_id ) {
			return [];
		}

		$urls     = [];
		$metadata = wp_get_attachment_metadata( $image_id );
		$sizes    = isset( $metadata['sizes'] ) ? array_keys( $metadata['sizes'] ) : [];

		foreach ( $sizes as $size ) {
			$url = wp_get_attachment_image_url( $image_id, $size );
			if ( $url ) {
				$urls[ $size ] = $url;
			}
		}

		$full_url = wp_get_attachment_image_url( $image_id, 'full' );
		if ( $full_url ) {
			$urls['full'] = $full_url;
		}

		return $urls;
	}

	/**
	 * DELETE handler — trashes a campaign post and deletes its custom table row.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign_id = $request->get_param( 'id' );
		$campaign    = Campaign::find( $campaign_id );

		if ( ! $campaign ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Campaign not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$result = $campaign->trash();

		if ( ! $result ) {
			return new WP_Error(
				'rest_cannot_delete',
				__( 'The campaign could not be deleted.', 'mission-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'deleted' => true,
				'id'      => $campaign_id,
			],
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
		$deleted = [];
		$errors  = [];

		foreach ( $ids as $campaign_id ) {
			$campaign = Campaign::find( $campaign_id );

			if ( ! $campaign ) {
				$errors[] = $campaign_id;
				continue;
			}

			$result = $campaign->trash();

			if ( $result ) {
				$deleted[] = $campaign_id;
			} else {
				$errors[] = $campaign_id;
			}
		}

		return new WP_REST_Response(
			[
				'deleted' => $deleted,
				'errors'  => $errors,
			],
			200
		);
	}

	/**
	 * PUT handler — updates an existing campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign = Campaign::find( $request->get_param( 'id' ) );

		if ( ! $campaign ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Campaign not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$goal_changed = false;

		// Update goal type.
		if ( null !== $request->get_param( 'goal_type' ) ) {
			$new_type = $request->get_param( 'goal_type' );
			if ( $campaign->goal_type !== $new_type ) {
				$goal_changed = true;
			}
			$campaign->goal_type = $new_type;
		}

		// Update table fields.
		if ( null !== $request->get_param( 'goal_amount' ) ) {
			$raw_goal = (int) $request->get_param( 'goal_amount' );
			if ( $raw_goal < 0 ) {
				return new WP_Error(
					'rest_invalid_goal',
					__( 'Goal amount cannot be negative.', 'mission-donation-platform' ),
					[ 'status' => 400 ]
				);
			}
			$new_goal              = $raw_goal;
			$goal_changed          = $campaign->goal_amount !== $new_goal;
			$campaign->goal_amount = $new_goal;
		}

		if ( null !== $request->get_param( 'date_start' ) ) {
			$campaign->date_start = $request->get_param( 'date_start' ) ?: null;
		}

		if ( null !== $request->get_param( 'date_end' ) ) {
			$campaign->date_end = $request->get_param( 'date_end' ) ?: null;
		}

		$excerpt = $request->get_param( 'excerpt' );
		if ( null !== $excerpt ) {
			$campaign->description = $excerpt;
		}

		$campaign->save();

		// Update campaign image.
		if ( $request->has_param( 'image' ) ) {
			$image = $request->get_param( 'image' );
			if ( $image ) {
				$campaign->set_image( $image );
			} else {
				$campaign->remove_image();
			}
		}

		// Toggle campaign page visibility.
		if ( $request->has_param( 'has_campaign_page' ) ) {
			$campaign->set_campaign_page_enabled( (bool) $request->get_param( 'has_campaign_page' ) );
		}

		// Toggle show in listings.
		if ( $request->has_param( 'show_in_listings' ) ) {
			$campaign->show_in_listings = (bool) $request->get_param( 'show_in_listings' );
			$campaign->save();
		}

		// Update slug via WP post (after status change so it isn't overwritten).
		$slug = $request->get_param( 'slug' );
		if ( null !== $slug && $campaign->post_id ) {
			wp_update_post(
				[
					'ID'        => $campaign->post_id,
					'post_name' => sanitize_title( $slug ),
				]
			);
		}

		// Update meta fields.
		foreach ( self::UPDATABLE_META as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$campaign->update_meta( $key, $value );
			}
		}

		if ( $goal_changed ) {
			/**
			 * Fires when a campaign's goal amount is changed.
			 *
			 * @param int $campaign_id Campaign ID.
			 */
			do_action( 'missiondp_campaign_goal_updated', $campaign->id );
		}

		/**
		 * Fires after a campaign is updated via the REST API.
		 *
		 * @param int      $campaign_id Campaign ID.
		 * @param Campaign $campaign    Campaign model.
		 */
		do_action( 'missiondp_campaign_updated', $campaign->id, $campaign );

		return new WP_REST_Response( $this->prepare_single_campaign( $campaign ), 200 );
	}

	/**
	 * Get create endpoint parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_create_params(): array {
		return [
			'title'       => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'excerpt'     => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'goal_amount' => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'goal_type'   => [
				'type'              => 'string',
				'default'           => 'amount',
				'enum'              => [ 'amount', 'donations', 'donors' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_start'  => [
				'type' => [ 'string', 'null' ],
			],
			'date_end'    => [
				'type' => [ 'string', 'null' ],
			],
			'image'       => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
		];
	}

	/**
	 * Get update endpoint parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_update_params(): array {
		return [
			'id'                          => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
			'title'                       => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'excerpt'                     => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'goal_amount'                 => [
				'type' => 'integer',
			],
			'goal_type'                   => [
				'type'              => 'string',
				'enum'              => [ 'amount', 'donations', 'donors' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_start'                  => [
				'type' => [ 'string', 'null' ],
			],
			'date_end'                    => [
				'type' => [ 'string', 'null' ],
			],
			'slug'                        => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'image'                       => [
				'type' => [ 'integer', 'null' ],
			],
			'has_campaign_page'           => [
				'type' => 'boolean',
			],
			'show_in_listings'            => [
				'type' => 'boolean',
			],
			'close_on_goal'               => [
				'type' => 'boolean',
			],
			'stop_donations_on_end'       => [
				'type' => 'boolean',
			],
			'show_ended_message'          => [
				'type' => 'boolean',
			],
			'remove_from_listings_on_end' => [
				'type' => 'boolean',
			],
			'recurring_end_behavior'      => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'recurring_redirect_campaign' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Get collection query parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_collection_params(): array {
		return [
			'page'     => [
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page' => [
				'type'              => 'integer',
				'default'           => 25,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			],
			'orderby'  => [
				'type'              => 'string',
				'default'           => 'date',
				'enum'              => [ 'date', 'title', 'date_start', 'date_end', 'goal_amount', 'total_raised', 'transaction_count' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'order'    => [
				'type'              => 'string',
				'default'           => 'DESC',
				'enum'              => [ 'ASC', 'DESC' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'search'   => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'status'   => [
				'type'              => 'string',
				'enum'              => [ 'active', 'scheduled', 'ended' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}
}
