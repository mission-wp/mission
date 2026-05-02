<?php
/**
 * Campaign model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Campaigns\CampaignPostType;
use MissionDP\Database\DataStore\CampaignDataStore;
use MissionDP\Database\DataStore\DataStoreInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Campaign model — the custom table is the source of truth.
 *
 * The linked WP post provides WordPress integration (URLs, Gutenberg editor,
 * campaign images, slugs) but is an internal detail. Consumers should use
 * model properties and convenience methods instead of touching the post.
 *
 * @property-read string $slug         Post slug (for URL generation).
 * @property-read string $page_content Full Gutenberg page body (post_content).
 */
class Campaign extends Model {

	use HasMeta;

	public int $post_id;
	public string $title;
	public string $description;
	public int $goal_amount;
	public string $goal_type;
	public int $total_raised;
	public int $transaction_count;
	public int $donor_count;
	public int $test_total_raised;
	public int $test_transaction_count;
	public int $test_donor_count;
	public string $currency;
	public bool $show_in_listings;
	public string $status;
	public ?string $date_start;
	public ?string $date_end;
	public string $date_created;
	public string $date_modified;

	/**
	 * Cached WP_Post for transparent property access.
	 */
	private ?\WP_Post $post = null;

	/**
	 * Map of virtual property names to WP_Post fields.
	 */
	private const POST_PROPERTIES = [
		'slug'         => 'post_name',
		'page_content' => 'post_content',
	];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id                     = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->post_id                = (int) ( $data['post_id'] ?? 0 );
		$this->title                  = $data['title'] ?? '';
		$this->description            = $data['description'] ?? '';
		$this->goal_amount            = (int) ( $data['goal_amount'] ?? 0 );
		$this->goal_type              = $data['goal_type'] ?? 'amount';
		$this->total_raised           = (int) ( $data['total_raised'] ?? 0 );
		$this->transaction_count      = (int) ( $data['transaction_count'] ?? 0 );
		$this->donor_count            = (int) ( $data['donor_count'] ?? 0 );
		$this->test_total_raised      = (int) ( $data['test_total_raised'] ?? 0 );
		$this->test_transaction_count = (int) ( $data['test_transaction_count'] ?? 0 );
		$this->test_donor_count       = (int) ( $data['test_donor_count'] ?? 0 );
		$this->currency               = $data['currency'] ?? 'usd';
		$this->show_in_listings       = (bool) ( $data['show_in_listings'] ?? true );
		$this->status                 = $data['status'] ?? 'active';
		$this->date_start             = $data['date_start'] ?? null;
		$this->date_end               = $data['date_end'] ?? null;
		$this->date_created           = $data['date_created'] ?? current_time( 'mysql', true );
		$this->date_modified          = $data['date_modified'] ?? current_time( 'mysql', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new CampaignDataStore();
	}

	/**
	 * Find a campaign by its associated WP post ID.
	 *
	 * @param int $post_id The WP post ID.
	 * @return self|null
	 */
	public static function find_by_post_id( int $post_id ): ?self {
		/** @var CampaignDataStore $store */
		$store = static::store();
		return $store->find_by_post_id( $post_id );
	}

	/**
	 * Save this model — creates the WP post on first save.
	 *
	 * @return int|bool New ID on insert, true on update, false on failure.
	 */
	public function save(): int|bool {
		$is_new = ! $this->id && ! $this->post_id;

		// New campaign without a post — create one.
		if ( $is_new ) {
			$post_id = wp_insert_post(
				[
					'post_type'    => CampaignPostType::POST_TYPE,
					'post_title'   => $this->title,
					'post_excerpt' => $this->description,
					'post_status'  => 'publish',
				],
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return false;
			}

			$this->post_id = $post_id;
		}

		$result = parent::save();

		// Set default page content for new campaigns (needs $this->id from the insert).
		if ( $is_new && $result ) {
			wp_update_post(
				[
					'ID'           => $this->post_id,
					'post_content' => $this->build_default_page_content(),
				]
			);
		}

		return $result;
	}

	/**
	 * Build default Gutenberg block content for a new campaign page.
	 *
	 * @return string Block markup.
	 */
	private function build_default_page_content(): string {
		$campaign_id = $this->id;
		$org_name    = get_option( 'missiondp_settings' )['org_name'] ?? get_bloginfo( 'name' );
		$description = $this->description ?? '';

		ob_start();
		include __DIR__ . '/../Campaigns/templates/campaign-page.php';
		$content = ob_get_clean();

		/**
		 * Filters the default page content for a newly created campaign.
		 *
		 * @since 1.0.0
		 *
		 * @param string   $content     The default block markup.
		 * @param Campaign $campaign    The campaign being created.
		 */
		return apply_filters( 'missiondp_campaign_default_page_content', $content, $this );
	}

	/**
	 * Get the current progress toward the campaign goal.
	 *
	 * Returns the appropriate aggregate based on goal_type:
	 * - amount: total_raised
	 * - donations: transaction_count
	 * - donors: donor_count
	 *
	 * @param bool $is_test Whether to use test aggregates.
	 *
	 * @return int
	 */
	public function get_goal_progress( bool $is_test = false ): int {
		return match ( $this->goal_type ) {
			'donations' => $is_test ? $this->test_transaction_count : $this->transaction_count,
			'donors'    => $is_test ? $this->test_donor_count : $this->donor_count,
			default     => $is_test ? $this->test_total_raised : $this->total_raised,
		};
	}

	/**
	 * Get the transactions for this campaign.
	 *
	 * @param array<string, mixed> $args Additional query args.
	 * @return Transaction[]
	 */
	public function transactions( array $args = [] ): array {
		return Transaction::query( array_merge( $args, [ 'campaign_id' => $this->id ] ) );
	}

	/**
	 * Get the edit URL for this campaign's post.
	 *
	 * @return string|null
	 */
	public function get_edit_url(): ?string {
		if ( ! $this->post_id ) {
			return null;
		}

		$url = get_edit_post_link( $this->post_id, 'raw' );

		return $url ?: null;
	}

	/**
	 * Get the frontend view URL for this campaign.
	 *
	 * @return string|null
	 */
	public function get_url(): ?string {
		if ( ! $this->post_id || ! $this->has_campaign_page() ) {
			return null;
		}

		$url = get_permalink( $this->post_id );

		return $url ?: null;
	}

	/**
	 * Get the campaign image attachment ID.
	 *
	 * @return int|null
	 */
	public function get_image_id(): ?int {
		$id = $this->get_meta( 'image_id' );

		return $id ? (int) $id : null;
	}

	/**
	 * Get the campaign image URL.
	 *
	 * @param string $size Image size.
	 * @return string|null
	 */
	public function get_image_url( string $size = 'medium' ): ?string {
		$id = $this->get_image_id();

		if ( ! $id ) {
			return null;
		}

		$url = wp_get_attachment_image_url( $id, $size );

		return $url ?: null;
	}

	/**
	 * Set the campaign image.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function set_image( int $attachment_id ): void {
		$this->update_meta( 'image_id', $attachment_id );
	}

	/**
	 * Remove the campaign image.
	 */
	public function remove_image(): void {
		$this->delete_meta( 'image_id' );
	}

	/**
	 * Check whether this campaign has a public page.
	 */
	public function has_campaign_page(): bool {
		$value = $this->get_meta( 'has_campaign_page' );

		return '' === $value ? true : (bool) $value;
	}

	/**
	 * Enable or disable the campaign's public page.
	 *
	 * Updates the meta flag and sets the backing post to publish or draft.
	 */
	public function set_campaign_page_enabled( bool $enabled ): void {
		$this->update_meta( 'has_campaign_page', $enabled );

		if ( $this->post_id ) {
			wp_update_post(
				[
					'ID'          => $this->post_id,
					'post_status' => $enabled ? 'publish' : 'draft',
				]
			);
		}
	}

	/**
	 * Trash the campaign post and delete the custom table row.
	 *
	 * @return bool
	 */
	public function trash(): bool {
		if ( $this->post_id ) {
			wp_trash_post( $this->post_id );
		}

		return $this->delete();
	}

	/**
	 * Transparent access to WP Post properties (slug, page_content).
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ): mixed {
		if ( isset( self::POST_PROPERTIES[ $name ] ) ) {
			$this->post ??= get_post( $this->post_id );
			return $this->post?->{self::POST_PROPERTIES[ $name ]} ?? '';
		}

		return null;
	}

	/**
	 * Support isset()/empty() for virtual post properties.
	 *
	 * @param string $name Property name.
	 * @return bool
	 */
	public function __isset( string $name ): bool {
		if ( isset( self::POST_PROPERTIES[ $name ] ) ) {
			$this->post ??= get_post( $this->post_id );
			return null !== $this->post;
		}

		return false;
	}
}
