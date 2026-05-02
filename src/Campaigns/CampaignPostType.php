<?php
/**
 * Campaign custom post type registration.
 *
 * @package MissionDP
 */

namespace MissionDP\Campaigns;

use MissionDP\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the missiondp_campaign post type.
 */
class CampaignPostType {

	public const POST_TYPE = 'missiondp_campaign';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'init', [ $this, 'register_block_templates' ] );
		add_action( 'template_redirect', [ $this, 'block_disabled_campaign_pages' ] );
		add_filter( 'map_meta_cap', [ $this, 'restrict_editor_delete' ], 10, 4 );
		add_action( 'enqueue_block_editor_assets', [ $this, 'restrict_editor_ui' ] );
		add_filter( 'rest_pre_insert_' . self::POST_TYPE, [ $this, 'lock_slug_and_status' ] );
	}

	/**
	 * Return a 404 for campaign pages that have been disabled,
	 * even for logged-in admins.
	 */
	public function block_disabled_campaign_pages(): void {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return;
		}

		$campaign = Campaign::find_by_post_id( get_queried_object_id() );

		if ( $campaign && ! $campaign->has_campaign_page() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Register the post type.
	 */
	public function register(): void {
		$this->register_post_type();
	}

	/**
	 * Register block templates for the Site Editor.
	 */
	public function register_block_templates(): void {
		\register_block_template(
			'mission-donation-platform//archive-' . self::POST_TYPE,
			[
				'title'       => __( 'Campaigns Archive', 'mission-donation-platform' ),
				'description' => __( 'Displays all campaigns.', 'mission-donation-platform' ),
				'content'     => '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group">

<!-- wp:heading {"level":1,"style":{"spacing":{"margin":{"bottom":"var:preset|spacing|40"}}}} -->
<h1 class="wp-block-heading" style="margin-bottom:var(--wp--preset--spacing--40)">' . esc_html__( 'Campaigns', 'mission-donation-platform' ) . '</h1>
<!-- /wp:heading -->

<!-- wp:mission-donation-platform/campaign-grid {"statusFilter":"all","columns":2,"numberOfCampaigns":12,"align":"wide"} /-->

</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->',
			]
		);

		\register_block_template(
			'mission-donation-platform//single-' . self::POST_TYPE,
			[
				'title'       => __( 'Campaign Page', 'mission-donation-platform' ),
				'description' => __( 'Displays a single campaign page.', 'mission-donation-platform' ),
				'content'     => '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group">

<!-- wp:post-title {"level":1,"style":{"spacing":{"margin":{"bottom":"var:preset|spacing|40"}}}} /-->

<!-- wp:post-content {"layout":{"type":"constrained"}} /-->

</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->',
			]
		);
	}

	/**
	 * Prevent campaign posts from being deleted through the post editor.
	 *
	 * Campaign deletion is managed through the plugin's own REST API,
	 * which calls wp_trash_post() directly (bypasses capability checks).
	 *
	 * @param string[] $caps    Primitive caps required.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param mixed[]  $args    Additional arguments (post ID at index 0).
	 *
	 * @return string[]
	 */
	public function restrict_editor_delete( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'delete_post' === $cap && ! empty( $args[0] ) ) {
			$post = get_post( $args[0] );

			if ( $post && self::POST_TYPE === $post->post_type ) {
				return [ 'do_not_allow' ];
			}
		}

		return $caps;
	}

	/**
	 * Remove slug, status, and trash controls from the campaign post editor.
	 *
	 * These settings are managed through the plugin's campaign admin instead.
	 */
	public function restrict_editor_ui(): void {
		$screen = get_current_screen();

		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_add_inline_script(
			'wp-edit-post',
			"wp.domReady( function() {
				wp.data.dispatch( 'core/editor' ).removeEditorPanel( 'post-link' );
				wp.data.dispatch( 'core/editor' ).removeEditorPanel( 'post-status' );
			} );"
		);
	}

	/**
	 * Prevent slug and status changes when saving through the REST API.
	 *
	 * Slug and status are managed via the plugin's campaign settings,
	 * not the post editor. This is a backend guard in addition to the
	 * UI restrictions applied by restrict_editor_ui().
	 *
	 * @param \stdClass $prepared_post Post data prepared for insertion.
	 *
	 * @return \stdClass
	 */
	public function lock_slug_and_status( \stdClass $prepared_post ): \stdClass {
		$post_id = $prepared_post->ID ?? 0;

		if ( ! $post_id ) {
			return $prepared_post;
		}

		$existing = get_post( $post_id );

		if ( $existing ) {
			$prepared_post->post_name   = $existing->post_name;
			$prepared_post->post_status = $existing->post_status;
		}

		return $prepared_post;
	}

	/**
	 * Register the missiondp_campaign post type.
	 */
	private function register_post_type(): void {
		$labels = [
			'name'               => __( 'Campaigns', 'mission-donation-platform' ),
			'singular_name'      => __( 'Campaign', 'mission-donation-platform' ),
			'add_new'            => __( 'Add New', 'mission-donation-platform' ),
			'add_new_item'       => __( 'Add New Campaign', 'mission-donation-platform' ),
			'edit_item'          => __( 'Edit Campaign', 'mission-donation-platform' ),
			'new_item'           => __( 'New Campaign', 'mission-donation-platform' ),
			'view_item'          => __( 'View Campaign', 'mission-donation-platform' ),
			'search_items'       => __( 'Search Campaigns', 'mission-donation-platform' ),
			'not_found'          => __( 'No campaigns found.', 'mission-donation-platform' ),
			'not_found_in_trash' => __( 'No campaigns found in Trash.', 'mission-donation-platform' ),
			'all_items'          => __( 'Campaigns', 'mission-donation-platform' ),
		];

		$args = [
			'labels'       => $labels,
			'public'       => true,
			'has_archive'  => true,
			'show_in_rest' => true,
			'show_in_menu' => false,
			'rewrite'      => [ 'slug' => 'campaigns' ],
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ],
		];

		register_post_type( self::POST_TYPE, $args );
	}
}
