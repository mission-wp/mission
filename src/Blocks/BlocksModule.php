<?php
/**
 * Blocks module - registers custom blocks.
 *
 * @package MissionDP
 */

namespace MissionDP\Blocks;

use MissionDP\Campaigns\CampaignPostType;
use MissionDP\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks module class.
 */
class BlocksModule {

	/**
	 * Initialize the blocks module.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'block_categories_all', [ $this, 'register_block_category' ] );
		add_action( 'init', [ $this, 'register_blocks' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ] );
		add_filter( 'render_block_mission-donation-platform/donation-form', [ $this, 'enqueue_stripe_js' ], 10, 2 );
		add_filter( 'render_block_mission-donation-platform/donor-dashboard', [ $this, 'enqueue_stripe_js' ], 10, 2 );
	}

	/**
	 * Enqueue editor scripts/styles for all Mission blocks.
	 *
	 * WordPress auto-enqueues block.json editorScript only on native
	 * block editor screens. This hook ensures our blocks load in any
	 * context that fires enqueue_block_editor_assets.
	 *
	 * @return void
	 */
	public function enqueue_block_editor_assets(): void {
		$plugin_dir = dirname( dirname( __DIR__ ) );
		$blocks_dir = $plugin_dir . '/blocks/build';

		if ( ! is_dir( $blocks_dir ) ) {
			return;
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		$blocks   = glob( $blocks_dir . '/*', GLOB_ONLYDIR );

		foreach ( $blocks as $block_path ) {
			$name  = 'mission-donation-platform/' . basename( $block_path );
			$block = $registry->get_registered( $name );

			if ( ! $block ) {
				continue;
			}

			foreach ( $block->editor_script_handles as $handle ) {
				wp_enqueue_script( $handle );
			}
			foreach ( $block->editor_style_handles as $handle ) {
				wp_enqueue_style( $handle );
			}
		}

		$this->preload_campaign_image_data();
		$this->localize_fee_settings();
		$this->localize_primary_color();
	}

	/**
	 * Preload campaign image data when editing a campaign post.
	 *
	 * Localizes image URLs so the Campaign Image block can render
	 * instantly without waiting for API calls.
	 *
	 * @return void
	 */
	private function preload_campaign_image_data(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$campaign = null;

		if ( CampaignPostType::POST_TYPE === $screen->post_type ) {
			// Standard campaign post edit screen.
			$post_id = get_the_ID();
			if ( $post_id ) {
				$campaign = Campaign::find_by_post_id( $post_id );
			}
		} elseif ( 'mission_page_mission-donation-platform-campaigns' === $screen->id ) {
			// Campaign details admin page (?page=mission-donation-platform-campaigns&campaign=ID).
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for page context.
			$campaign_id = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0;
			if ( $campaign_id ) {
				$campaign = Campaign::find( $campaign_id );
			}
		}

		if ( ! $campaign ) {
			return;
		}

		$block  = \WP_Block_Type_Registry::get_instance()->get_registered( 'mission-donation-platform/campaign-image' );
		$handle = $block?->editor_script_handles[0] ?? null;

		if ( ! $handle ) {
			return;
		}

		$urls     = [];
		$image_id = $campaign->get_image_id();

		if ( $image_id ) {
			$metadata = wp_get_attachment_metadata( $image_id );
			$sizes    = isset( $metadata['sizes'] ) ? array_keys( $metadata['sizes'] ) : [];

			foreach ( $sizes as $size ) {
				$url = wp_get_attachment_image_url( $image_id, $size );
				if ( $url ) {
					$urls[ $size ] = $url;
				}
			}

			$full_url = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $full_url ) {
				$urls['full'] = $full_url;
			}
		}

		wp_localize_script(
			$handle,
			'missiondpCampaignImage',
			[
				'campaignId' => $campaign->id,
				'imageUrls'  => $urls,
			]
		);
	}

	/**
	 * Localize fee settings so the block editor can display the configured rate.
	 *
	 * @return void
	 */
	private function localize_fee_settings(): void {
		$block  = \WP_Block_Type_Registry::get_instance()->get_registered( 'mission-donation-platform/donation-form' );
		$handle = $block?->editor_script_handles[0] ?? null;

		if ( ! $handle ) {
			return;
		}

		$settings = get_option( 'missiondp_settings', [] );

		wp_localize_script(
			$handle,
			'missiondpFeeSettings',
			[
				'stripeFeePercent' => (float) ( $settings['stripe_fee_percent'] ?? 2.9 ),
				'stripeFeeFixed'   => (int) ( $settings['stripe_fee_fixed'] ?? 30 ),
			]
		);
	}

	/**
	 * Localize primary color so block editor previews match the frontend.
	 *
	 * Uses the first available Mission block editor script handle.
	 *
	 * @return void
	 */
	private function localize_primary_color(): void {
		$settings = get_option( 'missiondp_settings', [] );
		$color    = $settings['primary_color'] ?? '#2fa36b';

		// Attach to wp-blocks which every block editor script depends on.
		wp_add_inline_script(
			'wp-blocks',
			'window.missiondpBlockEditor = ' . wp_json_encode( [ 'primaryColor' => $color ] ) . ';'
		);
	}

	/**
	 * Register a custom block category for Mission blocks.
	 *
	 * @param array $categories Existing block categories.
	 * @return array
	 */
	public function register_block_category( array $categories ): array {
		array_unshift(
			$categories,
			[
				'slug'  => 'mission-donation-platform',
				'title' => __( 'Mission', 'mission-donation-platform' ),
			]
		);

		return $categories;
	}

	/**
	 * Enqueue Stripe.js when a donation form block is rendered.
	 *
	 * @param string $block_content Rendered block content.
	 * @return string Unmodified block content.
	 */
	public function enqueue_stripe_js( string $block_content ): string {
		wp_enqueue_script_module(
			'@mission-donation-platform/stripe-js',
			'https://js.stripe.com/v3/',
			[],
			null
		);

		return $block_content;
	}

	/**
	 * Register blocks.
	 *
	 * Loops through all the blocks in the build directory and registers them.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		$plugin_dir = dirname( dirname( __DIR__ ) );
		$blocks_dir = $plugin_dir . '/blocks/build';

		if ( ! is_dir( $blocks_dir ) ) {
			return;
		}

		$blocks = glob( $blocks_dir . '/*', GLOB_ONLYDIR );

		foreach ( $blocks as $block ) {
			register_block_type_from_metadata( $block );
		}
	}
}
