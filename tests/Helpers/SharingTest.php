<?php
/**
 * Tests for the share network helper.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Helpers;

use MissionDP\Helpers\Sharing;
use WP_UnitTestCase;

/**
 * Sharing helper test class.
 */
class SharingTest extends WP_UnitTestCase {

	/**
	 * intent_url() builds a mailto link with the encoded text and URL.
	 */
	public function test_email_intent_url(): void {
		$url = Sharing::intent_url( 'email', 'https://example.org/page', 'Check this out' );

		$this->assertSame( 'mailto:?body=' . rawurlencode( 'Check this out https://example.org/page' ), $url );
	}

	/**
	 * intent_url() returns '' for unknown networks.
	 */
	public function test_unknown_network_returns_empty(): void {
		$this->assertSame( '', Sharing::intent_url( 'myspace', 'https://example.org' ) );
	}

	/**
	 * Facebook's sharer only receives the URL, never free text.
	 */
	public function test_facebook_intent_is_url_only(): void {
		$url = Sharing::intent_url( 'facebook', 'https://example.org/page', 'ignored text' );

		$this->assertSame( 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( 'https://example.org/page' ), $url );
	}

	/**
	 * Email is surface-opt-in: networks() never returns it by default, so
	 * surfaces that don't append it are unaffected.
	 */
	public function test_default_networks_exclude_email(): void {
		$this->assertNotContains( 'email', Sharing::networks( 'signup-modal' ) );
		$this->assertSame( [ 'facebook', 'x', 'bluesky' ], Sharing::networks( 'donor-dashboard' ) );
	}
}
