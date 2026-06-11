<?php
/**
 * REST endpoint for outgoing webhooks.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Models\OutgoingWebhook;
use MissionDP\Models\WebhookDelivery;
use MissionDP\OutgoingWebhooks\DeliveryHandler;
use MissionDP\OutgoingWebhooks\WebhookEvents;
use MissionDP\Rest\RestModule;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Outgoing webhooks endpoint class.
 */
class OutgoingWebhooksEndpoint {

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		// Collection routes.
		register_rest_route(
			RestModule::NAMESPACE,
			'/outgoing-webhooks',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->get_create_params(),
				],
			]
		);

		// Single item routes.
		register_rest_route(
			RestModule::NAMESPACE,
			'/outgoing-webhooks/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->get_update_params(),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);

		// Available events.
		register_rest_route(
			RestModule::NAMESPACE,
			'/outgoing-webhooks/events',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_events' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		// Delivery log for a webhook.
		register_rest_route(
			RestModule::NAMESPACE,
			'/outgoing-webhooks/(?P<id>\d+)/deliveries',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_deliveries' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->get_delivery_params(),
			]
		);

		// Ping (test delivery).
		register_rest_route(
			RestModule::NAMESPACE,
			'/outgoing-webhooks/(?P<id>\d+)/ping',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'ping' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
	}

	/**
	 * Permission check: requires manage_options.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage webhooks.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * GET /outgoing-webhooks: list all webhooks.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
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

		$webhooks = OutgoingWebhook::query( $query_args );
		$total    = OutgoingWebhook::count( $query_args );

		$items       = array_map( [ $this, 'prepare_webhook' ], $webhooks );
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * POST /outgoing-webhooks: create a new webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$url = $request->get_param( 'url' );

		$url_error = $this->validate_url( $url );
		if ( $url_error ) {
			return $url_error;
		}

		$events = $request->get_param( 'events' ) ?? [];

		$events_error = $this->validate_events( $events );
		if ( $events_error ) {
			return $events_error;
		}

		$webhook = new OutgoingWebhook(
			[
				'name'   => $request->get_param( 'name' ) ?? '',
				'url'    => $url,
				'events' => $events,
				'status' => $request->get_param( 'status' ) ?? 'active',
			]
		);

		$webhook->save();

		return new WP_REST_Response( $this->prepare_webhook( $webhook ), 201 );
	}

	/**
	 * GET /outgoing-webhooks/{id}: get a single webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$webhook = OutgoingWebhook::find( (int) $request->get_param( 'id' ) );

		if ( ! $webhook ) {
			return new WP_Error(
				'webhook_not_found',
				__( 'Webhook not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $this->prepare_webhook( $webhook ), 200 );
	}

	/**
	 * PATCH /outgoing-webhooks/{id}: update a webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$webhook = OutgoingWebhook::find( (int) $request->get_param( 'id' ) );

		if ( ! $webhook ) {
			return new WP_Error(
				'webhook_not_found',
				__( 'Webhook not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$params = $request->get_json_params();

		if ( isset( $params['name'] ) ) {
			$webhook->name = sanitize_text_field( $params['name'] );
		}

		if ( isset( $params['url'] ) ) {
			$url_error = $this->validate_url( $params['url'] );
			if ( $url_error ) {
				return $url_error;
			}
			$webhook->url = $params['url'];
		}

		if ( isset( $params['events'] ) ) {
			$events_error = $this->validate_events( $params['events'] );
			if ( $events_error ) {
				return $events_error;
			}
			$webhook->events = $params['events'];
		}

		if ( isset( $params['status'] ) && in_array( $params['status'], [ 'active', 'paused' ], true ) ) {
			// Cancel pending deliveries when pausing.
			if ( 'paused' === $params['status'] && 'active' === $webhook->status && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'missiondp_deliver_webhook', [], 'mission-webhook-' . $webhook->id );
			}

			// Reset failure tracking when reactivating.
			if ( 'active' === $params['status'] && 'paused' === $webhook->status ) {
				$webhook->failure_count = 0;
				$webhook->health        = 'healthy';
				$webhook->failing_since = null;
			}

			$webhook->status = $params['status'];
		}

		if ( isset( $params['regenerate_secret'] ) && $params['regenerate_secret'] ) {
			$webhook->secret = OutgoingWebhook::generate_secret();
		}

		$webhook->save();

		return new WP_REST_Response( $this->prepare_webhook( $webhook ), 200 );
	}

	/**
	 * DELETE /outgoing-webhooks/{id}: delete a webhook and its deliveries.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$webhook = OutgoingWebhook::find( (int) $request->get_param( 'id' ) );

		if ( ! $webhook ) {
			return new WP_Error(
				'webhook_not_found',
				__( 'Webhook not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		// Cancel any pending Action Scheduler actions for this webhook.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'missiondp_deliver_webhook', [], 'mission-webhook-' . $webhook->id );
		}

		$webhook->delete();

		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	/**
	 * GET /outgoing-webhooks/events: list available events grouped by category.
	 *
	 * @return WP_REST_Response
	 */
	public function get_events(): WP_REST_Response {
		return new WP_REST_Response( WebhookEvents::grouped(), 200 );
	}

	/**
	 * GET /outgoing-webhooks/{id}/deliveries: list deliveries for a webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_deliveries( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$webhook = OutgoingWebhook::find( (int) $request->get_param( 'id' ) );

		if ( ! $webhook ) {
			return new WP_Error(
				'webhook_not_found',
				__( 'Webhook not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$per_page = $request->get_param( 'per_page' ) ?? 25;
		$page     = $request->get_param( 'page' ) ?? 1;

		$query_args = [
			'webhook_id' => $webhook->id,
			'per_page'   => $per_page,
			'page'       => $page,
			'orderby'    => 'date_created',
			'order'      => 'DESC',
		];

		$deliveries  = WebhookDelivery::query( $query_args );
		$total       = WebhookDelivery::count( $query_args );
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		$items = array_map( [ $this, 'prepare_delivery' ], $deliveries );

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * POST /outgoing-webhooks/{id}/ping: send a test ping.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function ping( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$webhook = OutgoingWebhook::find( (int) $request->get_param( 'id' ) );

		if ( ! $webhook ) {
			return new WP_Error(
				'webhook_not_found',
				__( 'Webhook not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$event_id = 'evt_' . bin2hex( random_bytes( 12 ) );

		$payload = [
			'id'         => $event_id,
			'event'      => 'ping',
			'created_at' => gmdate( 'c' ),
			'data'       => [
				'message' => 'This is a test ping from Mission.',
			],
		];

		$delivery = new WebhookDelivery(
			[
				'webhook_id'   => $webhook->id,
				'event'        => 'ping',
				'event_id'     => $event_id,
				'url'          => $webhook->url,
				'request_body' => wp_json_encode( $payload ),
				'status'       => 'pending',
			]
		);

		$delivery->save();

		// Deliver synchronously so the user sees the result immediately.
		$handler = new DeliveryHandler();
		$handler->deliver( $delivery->id );

		// Reload after delivery.
		$delivery = WebhookDelivery::find( $delivery->id );

		return new WP_REST_Response( $this->prepare_delivery( $delivery ), 200 );
	}

	/**
	 * Validate a webhook URL.
	 *
	 * @param string $url URL to validate.
	 *
	 * @return WP_Error|null Error if invalid, null if valid.
	 */
	private function validate_url( string $url ): ?WP_Error {
		if ( empty( $url ) ) {
			return new WP_Error(
				'missing_url',
				__( 'A webhook URL is required.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'The URL provided is not valid.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return new WP_Error(
				'invalid_url_scheme',
				__( 'Only HTTP and HTTPS URLs are supported.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		return null;
	}

	/**
	 * Validate an array of event IDs.
	 *
	 * @param array $events Event IDs.
	 *
	 * @return WP_Error|null Error if invalid, null if valid.
	 */
	private function validate_events( array $events ): ?WP_Error {
		foreach ( $events as $event ) {
			if ( ! WebhookEvents::is_valid( $event ) ) {
				return new WP_Error(
					'invalid_event',
					/* translators: %s: event name */
					sprintf( __( 'Invalid event: %s', 'mission-donation-platform' ), $event ),
					[ 'status' => 400 ]
				);
			}
		}

		return null;
	}

	/**
	 * Prepare a webhook for REST response.
	 *
	 * @param OutgoingWebhook $webhook Webhook model.
	 *
	 * @return array<string, mixed>
	 */
	private function prepare_webhook( OutgoingWebhook $webhook ): array {
		return [
			'id'                 => $webhook->id,
			'name'               => $webhook->name,
			'url'                => $webhook->url,
			'secret'             => $webhook->secret,
			'events'             => $webhook->events,
			'status'             => $webhook->status,
			'health'             => $webhook->health,
			'failure_count'      => $webhook->failure_count,
			'failing_since'      => $webhook->failing_since,
			'last_delivery_at'   => $webhook->last_delivery_at,
			'last_response_code' => $webhook->last_response_code,
			'date_created'       => $webhook->date_created,
		];
	}

	/**
	 * Prepare a delivery for REST response.
	 *
	 * @param WebhookDelivery $delivery Delivery model.
	 *
	 * @return array<string, mixed>
	 */
	private function prepare_delivery( WebhookDelivery $delivery ): array {
		return [
			'id'               => $delivery->id,
			'webhook_id'       => $delivery->webhook_id,
			'event'            => $delivery->event,
			'event_id'         => $delivery->event_id,
			'url'              => $delivery->url,
			'request_headers'  => $delivery->request_headers ? json_decode( $delivery->request_headers, true ) : null,
			'request_body'     => $delivery->request_body ? json_decode( $delivery->request_body, true ) : null,
			'response_code'    => $delivery->response_code,
			'response_headers' => $delivery->response_headers ? json_decode( $delivery->response_headers, true ) : null,
			'response_body'    => $delivery->response_body,
			'duration_ms'      => $delivery->duration_ms,
			'attempt'          => $delivery->attempt,
			'status'           => $delivery->status,
			'error_message'    => $delivery->error_message,
			'date_created'     => $delivery->date_created,
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
		];
	}

	/**
	 * Get parameters for creating a webhook.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_create_params(): array {
		return [
			'name'   => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'url'    => [
				'type'     => 'string',
				'required' => true,
			],
			'events' => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type' => 'string',
				],
			],
			'status' => [
				'type'              => 'string',
				'default'           => 'active',
				'enum'              => [ 'active', 'paused' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Get parameters for updating a webhook.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_update_params(): array {
		return [
			'id' => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
		];
	}

	/**
	 * Get parameters for the delivery log.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_delivery_params(): array {
		return [
			'id'       => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
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
		];
	}
}
