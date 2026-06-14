<?php
/**
 * Team model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\TeamDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Team model — groups peer-to-peer fundraisers under a captain.
 *
 * The custom table is the source of truth. Teams carry no stored aggregate
 * columns; amount_raised() is summed live from member fundraisers.
 */
class Team extends Model {

	use HasMeta;

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_PENDING  = 'pending';
	public const STATUS_INACTIVE = 'inactive';

	/**
	 * Every team status.
	 *
	 * @var string[]
	 */
	public const STATUSES = [
		self::STATUS_ACTIVE,
		self::STATUS_PENDING,
		self::STATUS_INACTIVE,
	];

	public const ACCESS_PUBLIC  = 'public';
	public const ACCESS_PRIVATE = 'private';

	/**
	 * Every access level.
	 *
	 * @var string[]
	 */
	public const ACCESS_LEVELS = [
		self::ACCESS_PUBLIC,
		self::ACCESS_PRIVATE,
	];

	public int $campaign_id;
	public ?int $captain_id;
	public int $post_id;
	public string $name;
	public string $description;
	public int $goal;
	public string $cover_image;
	public string $status;
	public string $access;
	public string $date_created;
	public string $date_modified;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id            = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->campaign_id   = (int) ( $data['campaign_id'] ?? 0 );
		$this->captain_id    = isset( $data['captain_id'] ) ? (int) $data['captain_id'] : null;
		$this->post_id       = (int) ( $data['post_id'] ?? 0 );
		$this->name          = $data['name'] ?? '';
		$this->description   = $data['description'] ?? '';
		$this->goal          = (int) ( $data['goal'] ?? 0 );
		$this->cover_image   = $data['cover_image'] ?? '';
		$this->status        = $data['status'] ?? self::STATUS_PENDING;
		$this->access        = $data['access'] ?? self::ACCESS_PUBLIC;
		$this->date_created  = $data['date_created'] ?? current_time( 'mysql', true );
		$this->date_modified = $data['date_modified'] ?? current_time( 'mysql', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new TeamDataStore();
	}

	/**
	 * Get the campaign this team belongs to.
	 *
	 * @return Campaign|null
	 */
	public function campaign(): ?Campaign {
		return $this->campaign_id ? Campaign::find( $this->campaign_id ) : null;
	}

	/**
	 * Get the team captain.
	 *
	 * @return Fundraiser|null
	 */
	public function captain(): ?Fundraiser {
		return $this->captain_id ? Fundraiser::find( $this->captain_id ) : null;
	}

	/**
	 * Get the team's member fundraisers.
	 *
	 * @param array<string, mixed> $args Additional query args.
	 * @return Fundraiser[]
	 */
	public function members( array $args = [] ): array {
		return Fundraiser::query( array_merge( $args, [ 'team_id' => $this->id ] ) );
	}

	/**
	 * Get the team's invitations.
	 *
	 * @param array<string, mixed> $args Additional query args.
	 * @return TeamInvitation[]
	 */
	public function invitations( array $args = [] ): array {
		return TeamInvitation::query( array_merge( $args, [ 'team_id' => $this->id ] ) );
	}

	/**
	 * Count the team's member fundraisers.
	 *
	 * @return int
	 */
	public function member_count(): int {
		return Fundraiser::count( [ 'team_id' => $this->id ] );
	}

	/**
	 * Get the amount raised across all member fundraisers.
	 *
	 * @param bool $is_test Whether to sum the test-mode mirror column.
	 * @return int Total raised in minor units.
	 */
	public function amount_raised( bool $is_test = false ): int {
		/** @var TeamDataStore $store */
		$store = static::store();
		return $store->sum_member_raised( (int) $this->id, $is_test );
	}

	/**
	 * Get progress toward the team goal as a percentage (0-100).
	 *
	 * @param bool $is_test Whether to use test-mode totals.
	 * @return float
	 */
	public function progress( bool $is_test = false ): float {
		if ( $this->goal <= 0 ) {
			return 0.0;
		}

		return min( 100.0, round( $this->amount_raised( $is_test ) / $this->goal * 100, 2 ) );
	}

	/**
	 * Approve this team, moving it to the active status.
	 *
	 * Idempotent: an already-active team is left untouched and no event fires.
	 *
	 * @return bool True on success (or when already active).
	 */
	public function approve(): bool {
		if ( self::STATUS_ACTIVE === $this->status ) {
			return true;
		}

		$this->status = self::STATUS_ACTIVE;

		if ( ! $this->save() ) {
			return false;
		}

		/**
		 * Fires after a team is approved.
		 *
		 * @param Team $team The approved team.
		 */
		do_action( 'mission_team_approved', $this );

		return true;
	}

	/**
	 * Deactivate this team, hiding its page without deleting the record.
	 *
	 * @return bool True on success (or when already inactive).
	 */
	public function deactivate(): bool {
		if ( self::STATUS_INACTIVE === $this->status ) {
			return true;
		}

		$this->status = self::STATUS_INACTIVE;

		if ( ! $this->save() ) {
			return false;
		}

		/**
		 * Fires after a team is deactivated.
		 *
		 * @param Team $team The deactivated team.
		 */
		do_action( 'mission_team_deactivated', $this );

		return true;
	}
}
