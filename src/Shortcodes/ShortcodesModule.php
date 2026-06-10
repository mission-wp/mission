<?php
/**
 * Shortcodes module — shortcode equivalents of the Mission blocks.
 *
 * @package MissionDP
 */

namespace MissionDP\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a shortcode for each Mission block so the blocks can be used in
 * page builders (Bricks, Elementor, ...) and the classic editor.
 */
class ShortcodesModule {

	/**
	 * Shortcode tag => block name map.
	 *
	 * @var array<string, string>
	 */
	private const SHORTCODES = [
		'mission_donation_form'       => 'mission-donation-platform/donation-form',
		'mission_donate_button'       => 'mission-donation-platform/donate-button',
		'mission_campaign'            => 'mission-donation-platform/campaign',
		'mission_campaign_grid'       => 'mission-donation-platform/campaign-grid',
		'mission_campaign_image'      => 'mission-donation-platform/campaign-image',
		'mission_campaign_progress'   => 'mission-donation-platform/campaign-progress',
		'mission_campaign_statistics' => 'mission-donation-platform/campaign-statistics',
		'mission_donor_wall'          => 'mission-donation-platform/donor-wall',
		'mission_recent_donors'       => 'mission-donation-platform/recent-donors',
		'mission_top_donors'          => 'mission-donation-platform/top-donors',
		'mission_donor_dashboard'     => 'mission-donation-platform/donor-dashboard',
	];

	/**
	 * Initialize the module.
	 *
	 * @return void
	 */
	public function init(): void {
		// Priority 20 so blocks are registered first (BlocksModule runs at 10).
		add_action( 'init', [ $this, 'register_shortcodes' ], 20 );
	}

	/**
	 * Register all block shortcodes.
	 *
	 * @return void
	 */
	public function register_shortcodes(): void {
		/**
		 * Filters the shortcode tag => block name map before registration.
		 *
		 * @param array<string, string> $shortcodes Shortcode tag => full block name.
		 */
		$shortcodes = apply_filters( 'missiondp_shortcodes', self::SHORTCODES );

		foreach ( $shortcodes as $tag => $block_name ) {
			add_shortcode( $tag, fn( $atts ): string => $this->render( (string) $block_name, $atts ) );
		}
	}

	/**
	 * Render a block shortcode.
	 *
	 * @param string       $block_name Full block name.
	 * @param array|string $atts       Shortcode attributes (WordPress passes '' when none are set).
	 *
	 * @return string Rendered block HTML.
	 */
	public function render( string $block_name, array|string $atts ): string {
		$atts = is_array( $atts ) ? $atts : [];

		$overrides = 'mission-donation-platform/donation-form' === $block_name
			? DonationFormAliases::expand( $atts )
			: [];

		return ShortcodeRenderer::render( $block_name, $atts, $overrides );
	}
}
