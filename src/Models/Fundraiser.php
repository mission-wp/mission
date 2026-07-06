<?php
/**
 * Fundraiser model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\FundraiserDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Fundraiser model — one person's participation in one peer-to-peer campaign.
 *
 * Links to a Donor (participant identity) and, optionally, a Team. The custom
 * table is the source of truth; aggregate columns mirror the campaign pattern
 * and are rebuilt from attributed transactions via recompute_aggregates().
 */
class Fundraiser extends Model {

	use HasMeta;
	use HasShellPost;

	/**
	 * Post type slug for fundraiser shell posts.
	 *
	 * Registered by FundraiserPostType; defined here so the model owns the
	 * single source of truth the post type and trait both reference.
	 */
	public const POST_TYPE = 'missiondp_fundraiser';

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_PENDING  = 'pending';
	public const STATUS_INACTIVE = 'inactive';

	/**
	 * Every fundraiser status.
	 *
	 * @var string[]
	 */
	public const STATUSES = [
		self::STATUS_ACTIVE,
		self::STATUS_PENDING,
		self::STATUS_INACTIVE,
	];

	public int $campaign_id;
	public int $donor_id;
	public ?int $team_id;
	public int $post_id;
	public bool $is_team_captain;
	public string $status;
	public int $goal;
	public string $headline;
	public string $story;
	public string $cover_image;
	public string $profile_image;
	public int $total_raised;
	public int $transaction_count;
	public int $donor_count;
	public int $test_total_raised;
	public int $test_transaction_count;
	public int $test_donor_count;
	public string $date_created;
	public string $date_modified;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id                     = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->campaign_id            = (int) ( $data['campaign_id'] ?? 0 );
		$this->donor_id               = (int) ( $data['donor_id'] ?? 0 );
		$this->team_id                = isset( $data['team_id'] ) ? (int) $data['team_id'] : null;
		$this->post_id                = (int) ( $data['post_id'] ?? 0 );
		$this->is_team_captain        = (bool) ( $data['is_team_captain'] ?? false );
		$this->status                 = $data['status'] ?? self::STATUS_PENDING;
		$this->goal                   = (int) ( $data['goal'] ?? 0 );
		$this->headline               = $data['headline'] ?? '';
		$this->story                  = $data['story'] ?? '';
		$this->cover_image            = $data['cover_image'] ?? '';
		$this->profile_image          = $data['profile_image'] ?? '';
		$this->total_raised           = (int) ( $data['total_raised'] ?? 0 );
		$this->transaction_count      = (int) ( $data['transaction_count'] ?? 0 );
		$this->donor_count            = (int) ( $data['donor_count'] ?? 0 );
		$this->test_total_raised      = (int) ( $data['test_total_raised'] ?? 0 );
		$this->test_transaction_count = (int) ( $data['test_transaction_count'] ?? 0 );
		$this->test_donor_count       = (int) ( $data['test_donor_count'] ?? 0 );
		$this->date_created           = $data['date_created'] ?? current_time( 'mysql', true );
		$this->date_modified          = $data['date_modified'] ?? current_time( 'mysql', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new FundraiserDataStore();
	}

	/**
	 * Find a fundraiser by its linked shell post ID.
	 *
	 * @param int $post_id The WP post ID.
	 * @return self|null
	 */
	public static function find_by_post_id( int $post_id ): ?self {
		/** @var FundraiserDataStore $store */
		$store = static::store();
		return $store->find_by_post_id( $post_id );
	}

	/**
	 * Warm the row memo for a set of shell post IDs in one query.
	 *
	 * Call before a loop of find_by_post_id() lookups (e.g. leaderboard
	 * permalinks) so each lookup is served from the memo.
	 *
	 * @param int[] $post_ids WP post IDs.
	 */
	public static function warm_by_post_ids( array $post_ids ): void {
		/** @var FundraiserDataStore $store */
		$store = static::store();
		$store->warm_rows_by_post_ids( $post_ids );
	}

	/**
	 * Create a new fundraiser (and its shell post) during registration.
	 *
	 * Always created without a team; attaching to a team (including as the
	 * captain of a just-created team) goes through join_team() so the
	 * mission_team_joined event fires uniformly for every join path.
	 *
	 * @param int    $campaign_id Parent campaign ID.
	 * @param int    $donor_id    Participant donor ID.
	 * @param int    $goal        Personal goal in minor units.
	 * @param string $story       Personal fundraising story.
	 * @param string $headline    Short tagline.
	 * @param string $status      Initial status (active/pending).
	 * @return self The saved fundraiser.
	 */
	public static function register( int $campaign_id, int $donor_id, int $goal, string $story = '', string $headline = '', string $status = self::STATUS_ACTIVE ): self {
		$fundraiser = new self(
			[
				'campaign_id' => $campaign_id,
				'donor_id'    => $donor_id,
				'goal'        => $goal,
				'story'       => $story,
				'headline'    => $headline,
				'status'      => $status,
			]
		);

		$fundraiser->save();

		return $fundraiser;
	}

	/**
	 * Attach this fundraiser to a team and fire the joined event.
	 *
	 * @param Team $team       The team to join.
	 * @param bool $as_captain Whether this fundraiser leads the team.
	 * @return bool True on success.
	 */
	public function join_team( Team $team, bool $as_captain = false ): bool {
		$this->team_id         = $team->id;
		$this->is_team_captain = $as_captain;

		if ( ! $this->save() ) {
			return false;
		}

		/**
		 * Fires after a fundraiser joins a team.
		 *
		 * @param Fundraiser $fundraiser The fundraiser that joined.
		 * @param Team       $team       The team they joined.
		 */
		do_action( 'mission_team_joined', $this, $team );

		return true;
	}

	/**
	 * Detach this fundraiser from its team and fire the left event.
	 *
	 * Clears both the team association and the captain flag. Safe to call on a
	 * fundraiser with no team (a no-op that still reports success).
	 *
	 * @return bool True on success.
	 */
	public function leave_team(): bool {
		$team = $this->team();

		$this->team_id         = null;
		$this->is_team_captain = false;

		if ( ! $this->save() ) {
			return false;
		}

		if ( $team ) {
			/**
			 * Fires after a fundraiser leaves a team.
			 *
			 * @param Fundraiser $fundraiser The fundraiser that left.
			 * @param Team       $team       The team they left.
			 */
			do_action( 'mission_team_left', $this, $team );
		}

		return true;
	}

	/**
	 * Move this fundraiser to a different team, or off teams entirely.
	 *
	 * Routes the change through leave_team()/join_team() so the left/joined
	 * events fire, and vacates the captaincy on the team being left so its
	 * captain_id never points at a non-member. No-op when the team is
	 * unchanged.
	 *
	 * @param Team|null $team Destination team, or null to leave teams.
	 * @return bool True on success.
	 */
	public function move_to_team( ?Team $team ): bool {
		if ( ( $this->team_id ?: null ) === ( $team?->id ?: null ) ) {
			return true;
		}

		$old_team = $this->team();

		if ( $old_team && (int) $old_team->captain_id === (int) $this->id && ! $old_team->clear_captain() ) {
			return false;
		}

		if ( ! $team ) {
			return $this->leave_team();
		}

		if ( $this->team_id && ! $this->leave_team() ) {
			return false;
		}

		return $this->join_team( $team );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function shell_post_type(): string {
		return self::POST_TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Titles the shell post with the participant's name so the page URL and SEO
	 * title read as the person, not the record.
	 */
	protected function shell_post_title(): string {
		$donor = $this->donor();
		$name  = $donor ? trim( $donor->first_name . ' ' . $donor->last_name ) : '';

		return '' !== $name ? $name : __( 'Fundraiser', 'mission-donation-platform' );
	}

	/**
	 * Recompute a fundraiser's aggregate totals from the transactions table.
	 *
	 * @param int  $fundraiser_id Fundraiser ID.
	 * @param bool $is_test       Mode of the change that prompted the recompute.
	 */
	public static function recompute_aggregates( int $fundraiser_id, bool $is_test = false ): void {
		/** @var FundraiserDataStore $store */
		$store = static::store();
		$store->recompute_aggregates( $fundraiser_id, $is_test );
	}

	/**
	 * Get the campaign this fundraiser belongs to.
	 *
	 * @return Campaign|null
	 */
	public function campaign(): ?Campaign {
		return $this->campaign_id ? Campaign::find( $this->campaign_id ) : null;
	}

	/**
	 * Get the donor (participant identity) for this fundraiser.
	 *
	 * @return Donor|null
	 */
	public function donor(): ?Donor {
		return $this->donor_id ? Donor::find( $this->donor_id ) : null;
	}

	/**
	 * Get the team this fundraiser belongs to, if any.
	 *
	 * @return Team|null
	 */
	public function team(): ?Team {
		return $this->team_id ? Team::find( $this->team_id ) : null;
	}

	/**
	 * Get the transactions attributed to this fundraiser.
	 *
	 * @param array<string, mixed> $args Additional query args.
	 * @return Transaction[]
	 */
	public function transactions( array $args = [] ): array {
		return Transaction::query( array_merge( $args, [ 'fundraiser_id' => $this->id ] ) );
	}

	/**
	 * Get the amount this fundraiser has raised.
	 *
	 * @param bool $is_test Whether to use the test-mode mirror.
	 * @return int Total raised in minor units.
	 */
	public function amount_raised( bool $is_test = false ): int {
		return $is_test ? $this->test_total_raised : $this->total_raised;
	}

	/**
	 * Get progress toward the personal goal as a percentage (0-100).
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
	 * Whether this fundraiser is the captain of their team.
	 *
	 * @return bool
	 */
	public function is_captain(): bool {
		return $this->is_team_captain;
	}

	/**
	 * Whether this fundraiser's page is locked because its campaign ended.
	 *
	 * A missing campaign locks the page too — there is nothing left to raise for.
	 *
	 * @return bool
	 */
	public function is_locked(): bool {
		$campaign = $this->campaign();

		return ! $campaign || $campaign->has_ended();
	}

	/**
	 * Get the page dedication, if one was set.
	 *
	 * @return array{type: string, name: string}|null Type is 'honor' or 'memory'.
	 */
	public function dedication(): ?array {
		$name = (string) $this->get_meta( 'tribute_name' );

		if ( '' === $name ) {
			return null;
		}

		return [
			'type' => 'memory' === $this->get_meta( 'tribute_type' ) ? 'memory' : 'honor',
			'name' => $name,
		];
	}

	/**
	 * Human-readable dedication label for the page.
	 *
	 * @return string "In memory of …" / "In honor of …", or an empty string when none.
	 */
	public function dedication_label(): string {
		$dedication = $this->dedication();

		if ( ! $dedication ) {
			return '';
		}

		return 'memory' === $dedication['type']
			/* translators: %s: person being honored */
			? sprintf( __( 'In memory of %s', 'mission-donation-platform' ), $dedication['name'] )
			/* translators: %s: person being honored */
			: sprintf( __( 'In honor of %s', 'mission-donation-platform' ), $dedication['name'] );
	}

	/**
	 * Set or clear the page dedication.
	 *
	 * Mirrors the registration normalization: any type other than 'memory'
	 * stores 'honor'. An empty type or name clears the dedication.
	 *
	 * @param string|null $type Dedication type ('honor' or 'memory'), or null/'' to clear.
	 * @param string      $name The honoree's name.
	 */
	public function set_dedication( ?string $type, string $name ): void {
		$name = trim( $name );

		if ( null === $type || '' === $type || '' === $name ) {
			$this->delete_meta( 'tribute_type' );
			$this->delete_meta( 'tribute_name' );
			return;
		}

		$this->update_meta( 'tribute_type', 'memory' === $type ? 'memory' : 'honor' );
		$this->update_meta( 'tribute_name', $name );
	}

	/**
	 * Approve this fundraiser, moving them to the active status.
	 *
	 * Idempotent: a fundraiser that is already active is left untouched and no
	 * event fires. Approval is the admin sign-off that makes a pending page live;
	 * re-activating a deactivated fundraiser is not an approval, so it fires a
	 * separate event and never re-sends the "your page is live" email.
	 *
	 * @return bool True on success (or when already active).
	 */
	public function approve(): bool {
		if ( self::STATUS_ACTIVE === $this->status ) {
			return true;
		}

		$was_pending  = self::STATUS_PENDING === $this->status;
		$this->status = self::STATUS_ACTIVE;

		if ( ! $this->save() ) {
			return false;
		}

		if ( $was_pending ) {
			/**
			 * Fires after a pending fundraiser is approved.
			 *
			 * @param Fundraiser $fundraiser The approved fundraiser.
			 */
			do_action( 'mission_fundraiser_approved', $this );
		} else {
			/**
			 * Fires after a deactivated fundraiser is made active again.
			 *
			 * @param Fundraiser $fundraiser The reactivated fundraiser.
			 */
			do_action( 'mission_fundraiser_reactivated', $this );
		}

		return true;
	}

	/**
	 * Deactivate this fundraiser, hiding their page without deleting the record.
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
		 * Fires after a fundraiser is deactivated.
		 *
		 * @param Fundraiser $fundraiser The deactivated fundraiser.
		 */
		do_action( 'mission_fundraiser_deactivated', $this );

		return true;
	}
}
