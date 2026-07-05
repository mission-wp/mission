<?php
/**
 * Client IP resolution with proxy/CDN awareness.
 *
 * @package MissionDP
 */

namespace MissionDP\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the real client IP behind known proxies.
 *
 * Proxy headers are trivially spoofable by a direct client, so a header is
 * only consulted when the connecting address (REMOTE_ADDR) proves the request
 * came through a proxy that sets it: Cloudflare's published ranges are trusted
 * automatically, and other proxies opt in via the
 * `mission_trusted_proxy_headers` filter.
 */
class ClientIp {

	/**
	 * Cloudflare's published IP ranges (https://www.cloudflare.com/ips/).
	 *
	 * A point-in-time snapshot; the list changes rarely, and sites can adjust
	 * it via the `mission_cloudflare_ip_ranges` filter without a plugin update.
	 *
	 * @var string[]
	 */
	private const CLOUDFLARE_RANGES = [
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	];

	/**
	 * Get the client IP address.
	 *
	 * @return string Client IP, or '0.0.0.0' when none can be determined.
	 */
	public static function get(): string {
		$remote_addr = self::extract_ip( $_SERVER['REMOTE_ADDR'] ?? '' );

		/**
		 * Filters the proxy headers trusted for the client IP.
		 *
		 * Defaults to CF-Connecting-IP when the request demonstrably comes
		 * from a Cloudflare address, empty otherwise. A site behind another
		 * proxy/CDN that sets a client-IP header should return it here, e.g.
		 * [ 'HTTP_X_FORWARDED_FOR' ] behind a load balancer.
		 *
		 * @param string[] $headers     $_SERVER keys to consult before REMOTE_ADDR.
		 * @param string   $remote_addr The connecting address.
		 */
		$trusted = (array) apply_filters( 'mission_trusted_proxy_headers', self::default_trusted_headers( $remote_addr ), $remote_addr );

		foreach ( $trusted as $header ) {
			$ip = self::extract_ip( $_SERVER[ $header ] ?? '' );

			if ( $ip ) {
				return $ip;
			}
		}

		return $remote_addr ? $remote_addr : '0.0.0.0';
	}

	/**
	 * Headers trusted without site configuration.
	 *
	 * @param string $remote_addr The connecting address.
	 * @return string[] $_SERVER keys.
	 */
	private static function default_trusted_headers( string $remote_addr ): array {
		if ( $remote_addr && self::is_cloudflare_ip( $remote_addr ) ) {
			return [ 'HTTP_CF_CONNECTING_IP' ];
		}

		return [];
	}

	/**
	 * Whether an address belongs to Cloudflare's published ranges.
	 *
	 * @param string $ip The address to test.
	 * @return bool
	 */
	public static function is_cloudflare_ip( string $ip ): bool {
		/**
		 * Filters the Cloudflare IP ranges trusted for CF-Connecting-IP.
		 *
		 * @param string[] $ranges CIDR ranges.
		 */
		$ranges = (array) apply_filters( 'mission_cloudflare_ip_ranges', self::CLOUDFLARE_RANGES );

		foreach ( $ranges as $range ) {
			if ( self::ip_in_cidr( $ip, (string) $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Pull the first valid IP out of a raw header value.
	 *
	 * @param mixed $value Raw $_SERVER value (may hold a comma-separated list).
	 * @return string Valid IP, or empty string.
	 */
	private static function extract_ip( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		// X-Forwarded-For can contain multiple IPs; use the first.
		$ip = trim( (string) strtok( sanitize_text_field( wp_unslash( $value ) ), ',' ) );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Whether an IP falls within a CIDR range (IPv4 or IPv6).
	 *
	 * @param string $ip   The address to test.
	 * @param string $cidr The range, e.g. '104.16.0.0/13' or '2606:4700::/32'.
	 * @return bool
	 */
	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			return false;
		}

		[ $subnet, $bits ] = explode( '/', $cidr, 2 );

		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );

		// Mixed families (or invalid input) never match.
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$bits = (int) $bits;

		if ( $bits < 0 || $bits > strlen( $ip_bin ) * 8 ) {
			return false;
		}

		$whole_bytes = intdiv( $bits, 8 );

		if ( $whole_bytes > 0 && substr( $ip_bin, 0, $whole_bytes ) !== substr( $subnet_bin, 0, $whole_bytes ) ) {
			return false;
		}

		$partial_bits = $bits % 8;

		if ( 0 === $partial_bits ) {
			return true;
		}

		$mask = ~( 0xFF >> $partial_bits ) & 0xFF;

		return ( ord( $ip_bin[ $whole_bytes ] ) & $mask ) === ( ord( $subnet_bin[ $whole_bytes ] ) & $mask );
	}
}
