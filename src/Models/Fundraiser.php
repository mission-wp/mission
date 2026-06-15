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
	 * Save without firing the created hook.
	 *
	 * For bulk backfill/seed paths that recompute aggregates once at the end.
	 *
	 * @return int|bool New ID on insert, true on update, false on failure.
	 */
	public function save_silent(): int|bool {
		/** @var FundraiserDataStore $store */
		$store = static::store();

		if ( $this->id ) {
			return $store->update( $this );
		}

		$this->id = $store->create_silent( $this );

		return $this->id;
	}

	/**
	 * Recompute a fundraiser's aggregate totals from the transactions table.
	 *
	 * @param int $fundraiser_id Fundraiser ID.
	 */
	public static function recompute_aggregates( int $fundraiser_id ): void {
		/** @var FundraiserDataStore $store */
		$store = static::store();
		$store->recompute_aggregates( $fundraiser_id );
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
	 * Approve this fundraiser, moving them to the active status.
	 *
	 * Idempotent: a fundraiser that is already active is left untouched and no
	 * event fires. Approval is the admin sign-off that makes a pending page live.
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
		 * Fires after a fundraiser is approved.
		 *
		 * @param Fundraiser $fundraiser The approved fundraiser.
		 */
		do_action( 'mission_fundraiser_approved', $this );

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
