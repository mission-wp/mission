<?php
/**
 * Campaign URL slug helpers.
 *
 * @package MissionDP
 */

namespace MissionDP\Campaigns;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitization, conflict detection, and candidate selection for the
 * campaign URL slug setting.
 */
class CampaignSlug {

	public const DEFAULT_SLUG = 'campaigns';

	/**
	 * Candidate slugs tried in order on fresh installs.
	 *
	 * @var string[]
	 */
	private const CANDIDATES = [ 'campaigns', 'giving', 'fundraisers', 'mission-campaigns' ];

	/**
	 * Slugs that collide with WordPress core rewrites and paths.
	 *
	 * @var string[]
	 */
	private const RESERVED = [
		'attachment',
		'author',
		'category',
		'comments',
		'date',
		'embed',
		'feed',
		'page',
		'post',
		'posts',
		'search',
		'tag',
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-json',
		'wp-login',
	];

	/**
	 * Sanitize a slug down to a single lowercase URL path segment.
	 *
	 * @param string $slug Raw slug input.
	 * @return string Sanitized slug, or '' if nothing usable remains.
	 */
	public static function sanitize( string $slug ): string {
		return sanitize_title( $slug );
	}

	/**
	 * Whether a slug is reserved by WordPress core.
	 *
	 * @param string $slug Sanitized slug.
	 * @return bool
	 */
	public static function is_reserved( string $slug ): bool {
		return in_array( $slug, self::RESERVED, true );
	}

	/**
	 * Find existing content occupying the given top-level path.
	 *
	 * Checks pages always, and posts only under a postname-first permalink
	 * structure (otherwise posts don't live at top-level paths). Campaign
	 * posts and the campaign archive are never matched, so the current
	 * slug can't conflict with itself.
	 *
	 * @param string $slug Sanitized slug to check.
	 * @return WP_Post|null The conflicting post, or null if the path is free.
	 */
	public static function find_conflict( string $slug ): ?WP_Post {
		if ( '' === $slug ) {
			return null;
		}

		$types = [ 'page' ];

		if ( str_starts_with( (string) get_option( 'permalink_structure' ), '/%postname%' ) ) {
			$types[] = 'post';
		}

		$post = get_page_by_path( $slug, OBJECT, $types );

		// get_page_by_path() ignores status; only live content blocks a slug.
		if ( $post && in_array( $post->post_status, [ 'publish', 'private' ], true ) ) {
			return $post;
		}

		return null;
	}

	/**
	 * Pick the first candidate slug whose path is not already taken.
	 *
	 * Falls back to numbered variants of the default if every candidate
	 * conflicts with existing content.
	 *
	 * @return string
	 */
	public static function first_available(): string {
		foreach ( self::CANDIDATES as $candidate ) {
			if ( ! self::find_conflict( $candidate ) ) {
				return $candidate;
			}
		}

		for ( $i = 2; $i <= 100; $i++ ) {
			$candidate = self::DEFAULT_SLUG . '-' . $i;

			if ( ! self::find_conflict( $candidate ) ) {
				return $candidate;
			}
		}

		return self::DEFAULT_SLUG;
	}
}
