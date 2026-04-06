<?php
/**
 * Reusable IP-based rate limiting for REST endpoints.
 *
 * @package Mission
 */

namespace Mission\Rest\Traits;

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

		$ip  = $this->get_client_ip();
		$key = 'mission_rl_' . $action . '_' . md5( $ip );

		$attempts = (int) get_transient( $key );

		if ( $attempts >= $limit ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many attempts. Please try again in a few minutes.', 'mission' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, $attempts + 1, $window );

		return null;
	}

	/**
	 * Get the client IP address, accounting for common proxies.
	 *
	 * @return string Client IP.
	 */
	private function get_client_ip(): string {
		$headers = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// X-Forwarded-For can contain multiple IPs; use the first.
				$ip = strtok( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), ',' );
				$ip = trim( $ip );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}
}
