<?php
/**
 * Share network resolution.
 *
 * @package MissionDP
 */

namespace MissionDP\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves which social share networks the plugin's share UIs render.
 */
class Sharing {

	/**
	 * The share networks rendered by default, in display order.
	 *
	 * @var string[]
	 */
	public const DEFAULT_NETWORKS = [ 'facebook', 'x', 'bluesky' ];

	/**
	 * Get the enabled share networks for a rendering surface.
	 *
	 * @param string $context Rendering surface (e.g. 'signup-modal', 'donor-dashboard').
	 * @return string[] Enabled network keys, in display order.
	 */
	public static function networks( string $context ): array {
		/**
		 * Filters the social share networks rendered by share UIs.
		 *
		 * Return a subset of the defaults to drop networks (e.g. remove 'x'),
		 * or an empty array to render no social share buttons at all.
		 * Copy-link controls are not affected by this filter.
		 *
		 * @param string[] $networks Network keys in display order. Default: 'facebook', 'x', 'bluesky'.
		 * @param string   $context  Rendering surface: 'signup-modal' or 'donor-dashboard'.
		 */
		$networks = apply_filters( 'mission_share_networks', self::DEFAULT_NETWORKS, $context );

		return array_values( array_intersect( (array) $networks, self::DEFAULT_NETWORKS ) );
	}
}
