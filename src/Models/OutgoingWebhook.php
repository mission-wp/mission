<?php
/**
 * Outgoing webhook model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\OutgoingWebhookDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Outgoing webhook model.
 */
class OutgoingWebhook extends Model {

	public string $name;
	public string $url;
	public string $secret;
	public array $events;
	public string $status;
	public string $health;
	public int $failure_count;
	public ?string $failing_since;
	public ?string $last_delivery_at;
	public ?int $last_response_code;
	public string $date_created;
	public string $date_modified;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id                 = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->name               = $data['name'] ?? '';
		$this->url                = $data['url'] ?? '';
		$this->secret             = $data['secret'] ?? self::generate_secret();
		$this->events             = $data['events'] ?? [];
		$this->status             = $data['status'] ?? 'active';
		$this->health             = $data['health'] ?? 'healthy';
		$this->failure_count      = (int) ( $data['failure_count'] ?? 0 );
		$this->failing_since      = $data['failing_since'] ?? null;
		$this->last_delivery_at   = $data['last_delivery_at'] ?? null;
		$this->last_response_code = isset( $data['last_response_code'] ) ? (int) $data['last_response_code'] : null;
		$this->date_created       = $data['date_created'] ?? current_time( 'mysql', true );
		$this->date_modified      = $data['date_modified'] ?? current_time( 'mysql', true );
	}

	/**
	 * Check whether this webhook subscribes to a given event.
	 *
	 * @param string $event Event name (e.g. 'donation.completed').
	 *
	 * @return bool
	 */
	public function subscribes_to( string $event ): bool {
		return in_array( '*', $this->events, true ) || in_array( $event, $this->events, true );
	}

	/**
	 * Generate a cryptographically secure signing secret.
	 *
	 * @return string 64-character hex string.
	 */
	public static function generate_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new OutgoingWebhookDataStore();
	}
}
