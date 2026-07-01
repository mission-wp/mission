<?php
/**
 * Team model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\TeamDataStore;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Team model — groups peer-to-peer fundraisers under a captain.
 *
 * The custom table is the source of truth. Teams carry no stored aggregate
 * columns; amount_raised() is summed live from member fundraisers.
 */
class Team extends Model {

	use HasMeta;
	use HasShellPost;

	/**
	 * Post type slug for team shell posts.
	 *
	 * Registered by TeamPostType; defined here so the model owns the single
	 * source of truth the post type and trait both reference.
	 */
	public const POST_TYPE = 'missiondp_team';

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
	 * Find a team by its linked shell post ID.
	 *
	 * @param int $post_id The WP post ID.
	 * @return self|null
	 */
	public static function find_by_post_id( int $post_id ): ?self {
		/** @var TeamDataStore $store */
		$store = static::store();
		return $store->find_by_post_id( $post_id );
	}

	/**
	 * Create a new team (and its shell post) during registration.
	 *
	 * The captain is set separately via set_captain() once the captain
	 * fundraiser exists, because the two rows reference each other.
	 *
	 * @param int    $campaign_id Parent campaign ID.
	 * @param string $name        Team name.
	 * @param int    $goal        Team goal in minor units.
	 * @param string $access      Access level (public/private).
	 * @param string $status      Initial status (active/pending).
	 * @return self The saved team.
	 */
	public static function register( int $campaign_id, string $name, int $goal, string $access = self::ACCESS_PUBLIC, string $status = self::STATUS_ACTIVE ): self {
		$team = new self(
			[
				'campaign_id' => $campaign_id,
				'name'        => $name,
				'goal'        => $goal,
				'access'      => $access,
				'status'      => $status,
			]
		);

		$team->save();

		return $team;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function shell_post_type(): string {
		return self::POST_TYPE;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function shell_post_title(): string {
		return '' !== $this->name ? $this->name : __( 'Team', 'mission-donation-platform' );
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
	 * Set the team captain, completing the circular foreign key.
	 *
	 * Called after the captain's fundraiser row exists (a team is created
	 * before its captain, so captain_id is backfilled here).
	 *
	 * @param Fundraiser $captain The captain fundraiser.
	 * @return bool True on success.
	 */
	public function set_captain( Fundraiser $captain ): bool {
		$this->captain_id = $captain->id;

		return (bool) $this->save();
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
	 * Get the amount raised: member fundraisers plus direct team gifts.
	 *
	 * @param bool $is_test Whether to sum test-mode amounts.
	 * @return int Total raised in minor units.
	 */
	public function amount_raised( bool $is_test = false ): int {
		/** @var TeamDataStore $store */
		$store = static::store();
		return $store->sum_amount_raised( (int) $this->id, $is_test );
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

	/**
	 * Invite an email address to join this (private) team.
	 *
	 * Idempotent per (team, email): an outstanding pending invite is returned
	 * as-is, and only re-emailed after a cooldown so the invite flow can't be
	 * used to spam an address. The bearer token is a fresh CSPRNG value; the
	 * email and sent_at stamp are handled by the email listener, not here.
	 *
	 * @param string $email The invitee's email address.
	 * @return TeamInvitation|WP_Error The invitation, or an error.
	 */
	public function invite( string $email ): TeamInvitation|WP_Error {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'mission-donation-platform' ) );
		}

		// Already a member of this team? Nothing to invite.
		$donor = Donor::find_by_email( $email );
		if ( $donor && Fundraiser::count(
			[
				'team_id'  => $this->id,
				'donor_id' => $donor->id,
			]
		) > 0 ) {
			return new WP_Error( 'already_member', __( 'That person is already on the team.', 'mission-donation-platform' ) );
		}

		// Reuse an outstanding pending invite rather than minting duplicates.
		$existing = TeamInvitation::query(
			[
				'team_id'  => $this->id,
				'email'    => $email,
				'status'   => TeamInvitation::STATUS_PENDING,
				'per_page' => 1,
			]
		);
		if ( $existing ) {
			$invitation = $existing[0];

			/**
			 * Filters how long to wait before an outstanding invitation may be re-emailed.
			 *
			 * @param int            $cooldown   Cooldown in seconds (default 15 minutes).
			 * @param TeamInvitation $invitation The outstanding invitation.
			 */
			$cooldown  = (int) apply_filters( 'mission_team_invitation_resend_cooldown', 15 * MINUTE_IN_SECONDS, $invitation );
			$last_sent = $invitation->sent_at ? (int) strtotime( $invitation->sent_at . ' UTC' ) : 0;

			if ( ! $last_sent || ( $last_sent + $cooldown ) <= time() ) {
				do_action( 'mission_team_invitation_created', $invitation );
			}

			return $invitation;
		}

		$invitation = new TeamInvitation(
			[
				'team_id' => $this->id,
				'email'   => $email,
				'token'   => bin2hex( random_bytes( 16 ) ),
				'status'  => TeamInvitation::STATUS_PENDING,
			]
		);
		$invitation->save();

		return $invitation;
	}

	/**
	 * Remove a member from this team.
	 *
	 * The captain cannot be removed; promote a successor first. Clears the
	 * member's team association.
	 *
	 * @param Fundraiser $fundraiser The member to remove.
	 * @return bool|WP_Error True on success, or an error.
	 */
	public function remove_member( Fundraiser $fundraiser ): bool|WP_Error {
		if ( (int) $fundraiser->team_id !== (int) $this->id ) {
			return new WP_Error( 'not_a_member', __( 'That person is not on this team.', 'mission-donation-platform' ) );
		}

		if ( (int) $fundraiser->id === (int) $this->captain_id ) {
			return new WP_Error( 'cannot_remove_captain', __( 'Promote another member to captain before leaving the team.', 'mission-donation-platform' ) );
		}

		return $fundraiser->leave_team();
	}

	/**
	 * Promote a member to captain, demoting the current captain.
	 *
	 * Updates both sides of the circular captain/team association: the old
	 * captain's flag is cleared, the new captain's flag is set, and the team's
	 * captain_id is repointed.
	 *
	 * @param Fundraiser $fundraiser The member to promote.
	 * @return bool|WP_Error True on success, or an error.
	 */
	public function promote_captain( Fundraiser $fundraiser ): bool|WP_Error {
		if ( (int) $fundraiser->team_id !== (int) $this->id ) {
			return new WP_Error( 'not_a_member', __( 'That person is not on this team.', 'mission-donation-platform' ) );
		}

		if ( (int) $fundraiser->id === (int) $this->captain_id ) {
			return true;
		}

		$old_captain = $this->captain();

		if ( $old_captain ) {
			$old_captain->is_team_captain = false;
			$old_captain->save();
		}

		$fundraiser->is_team_captain = true;
		$fundraiser->save();

		$this->captain_id = (int) $fundraiser->id;
		if ( ! $this->save() ) {
			return false;
		}

		/**
		 * Fires after a team captain is changed.
		 *
		 * @param Team       $team        The team.
		 * @param Fundraiser $captain     The new captain.
		 * @param Fundraiser|null $previous The previous captain, if any.
		 */
		do_action( 'mission_team_captain_promoted', $this, $fundraiser, $old_captain );

		return true;
	}
}
