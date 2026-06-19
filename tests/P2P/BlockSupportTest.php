<?php
/**
 * Unit tests for the shared P2P block rendering helpers.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\P2P;

use MissionDP\P2P\BlockSupport;
use WP_UnitTestCase;

/**
 * BlockSupport helper test class.
 */
class BlockSupportTest extends WP_UnitTestCase {

	/**
	 * Empty attributes produce no inline style.
	 */
	public function test_image_inline_style_empty(): void {
		$this->assertSame( '', BlockSupport::image_inline_style( [] ) );
	}

	/**
	 * Aspect ratio, dimensions, and scale render as image styles.
	 */
	public function test_image_inline_style_sizing(): void {
		$style = BlockSupport::image_inline_style(
			[
				'aspectRatio' => '16/9',
				'width'       => '300',
				'height'      => '200',
				'scale'       => 'contain',
			]
		);

		$this->assertStringContainsString( 'aspect-ratio:16/9', $style );
		$this->assertStringContainsString( 'object-fit:contain', $style );
		$this->assertStringContainsString( 'width:300px', $style );
		$this->assertStringContainsString( 'height:200px', $style );
	}

	/**
	 * An invalid scale falls back to cover.
	 */
	public function test_image_inline_style_invalid_scale_falls_back(): void {
		$style = BlockSupport::image_inline_style(
			[
				'aspectRatio' => '1/1',
				'scale'       => 'bogus',
			]
		);

		$this->assertStringContainsString( 'object-fit:cover', $style );
	}

	/**
	 * Border and shadow presets expand to CSS custom property references.
	 */
	public function test_image_inline_style_border_and_shadow_presets(): void {
		$style = BlockSupport::image_inline_style(
			[
				'style' => [
					'border' => [
						'radius' => '8px',
						'width'  => '2px',
						'style'  => 'solid',
						'color'  => 'var:preset|color|accent',
					],
					'shadow' => 'var:preset|shadow|natural',
				],
			]
		);

		$this->assertStringContainsString( 'border-radius:8px', $style );
		$this->assertStringContainsString( 'border-width:2px', $style );
		$this->assertStringContainsString( 'border-style:solid', $style );
		$this->assertStringContainsString( 'border-color:var(--wp--preset--color--accent)', $style );
		$this->assertStringContainsString( 'box-shadow:var(--wp--preset--shadow--natural)', $style );
	}

	/**
	 * Split border radius corners render individually.
	 */
	public function test_image_inline_style_split_radius(): void {
		$style = BlockSupport::image_inline_style(
			[
				'style' => [
					'border' => [
						'radius' => [
							'topLeft'     => '4px',
							'bottomRight' => '12px',
						],
					],
				],
			]
		);

		$this->assertStringContainsString( 'border-top-left-radius:4px', $style );
		$this->assertStringContainsString( 'border-bottom-right-radius:12px', $style );
	}

	/**
	 * An empty image value yields no markup.
	 */
	public function test_image_html_empty(): void {
		$this->assertSame( '', BlockSupport::image_html( '' ) );
	}

	/**
	 * A URL image renders an img tag carrying the extra attributes.
	 */
	public function test_image_html_url_with_attrs(): void {
		$html = BlockSupport::image_html(
			'https://example.com/photo.jpg',
			'A cover photo',
			'large',
			[ 'style' => 'aspect-ratio:16/9' ]
		);

		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'src="https://example.com/photo.jpg"', $html );
		$this->assertStringContainsString( 'alt="A cover photo"', $html );
		$this->assertStringContainsString( 'style="aspect-ratio:16/9"', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
	}

	/**
	 * Empty extra attribute values are skipped.
	 */
	public function test_image_html_url_skips_empty_attrs(): void {
		$html = BlockSupport::image_html(
			'https://example.com/photo.jpg',
			'',
			'large',
			[ 'style' => '' ]
		);

		$this->assertStringNotContainsString( 'style=', $html );
	}
}
