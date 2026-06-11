<?php
/**
 * Webhook delivery model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\WebhookDeliveryDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook delivery log entry model.
 */
class WebhookDelivery extends Model {

	public int $webhook_id;
	public string $event;
	public string $event_id;
	public string $url;
	public ?string $request_headers;
	public ?string $request_body;
	public ?int $response_code;
	public ?string $response_headers;
	public ?string $response_body;
	public ?int $duration_ms;
	public int $attempt;
	public string $status;
	public ?string $error_message;
	public string $date_created;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id               = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->webhook_id       = (int) ( $data['webhook_id'] ?? 0 );
		$this->event            = $data['event'] ?? '';
		$this->event_id         = $data['event_id'] ?? '';
		$this->url              = $data['url'] ?? '';
		$this->request_headers  = $data['request_headers'] ?? null;
		$this->request_body     = $data['request_body'] ?? null;
		$this->response_code    = isset( $data['response_code'] ) ? (int) $data['response_code'] : null;
		$this->response_headers = $data['response_headers'] ?? null;
		$this->response_body    = $data['response_body'] ?? null;
		$this->duration_ms      = isset( $data['duration_ms'] ) ? (int) $data['duration_ms'] : null;
		$this->attempt          = (int) ( $data['attempt'] ?? 1 );
		$this->status           = $data['status'] ?? 'pending';
		$this->error_message    = $data['error_message'] ?? null;
		$this->date_created     = $data['date_created'] ?? current_time( 'mysql', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new WebhookDeliveryDataStore();
	}
}
