<?php
/**
 * Activity log model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\ActivityLogDataStore;
use MissionDP\Database\DataStore\DataStoreInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Activity log model.
 */
class ActivityLog extends Model {

	public string $object_type;
	public int $object_id;
	public string $event;
	public ?int $actor_id;
	public ?string $data;
	public bool $is_test;
	public string $level;
	public string $category;
	public string $date_created;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id           = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->object_type  = $data['object_type'] ?? '';
		$this->object_id    = (int) ( $data['object_id'] ?? 0 );
		$this->event        = $data['event'] ?? '';
		$this->actor_id     = isset( $data['actor_id'] ) ? (int) $data['actor_id'] : null;
		$this->data         = $data['data'] ?? null;
		$this->is_test      = (bool) ( $data['is_test'] ?? false );
		$this->level        = $data['level'] ?? 'info';
		$this->category     = $data['category'] ?? 'system';
		$this->date_created = $data['date_created'] ?? current_time( 'mysql', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new ActivityLogDataStore();
	}
}
