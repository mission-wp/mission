<?php
/**
 * Unified note model for transactions, donors, and subscriptions.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\NoteDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Note model with polymorphic object association.
 */
class Note extends Model {

	public string $object_type;
	public int $object_id;
	public string $type;
	public string $content;
	public int $author_id;
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
		$this->type         = $data['type'] ?? 'internal';
		$this->content      = $data['content'] ?? '';
		$this->author_id    = (int) ( $data['author_id'] ?? 0 );
		$this->date_created = $data['date_created'] ?? current_time( 'mysql', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new NoteDataStore();
	}
}
