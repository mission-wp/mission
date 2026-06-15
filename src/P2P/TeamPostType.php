<?php
/**
 * Team shell post type registration.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

use MissionDP\Models\Team;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the hidden missiondp_team post type.
 *
 * Mirrors FundraiserPostType: each team row links to one of these posts so the
 * team page has a real queried object for URL/slug/SEO. The custom table stays
 * the source of truth. Nested pretty URLs are layered on in P2PRewrites.
 */
class TeamPostType {

	public const POST_TYPE = Team::POST_TYPE;

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'register' ] );
	}

	/**
	 * Register the post type.
	 */
	public function register(): void {
		$labels = [
			'name'          => __( 'Teams', 'mission-donation-platform' ),
			'singular_name' => __( 'Team', 'mission-donation-platform' ),
		];

		register_post_type(
			self::POST_TYPE,
			[
				'labels'             => $labels,
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'has_archive'        => false,
				'hierarchical'       => false,
				'query_var'          => true,
				// Custom nested rewrite rules are registered in P2PRewrites.
				'rewrite'            => false,
				'supports'           => [ 'title' ],
			]
		);
	}
}
