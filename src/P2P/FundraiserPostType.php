<?php
/**
 * Fundraiser shell post type registration.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

use MissionDP\Models\Fundraiser;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the hidden missiondp_fundraiser post type.
 *
 * Each fundraiser row links to one of these posts purely so WordPress has a
 * real queried object for the fundraising page (URL, slug, SEO, sitemaps). The
 * custom table stays the source of truth; the post carries no content. Nested
 * pretty URLs are layered on in P2PRewrites.
 */
class FundraiserPostType {

	public const POST_TYPE = Fundraiser::POST_TYPE;

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'init', [ $this, 'register_block_templates' ] );
	}

	/**
	 * Register the single-fundraiser block template for the Site Editor.
	 *
	 * Without this the fundraiser page renders only through the the_content
	 * filter (P2PPageRenderer) and never appears in the Site Editor. Registering
	 * it makes "Fundraiser Page" an editable template on block themes, mirroring
	 * the campaign single template. The default content is the shared fundraiser
	 * page markup wrapped in the theme's header, main, and footer.
	 */
	public function register_block_templates(): void {
		ob_start();
		include __DIR__ . '/templates/fundraiser-page.php';
		$inner = (string) ob_get_clean();

		\register_block_template(
			'mission-donation-platform//single-' . self::POST_TYPE,
			[
				'title'       => __( 'Fundraiser Page', 'mission-donation-platform' ),
				'description' => __( 'Displays a single fundraiser page.', 'mission-donation-platform' ),
				'content'     => '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group">

' . $inner . '
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->',
			]
		);
	}

	/**
	 * Register the post type.
	 */
	public function register(): void {
		$labels = [
			'name'          => __( 'Fundraisers', 'mission-donation-platform' ),
			'singular_name' => __( 'Fundraiser', 'mission-donation-platform' ),
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
