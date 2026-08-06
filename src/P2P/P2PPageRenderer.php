<?php
/**
 * Renders fundraiser and team shell pages from per-type block templates.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;

defined( 'ABSPATH' ) || exit;

/**
 * The shell posts carry no content; their page is rendered from a shared,
 * filterable block template whose blocks resolve the current fundraiser/team
 * from the queried post. This keeps one rendering path and lets site owners
 * re-theme via blocks without touching each record.
 */
class P2PPageRenderer {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'the_content', [ $this, 'render_page_content' ] );
		add_filter( 'document_title_parts', [ $this, 'filter_document_title' ] );
	}

	/**
	 * Replace the empty shell-post content with the rendered page template.
	 *
	 * @param string $content The post content.
	 * @return string
	 */
	public function render_page_content( string $content ): string {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post = get_post();

		if ( ! $post ) {
			return $content;
		}

		if ( Fundraiser::POST_TYPE === $post->post_type ) {
			return do_blocks( $this->fundraiser_template() );
		}

		if ( Team::POST_TYPE === $post->post_type ) {
			return do_blocks( $this->team_template() );
		}

		return $content;
	}

	/**
	 * Prefix the document title with the parent campaign name for shell pages.
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public function filter_document_title( array $parts ): array {
		$campaign = null;

		if ( is_singular( Fundraiser::POST_TYPE ) ) {
			$campaign = Fundraiser::find_by_post_id( get_queried_object_id() )?->campaign();
		} elseif ( is_singular( Team::POST_TYPE ) ) {
			$campaign = Team::find_by_post_id( get_queried_object_id() )?->campaign();
		}

		if ( $campaign ) {
			$parts['site'] = $campaign->title;
		}

		return $parts;
	}

	/**
	 * Get the fundraiser page block template markup.
	 *
	 * @return string
	 */
	private function fundraiser_template(): string {
		ob_start();
		include __DIR__ . '/templates/fundraiser-page.php';
		$markup = (string) ob_get_clean();

		/**
		 * Filters the fundraiser page block template markup.
		 *
		 * @param string $markup The block markup rendered for every fundraiser page.
		 */
		return apply_filters( 'mission_fundraiser_page_template', $markup );
	}

	/**
	 * Get the team page block template markup.
	 *
	 * @return string
	 */
	private function team_template(): string {
		ob_start();
		include __DIR__ . '/templates/team-page.php';
		$markup = (string) ob_get_clean();

		/**
		 * Filters the team page block template markup.
		 *
		 * @param string $markup The block markup rendered for every team page.
		 */
		return apply_filters( 'mission_team_page_template', $markup );
	}
}
