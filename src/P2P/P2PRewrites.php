<?php
/**
 * Nested permalinks for fundraiser and team shell posts.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

use MissionDP\Campaigns\CampaignSlug;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Gives fundraiser/team pages URLs nested under their parent campaign:
 *
 *   /{campaign-base}/{campaign-slug}/fundraiser/{slug}/
 *   /{campaign-base}/{campaign-slug}/team/{slug}/
 *
 * The path segments are filterable. Resolution is by the globally-unique CPT
 * slug, so the campaign-slug portion is for readability/SEO only.
 */
class P2PRewrites {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_filter( 'post_type_link', [ $this, 'filter_post_type_link' ], 10, 2 );
	}

	/**
	 * The URL path segment for fundraiser pages.
	 *
	 * @return string
	 */
	public static function fundraiser_segment(): string {
		/**
		 * Filters the fundraiser page URL segment (default "fundraiser").
		 *
		 * @param string $segment The path segment.
		 */
		return (string) apply_filters( 'mission_fundraiser_url_segment', 'fundraiser' );
	}

	/**
	 * The URL path segment for team pages.
	 *
	 * @return string
	 */
	public static function team_segment(): string {
		/**
		 * Filters the team page URL segment (default "team").
		 *
		 * @param string $segment The path segment.
		 */
		return (string) apply_filters( 'mission_team_url_segment', 'team' );
	}

	/**
	 * Register the nested rewrite rules.
	 */
	public function add_rewrite_rules(): void {
		$base = $this->campaign_base();

		add_rewrite_rule(
			'^' . $base . '/([^/]+)/' . self::fundraiser_segment() . '/([^/]+)/?$',
			'index.php?' . Fundraiser::POST_TYPE . '=$matches[2]',
			'top'
		);

		add_rewrite_rule(
			'^' . $base . '/([^/]+)/' . self::team_segment() . '/([^/]+)/?$',
			'index.php?' . Team::POST_TYPE . '=$matches[2]',
			'top'
		);
	}

	/**
	 * Rewrite fundraiser/team permalinks to sit under the parent campaign.
	 *
	 * @param string   $permalink The default permalink.
	 * @param \WP_Post $post      The post being linked.
	 * @return string
	 */
	public function filter_post_type_link( string $permalink, \WP_Post $post ): string {
		if ( Fundraiser::POST_TYPE === $post->post_type ) {
			$fundraiser = Fundraiser::find_by_post_id( $post->ID );
			return $this->build_nested_url( $fundraiser?->campaign_id ?? 0, self::fundraiser_segment(), $post->post_name )
				?? $permalink;
		}

		if ( Team::POST_TYPE === $post->post_type ) {
			$team = Team::find_by_post_id( $post->ID );
			return $this->build_nested_url( $team?->campaign_id ?? 0, self::team_segment(), $post->post_name )
				?? $permalink;
		}

		return $permalink;
	}

	/**
	 * Build a nested URL under a campaign's permalink.
	 *
	 * @param int    $campaign_id Parent campaign ID.
	 * @param string $segment     Path segment (fundraiser/team).
	 * @param string $slug        The shell post slug.
	 * @return string|null Null when the campaign or its permalink can't be resolved.
	 */
	private function build_nested_url( int $campaign_id, string $segment, string $slug ): ?string {
		if ( ! $campaign_id || '' === $slug ) {
			return null;
		}

		$campaign = \MissionDP\Models\Campaign::find( $campaign_id );

		if ( ! $campaign || ! $campaign->post_id ) {
			return null;
		}

		$campaign_url = get_permalink( $campaign->post_id );

		if ( ! $campaign_url ) {
			return null;
		}

		return trailingslashit( $campaign_url ) . $segment . '/' . $slug . '/';
	}

	/**
	 * Get the campaign URL base segment from settings.
	 *
	 * @return string
	 */
	private function campaign_base(): string {
		return CampaignSlug::sanitize(
			(string) ( new SettingsService() )->get( 'campaign_url_slug', CampaignSlug::DEFAULT_SLUG )
		) ?: CampaignSlug::DEFAULT_SLUG;
	}
}
