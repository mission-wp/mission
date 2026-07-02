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
		// Before core's redirect_canonical() (priority 10) so one hop lands on the permalink.
		add_action( 'template_redirect', [ $this, 'redirect_to_canonical' ], 5 );
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
	 * Redirect non-canonical shell-page URLs to the permalink.
	 */
	public function redirect_to_canonical(): void {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$redirect = $this->get_canonical_redirect_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );

		if ( $redirect ) {
			wp_safe_redirect( $redirect, 301 );
			exit;
		}
	}

	/**
	 * Compute the canonical redirect for the current shell-page request, if any.
	 *
	 * The nested rules resolve by the globally-unique CPT slug, so a request
	 * with the wrong campaign segment still resolves, and core's
	 * redirect_canonical() never corrects the path.
	 *
	 * @param string $requested_url The requested URL (or path with query).
	 * @return string|null The canonical URL to redirect to, or null when none.
	 */
	public function get_canonical_redirect_url( string $requested_url ): ?string {
		if ( ! is_singular( [ Fundraiser::POST_TYPE, Team::POST_TYPE ] ) ) {
			return null;
		}

		$canonical = get_permalink( get_queried_object_id() );

		if ( ! $canonical ) {
			return null;
		}

		$requested_path = (string) wp_parse_url( $requested_url, PHP_URL_PATH );
		$canonical_path = (string) wp_parse_url( $canonical, PHP_URL_PATH );

		if ( untrailingslashit( $requested_path ) === untrailingslashit( $canonical_path ) ) {
			return null;
		}

		// Preserve unrelated query args, dropping the ones that resolved the post.
		$args = [];
		parse_str( (string) wp_parse_url( $requested_url, PHP_URL_QUERY ), $args );
		unset( $args[ Fundraiser::POST_TYPE ], $args[ Team::POST_TYPE ], $args['post_type'], $args['name'], $args['p'] );

		return $args ? add_query_arg( $args, $canonical ) : $canonical;
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
