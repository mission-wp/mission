<?php
/**
 * Renders a block from shortcode attributes.
 *
 * @package MissionDP
 */

namespace MissionDP\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Turns shortcode attributes into serialized block markup and renders it
 * through do_blocks(), so shortcodes get the full block pipeline: render
 * callbacks, view script/style enqueueing, and Interactivity API directives.
 */
class ShortcodeRenderer {

	/**
	 * Render a block from raw shortcode attributes.
	 *
	 * @param string               $block_name Full block name (e.g. mission-donation-platform/donation-form).
	 * @param array<string, mixed> $raw_atts   Raw shortcode attributes.
	 * @param array<string, mixed> $overrides  Typed block attributes merged over the coerced ones.
	 *
	 * @return string Rendered block HTML, or an empty string if the block is not registered.
	 */
	public static function render( string $block_name, array $raw_atts, array $overrides = [] ): string {
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );

		if ( ! $block_type ) {
			return '';
		}

		$attributes = array_merge(
			AttributeCoercer::coerce( $block_type->attributes ?? [], $raw_atts ),
			$overrides
		);

		/**
		 * Filters the block attributes a Mission shortcode renders with.
		 *
		 * @param array<string, mixed> $attributes Typed block attributes.
		 * @param string               $block_name Full block name being rendered.
		 * @param array<string, mixed> $raw_atts   Raw shortcode attributes as supplied.
		 */
		$attributes = apply_filters( 'mission_shortcode_attributes', $attributes, $block_name, $raw_atts );

		return do_blocks(
			serialize_block(
				[
					'blockName'    => $block_name,
					'attrs'        => $attributes,
					'innerBlocks'  => [],
					'innerHTML'    => '',
					'innerContent' => [],
				]
			)
		);
	}
}
