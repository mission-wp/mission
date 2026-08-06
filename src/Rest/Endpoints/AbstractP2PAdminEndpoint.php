<?php
/**
 * Shared REST plumbing for the peer-to-peer admin endpoints.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Models\Campaign;
use MissionDP\Reporting\ReportingService;
use MissionDP\Rest\Args;
use MissionDP\Rest\CollectionParams;
use MissionDP\Rest\RestErrors;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\AdminPermissionTrait;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the Fundraisers and Teams admin endpoints.
 *
 * Owns the identical route shape, the list/single/delete/approve
 * handlers, and the shared param definitions. Concrete endpoints supply the
 * model class, route slug, response shaping, and the create/update handlers.
 */
abstract class AbstractP2PAdminEndpoint {

	use AdminPermissionTrait;

	/**
	 * Constructor.
	 *
	 * @param ReportingService $reporting Reporting service.
	 * @param SettingsService  $settings  Settings service.
	 */
	public function __construct(
		protected ReportingService $reporting,
		protected SettingsService $settings,
	) {}

	// -----
	// Template methods.
	// -----

	/**
	 * The model class this endpoint manages.
	 *
	 * @return class-string
	 */
	abstract protected function model_class(): string;

	/**
	 * The route slug (e.g. 'fundraisers').
	 *
	 * @return string
	 */
	abstract protected function route_base(): string;

	/**
	 * Message for a create attempt against a non-P2P campaign.
	 *
	 * @return string
	 */
	abstract protected function invalid_campaign_type_message(): string;

	/**
	 * The not-found error for this endpoint's model.
	 *
	 * @return WP_Error
	 */
	abstract protected function not_found_error(): WP_Error;

	/**
	 * Prepare a model for a REST response.
	 *
	 * @param object $item The model instance.
	 * @return array<string, mixed>
	 */
	abstract protected function prepare_item( object $item ): array;

	/**
	 * Run the list query with relations via the reporting service.
	 *
	 * @param array $args Query args.
	 * @return array{items: array, total: int}
	 */
	abstract protected function query_list( array $args ): array;

	/**
	 * Aggregate stats for the summary route.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function summary(): array;

	/**
	 * Allowed orderby values for the collection route.
	 *
	 * @return string[]
	 */
	abstract protected function allowed_orderby(): array;

	/**
	 * Create endpoint parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract protected function get_create_params(): array;

	/**
	 * Update endpoint parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract protected function get_update_params(): array;

	/**
	 * POST handler — creates an item for a P2P campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	abstract public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error;

	/**
	 * PUT handler — updates an item's editable fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	abstract public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error;

	/**
	 * Endpoint-specific collection params merged on top of the shared set.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function extra_collection_params(): array {
		return [];
	}

	/**
	 * Endpoint-specific list-query args merged on top of the shared set.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>
	 */
	protected function extra_list_args( WP_REST_Request $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- uniform override signature.
		return [];
	}

	// -----
	// Route registration.
	// -----

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		$base = $this->route_base();

		register_rest_route(
			RestModule::NAMESPACE,
			"/{$base}",
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => $this->get_create_params(),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			"/{$base}/summary",
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_summary' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			"/{$base}/(?P<id>\d+)",
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [ 'id' => Args::id() ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => $this->get_update_params(),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [ 'id' => Args::id() ],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			"/{$base}/(?P<id>\d+)/approve",
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'approve_item' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [ 'id' => Args::id() ],
			]
		);
	}

	// -----
	// Shared handlers.
	// -----

	/**
	 * GET handler — paginated items with related data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$per_page = $request->get_param( 'per_page' ) ?? 25;

		$result = $this->query_list(
			array_merge(
				[
					'per_page'    => $per_page,
					'page'        => $request->get_param( 'page' ) ?? 1,
					'orderby'     => $request->get_param( 'orderby' ) ?? 'date_created',
					'order'       => $request->get_param( 'order' ) ?? 'DESC',
					'search'      => $request->get_param( 'search' ),
					'campaign_id' => $request->get_param( 'campaign_id' ),
					'status'      => $request->get_param( 'status' ),
				],
				$this->extra_list_args( $request )
			)
		);

		$total       = $result['total'];
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		$response = new WP_REST_Response( $result['items'], 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * GET handler — aggregate stats.
	 *
	 * @return WP_REST_Response
	 */
	public function get_summary(): WP_REST_Response {
		return new WP_REST_Response( $this->summary(), 200 );
	}

	/**
	 * GET handler — a single item with detail fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$item = $this->find_item( (int) $request->get_param( 'id' ) );

		if ( ! $item ) {
			return $this->not_found_error();
		}

		return new WP_REST_Response( $this->prepare_item( $item ), 200 );
	}

	/**
	 * DELETE handler — removes an item record.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$item = $this->find_item( (int) $request->get_param( 'id' ) );

		if ( ! $item ) {
			return $this->not_found_error();
		}

		$item->delete();

		return new WP_REST_Response(
			[
				'deleted' => true,
				'id'      => (int) $request->get_param( 'id' ),
			],
			200
		);
	}

	/**
	 * POST handler — approves a single item.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$item = $this->find_item( (int) $request->get_param( 'id' ) );

		if ( ! $item ) {
			return $this->not_found_error();
		}

		$item->approve();

		return new WP_REST_Response( $this->prepare_item( $item ), 200 );
	}

	// -----
	// Shared helpers.
	// -----

	/**
	 * Find a model instance by ID.
	 *
	 * @param int $id The item ID.
	 * @return object|null
	 */
	protected function find_item( int $id ): ?object {
		$model = $this->model_class();

		return $model::find( $id );
	}

	/**
	 * Find the campaign for a create request and require the P2P type.
	 *
	 * @param int $campaign_id Campaign ID from the request.
	 * @return Campaign|WP_Error
	 */
	protected function find_p2p_campaign( int $campaign_id ): Campaign|WP_Error {
		$campaign = Campaign::find( $campaign_id );

		if ( ! $campaign ) {
			return RestErrors::campaign_not_found();
		}

		if ( ! $campaign->is_p2p() ) {
			return new WP_Error(
				'invalid_campaign_type',
				$this->invalid_campaign_type_message(),
				[ 'status' => 400 ]
			);
		}

		return $campaign;
	}

	/**
	 * Validate a related-record selection: it must exist and belong to the campaign.
	 *
	 * @param mixed  $id          Raw related-record ID param (may be empty/null).
	 * @param int    $campaign_id Campaign the item belongs to.
	 * @param string $model       Model class of the related record.
	 * @param string $code        Error code when invalid.
	 * @param string $message     Error message when invalid.
	 * @return WP_Error|null Error when invalid, null when valid or unset.
	 */
	protected function validate_campaign_relation( mixed $id, int $campaign_id, string $model, string $code, string $message ): ?WP_Error {
		if ( empty( $id ) ) {
			return null;
		}

		$related = $model::find( (int) $id );

		if ( ! $related || $related->campaign_id !== $campaign_id ) {
			return new WP_Error( $code, $message, [ 'status' => 400 ] );
		}

		return null;
	}

	/**
	 * Apply a status change from an update request.
	 *
	 * Transitions go through the model methods so approval fires the
	 * email/activity events (a plain field write would approve silently).
	 *
	 * @param object      $item   The model instance.
	 * @param string|null $status Requested status, or null when not provided.
	 * @return void
	 */
	protected function apply_status_transition( object $item, ?string $status ): void {
		if ( null === $status || $status === $item->status ) {
			return;
		}

		$model = $this->model_class();

		if ( $model::STATUS_ACTIVE === $status ) {
			$item->approve();
		} elseif ( $model::STATUS_INACTIVE === $status ) {
			$item->deactivate();
		} else {
			$item->status = $status;
			$item->save();
		}
	}

	// -----
	// Shared params.
	// -----

	/**
	 * Collection query parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_collection_params(): array {
		$model = $this->model_class();

		return array_merge(
			CollectionParams::base(
				orderby: $this->allowed_orderby(),
				default_orderby: 'date_created'
			),
			[
				'campaign_id' => Args::integer(),
				'status'      => Args::enum( $model::STATUSES ),
			],
			$this->extra_collection_params()
		);
	}
}
