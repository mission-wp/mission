<?php
/**
 * Team Invitation model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\TeamInvitationDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Team invitation model — one pending invite to join a private team.
 */
class TeamInvitation extends Model {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_ACCEPTED = 'accepted';
	public const STATUS_EXPIRED  = 'expired';

	/**
	 * Every invitation status.
	 *
	 * @var string[]
	 */
	public const STATUSES = [
		self::STATUS_PENDING,
		self::STATUS_ACCEPTED,
		self::STATUS_EXPIRED,
	];

	public int $team_id;
	public string $email;
	public string $status;
	public string $date_created;
	public ?string $sent_at;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id           = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->team_id      = (int) ( $data['team_id'] ?? 0 );
		$this->email        = $data['email'] ?? '';
		$this->status       = $data['status'] ?? self::STATUS_PENDING;
		$this->date_created = $data['date_created'] ?? current_time( 'mysql', true );
		$this->sent_at      = $data['sent_at'] ?? null;
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new TeamInvitationDataStore();
	}

	/**
	 * Get the team this invitation belongs to.
	 *
	 * @return Team|null
	 */
	public function team(): ?Team {
		return $this->team_id ? Team::find( $this->team_id ) : null;
	}
}
