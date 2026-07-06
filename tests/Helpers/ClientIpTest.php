<?php
/**
 * Tests for the ClientIp helper.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Helpers;

use MissionDP\Helpers\ClientIp;
use WP_UnitTestCase;

/**
 * ClientIp test class.
 */
class ClientIpTest extends WP_UnitTestCase {

	/**
	 * Original $_SERVER values to restore.
	 *
	 * @var array<string, mixed>
	 */
	private array $server_backup = [];

	/**
	 * Back up the $_SERVER keys the helper reads.
	 */
	public function set_up(): void {
		parent::set_up();

		foreach ( [ 'REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ] as $key ) {
			$this->server_backup[ $key ] = $_SERVER[ $key ] ?? null;
			unset( $_SERVER[ $key ] );
		}
	}

	/**
	 * Restore $_SERVER.
	 */
	public function tear_down(): void {
		foreach ( $this->server_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}

		parent::tear_down();
	}

	/**
	 * Test REMOTE_ADDR is used when no proxy is involved.
	 */
	public function test_returns_remote_addr_by_default(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$this->assertSame( '203.0.113.5', ClientIp::get() );
	}

	/**
	 * Test the fallback when no address is available at all.
	 */
	public function test_returns_placeholder_without_remote_addr(): void {
		$this->assertSame( '0.0.0.0', ClientIp::get() );
	}

	/**
	 * Test CF-Connecting-IP is trusted when the connection comes from a
	 * Cloudflare address.
	 */
	public function test_trusts_cf_header_from_cloudflare_address(): void {
		$_SERVER['REMOTE_ADDR']           = '104.16.132.229'; // In 104.16.0.0/13.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';

		$this->assertSame( '203.0.113.5', ClientIp::get() );
	}

	/**
	 * Test CF-Connecting-IP is trusted from a Cloudflare IPv6 address.
	 */
	public function test_trusts_cf_header_from_cloudflare_ipv6_address(): void {
		$_SERVER['REMOTE_ADDR']           = '2606:4700::6810:84e5'; // In 2606:4700::/32.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';

		$this->assertSame( '203.0.113.5', ClientIp::get() );
	}

	/**
	 * Test a spoofed CF-Connecting-IP from a non-Cloudflare address is ignored.
	 */
	public function test_ignores_cf_header_from_untrusted_address(): void {
		$_SERVER['REMOTE_ADDR']           = '203.0.113.5';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.99';

		$this->assertSame( '203.0.113.5', ClientIp::get() );
	}

	/**
	 * Test an invalid header value falls back to REMOTE_ADDR even when the
	 * header is trusted.
	 */
	public function test_invalid_header_value_falls_back_to_remote_addr(): void {
		$_SERVER['REMOTE_ADDR']           = '104.16.132.229';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';

		$this->assertSame( '104.16.132.229', ClientIp::get() );
	}

	/**
	 * Test the mission_trusted_proxy_headers filter opts in other proxies.
	 */
	public function test_filter_trusts_custom_proxy_header(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.2';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5';

		$ip = $this->get_with_xff_trusted();

		$this->assertSame( '203.0.113.5', $ip );
	}

	/**
	 * Test a client-prepended X-Forwarded-For entry cannot spoof the result:
	 * the last (proxy-appended) entry wins.
	 */
	public function test_xff_spoofed_first_entry_loses_to_proxy_appended_entry(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.2';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 203.0.113.9';

		$this->assertSame( '203.0.113.9', $this->get_with_xff_trusted() );
	}

	/**
	 * Test an invalid trailing X-Forwarded-For entry is skipped and the walk
	 * continues leftward to the nearest valid IP.
	 */
	public function test_xff_invalid_trailing_entry_is_skipped(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.2';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, unknown';

		$this->assertSame( '203.0.113.9', $this->get_with_xff_trusted() );
	}

	/**
	 * Resolve the client IP with X-Forwarded-For opted in as a trusted header.
	 *
	 * @return string
	 */
	private function get_with_xff_trusted(): string {
		$filter = static fn(): array => [ 'HTTP_X_FORWARDED_FOR' ];
		add_filter( 'mission_trusted_proxy_headers', $filter );

		$ip = ClientIp::get();

		remove_filter( 'mission_trusted_proxy_headers', $filter );

		return $ip;
	}

	/**
	 * Test Cloudflare range membership across boundaries.
	 */
	public function test_is_cloudflare_ip(): void {
		$this->assertTrue( ClientIp::is_cloudflare_ip( '104.16.0.1' ) );     // Bottom of 104.16.0.0/13.
		$this->assertTrue( ClientIp::is_cloudflare_ip( '104.23.255.254' ) ); // Top of 104.16.0.0/13.
		$this->assertTrue( ClientIp::is_cloudflare_ip( '104.24.0.0' ) );      // In 104.24.0.0/14.
		$this->assertFalse( ClientIp::is_cloudflare_ip( '104.32.0.1' ) );    // Outside both.
		$this->assertTrue( ClientIp::is_cloudflare_ip( '2400:cb00:1::1' ) );
		$this->assertFalse( ClientIp::is_cloudflare_ip( '2001:db8::1' ) );
		$this->assertFalse( ClientIp::is_cloudflare_ip( 'garbage' ) );
	}
}
