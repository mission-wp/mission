<?php
/**
 * Reusable IP-based rate limiting for REST endpoints.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Traits;

use MissionDP\Helpers\AttemptCounter;
use MissionDP\Helpers\ClientIp;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Provides check_rate_limit() and get_client_ip() for any endpoint.
 */
trait RateLimitTrait {

	/**
	 * Check whether the current IP has exceeded the allowed number of attempts.
	 *
	 * @param string $action Unique action identifier (used in transient key and filter).
	 * @param int    $limit  Maximum attempts within the window.
	 * @param int    $window Time window in seconds.
	 *
	 * @return WP_Error|null Error if rate limited, null otherwise.
	 */
	private function check_rate_limit( string $action, int $limit, int $window ): ?WP_Error {
		/**
		 * Filter the maximum attempts for a rate-limited action.
		 *
		 * @param int    $limit  Maximum attempts.
		 * @param string $action Action identifier.
		 */
		$limit = (int) apply_filters( 'mission_rate_limit', $limit, $action );

		/**
		 * Filter the time window (in seconds) for a rate-limited action.
		 *
		 * @param int    $window Time window in seconds.
		 * @param string $action Action identifier.
		 */
		$window = (int) apply_filters( 'mission_rate_window', $window, $action );

		$ip = $this->get_client_ip();

		// Atomic claim: parallel requests cannot race past the cap the way a
		// transient read-modify-write counter could.
		if ( ! AttemptCounter::claim( 'rl_' . $action . '_' . md5( $ip ), $limit, $window ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many attempts. Please try again in a few minutes.', 'mission-donation-platform' ),
				[ 'status' => 429 ]
			);
		}

		return null;
	}

	/**
	 * Get the client IP address.
	 *
	 * Cloudflare's CF-Connecting-IP header is trusted automatically when the
	 * request comes from a Cloudflare address; other proxies opt in via the
	 * `mission_trusted_proxy_headers` filter (see ClientIp).
	 *
	 * @return string Client IP.
	 */
	private function get_client_ip(): string {
		return ClientIp::get();
	}
}
