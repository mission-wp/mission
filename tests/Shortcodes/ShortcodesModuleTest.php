<?php
/**
 * Tests for the ShortcodesModule class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Shortcodes;

use MissionDP\Shortcodes\ShortcodesModule;
use WP_UnitTestCase;

/**
 * ShortcodesModule test class.
 */
class ShortcodesModuleTest extends WP_UnitTestCase {

	/**
	 * All shipped shortcode tags.
	 *
	 * @var array<string>
	 */
	private const TAGS = [
		'mission_donation_form',
		'mission_donate_button',
		'mission_campaign',
		'mission_campaign_grid',
		'mission_campaign_image',
		'mission_campaign_progress',
		'mission_campaign_statistics',
		'mission_donor_wall',
		'mission_recent_donors',
		'mission_top_donors',
		'mission_donor_dashboard',
	];

	/**
	 * Restore the stock shortcode registrations after each test.
	 */
	public function tear_down(): void {
		( new ShortcodesModule() )->register_shortcodes();

		parent::tear_down();
	}

	/**
	 * Every block shortcode is registered on plugin boot.
	 */
	public function test_all_shortcodes_registered(): void {
		foreach ( self::TAGS as $tag ) {
			$this->assertTrue( shortcode_exists( $tag ), "Missing shortcode: $tag" );
		}
	}

	/**
	 * The mission_shortcodes filter can register an extra alias tag.
	 */
	public function test_filter_can_add_alias(): void {
		$add_alias = static function ( array $shortcodes ): array {
			$shortcodes['my_form'] = 'mission-donation-platform/donation-form';
			return $shortcodes;
		};

		add_filter( 'mission_shortcodes', $add_alias );
		( new ShortcodesModule() )->register_shortcodes();
		remove_filter( 'mission_shortcodes', $add_alias );

		$this->assertTrue( shortcode_exists( 'my_form' ) );

		remove_shortcode( 'my_form' );
	}

	/**
	 * The mission_shortcodes filter can drop a tag from registration.
	 */
	public function test_filter_can_remove_tag(): void {
		remove_shortcode( 'mission_top_donors' );

		$without_tag = static function ( array $shortcodes ): array {
			unset( $shortcodes['mission_top_donors'] );
			return $shortcodes;
		};

		add_filter( 'mission_shortcodes', $without_tag );
		( new ShortcodesModule() )->register_shortcodes();
		remove_filter( 'mission_shortcodes', $without_tag );

		$this->assertFalse( shortcode_exists( 'mission_top_donors' ) );
	}

	/**
	 * A shortcode mapped to an unregistered block renders an empty string.
	 */
	public function test_unregistered_block_renders_empty(): void {
		$this->assertSame( '', ( new ShortcodesModule() )->render( 'mission-donation-platform/does-not-exist', [] ) );
	}

	/**
	 * WordPress passes '' (not an array) when a shortcode has no attributes.
	 */
	public function test_empty_string_atts_do_not_fatal(): void {
		$this->assertIsString( ( new ShortcodesModule() )->render( 'mission-donation-platform/does-not-exist', '' ) );
	}
}
