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
	public string $token;
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
		$this->token        = $data['token'] ?? '';
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
	 * Find a pending-or-not invitation by its bearer token.
	 *
	 * @param string $token The invitation token.
	 * @return self|null
	 */
	public static function find_by_token( string $token ): ?self {
		if ( '' === $token ) {
			return null;
		}

		/** @var TeamInvitationDataStore $store */
		$store = static::store();

		return $store->read_by_token( $token );
	}

	/**
	 * Whether this invitation is still pending.
	 *
	 * @return bool
	 */
	public function is_pending(): bool {
		return self::STATUS_PENDING === $this->status;
	}

	/**
	 * Whether this invitation has passed its time-to-live.
	 *
	 * The window is anchored to sent_at (falling back to date_created for
	 * never-sent invites); there is no expires_at column. Anchoring to the
	 * send matters for invites held while a team awaits approval: the
	 * recipient's window starts when the email could first be acted on.
	 *
	 * @return bool
	 */
	public function is_expired(): bool {
		/**
		 * Filters how long a team invitation stays valid, in seconds.
		 *
		 * @param int            $ttl        Lifetime in seconds (default 14 days).
		 * @param TeamInvitation $invitation The invitation being checked.
		 */
		$ttl = (int) apply_filters( 'mission_team_invitation_ttl', 14 * DAY_IN_SECONDS, $this );

		$anchor = strtotime( ( $this->sent_at ?? $this->date_created ) . ' UTC' );
		if ( ! $anchor ) {
			return false;
		}

		return ( $anchor + $ttl ) < time();
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
