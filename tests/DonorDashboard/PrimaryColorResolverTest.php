<?php
/**
 * Tests for the PrimaryColorResolver.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\DonorDashboard;

use MissionDP\DonorDashboard\PrimaryColorResolver;
use WP_UnitTestCase;

/**
 * Primary color resolver test class.
 */
class PrimaryColorResolverTest extends WP_UnitTestCase {

	/**
	 * Test a dark primary color gets light button text.
	 */
	public function test_dark_color_gets_light_text(): void {
		$vars = PrimaryColorResolver::compute( '#2fa36b' );

		$this->assertSame( '#2fa36b', $vars['--mission-primary'] );
		$this->assertSame( '#ffffff', $vars['--mission-primary-text'] );
		// Dark enough to use as-is on light backgrounds.
		$this->assertSame( '#2fa36b', $vars['--mission-primary-text-on-light'] );
	}

	/**
	 * Test a light primary color gets dark button text and a darkened on-light variant.
	 */
	public function test_light_color_gets_dark_text(): void {
		$vars = PrimaryColorResolver::compute( '#ffeb3b' );

		$this->assertSame( '#1e1e1e', $vars['--mission-primary-text'] );
		// Too light for text on white, so the on-light variant is darkened 45%.
		$this->assertSame( '#8c8120', $vars['--mission-primary-text-on-light'] );
	}

	/**
	 * Test the hover color is the primary darkened by 12%.
	 */
	public function test_hover_color_is_darkened(): void {
		$vars = PrimaryColorResolver::compute( '#2fa36b' );

		// 0x2f -> 41, 0xa3 -> 143, 0x6b -> 94 after 12% darkening.
		$this->assertSame( '#298f5e', $vars['--mission-primary-hover'] );
	}

	/**
	 * Test the light variant is a solid 10%-over-white tint of the raw hex.
	 *
	 * A solid hex is required: kses strips function values (color-mix, rgba)
	 * from custom properties when block output is sanitized.
	 */
	public function test_light_variant_is_solid_tint(): void {
		$vars = PrimaryColorResolver::compute( '#2fa36b' );

		// 0x2f -> 234, 0xa3 -> 246, 0x6b -> 240 blended 10% into white.
		$this->assertSame( '#eaf6f0', $vars['--mission-primary-light'] );
	}

	/**
	 * Test luminance values either side of the 0.5 threshold flip the text color.
	 */
	public function test_luminance_threshold(): void {
		// Pure middle grey #808080 has luminance 128/255 ~ 0.502 (just light).
		$this->assertSame( '#1e1e1e', PrimaryColorResolver::compute( '#808080' )['--mission-primary-text'] );

		// #7f7f7f is 127/255 ~ 0.498 (just dark).
		$this->assertSame( '#ffffff', PrimaryColorResolver::compute( '#7f7f7f' )['--mission-primary-text'] );
	}

	/**
	 * Test the inline style string joins escaped property-value pairs.
	 */
	public function test_inline_style_format(): void {
		$style = PrimaryColorResolver::inline_style( '#2fa36b' );

		$this->assertStringContainsString( '--mission-primary:#2fa36b', $style );
		$this->assertStringContainsString( '--mission-primary-hover:#298f5e', $style );
		$this->assertStringContainsString( '--mission-primary-text:#ffffff', $style );
		// Five declarations joined by semicolons.
		$this->assertCount( 5, explode( ';', $style ) );
	}
}
