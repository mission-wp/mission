<?php
/**
 * KSES helper for block render output.
 *
 * @package MissionDP
 */

namespace MissionDP\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * KSES utilities tailored to block render output.
 */
class Kses {

	/**
	 * Cached allowlist for block output.
	 *
	 * @var array<string, array<string, bool>>|null
	 */
	private static ?array $block_allowed = null;

	/**
	 * Sanitize block render HTML for echo'ing.
	 *
	 * Extends `wp_kses_allowed_html('post')` with:
	 * - SVG and the SVG child elements used for icons in our blocks
	 * - The Interactivity API `data-wp-*` attribute family
	 *
	 * Use this any time a block render template echoes an HTML string that
	 * has been (or might be) modified by a third-party filter — escape late.
	 *
	 * @param string $html Raw HTML.
	 * @return string Escaped HTML safe to echo.
	 */
	public static function block_output( string $html ): string {
		return wp_kses( $html, self::allowed_block_html() );
	}

	/**
	 * Build (and memoise) the allowlist used by block_output().
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function allowed_block_html(): array {
		if ( null !== self::$block_allowed ) {
			return self::$block_allowed;
		}

		$allowed = wp_kses_allowed_html( 'post' );

		// Common attributes that should be allowed on every interactive tag,
		// including Interactivity API directives and ARIA attributes.
		$shared_attrs = [
			'data-*'                => true,
			'aria-*'                => true,
			'role'                  => true,
			'tabindex'              => true,
			'data-wp-interactive'   => true,
			'data-wp-context'       => true,
			'data-wp-bind'          => true,
			'data-wp-class'         => true,
			'data-wp-style'         => true,
			'data-wp-text'          => true,
			'data-wp-on'            => true,
			'data-wp-on-window'     => true,
			'data-wp-on-document'   => true,
			'data-wp-watch'         => true,
			'data-wp-init'          => true,
			'data-wp-each'          => true,
			'data-wp-each-child'    => true,
			'data-wp-key'           => true,
			'data-wp-router-region' => true,
		];

		// Tags that appear in our block templates and may carry Interactivity
		// API directives, custom data-* attrs, or ARIA attrs.
		$interactive_tags = [
			'div',
			'span',
			'a',
			'button',
			'form',
			'fieldset',
			'legend',
			'label',
			'input',
			'select',
			'option',
			'textarea',
			'p',
			'ul',
			'ol',
			'li',
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
			'figure',
			'figcaption',
			'img',
			'table',
			'thead',
			'tbody',
			'tr',
			'th',
			'td',
		];

		foreach ( $interactive_tags as $tag ) {
			if ( ! isset( $allowed[ $tag ] ) ) {
				$allowed[ $tag ] = [];
			}
			$allowed[ $tag ] = array_merge( $allowed[ $tag ], $shared_attrs );
		}

		// Inputs and form controls need a baseline of attrs.
		$form_attrs = [
			'name'         => true,
			'type'         => true,
			'value'        => true,
			'placeholder'  => true,
			'required'     => true,
			'disabled'     => true,
			'readonly'     => true,
			'checked'      => true,
			'selected'     => true,
			'min'          => true,
			'max'          => true,
			'step'         => true,
			'pattern'      => true,
			'autocomplete' => true,
			'inputmode'    => true,
			'maxlength'    => true,
			'minlength'    => true,
			'multiple'     => true,
			'size'         => true,
			'rows'         => true,
			'cols'         => true,
			'for'          => true,
			'form'         => true,
			'method'       => true,
			'action'       => true,
			'enctype'      => true,
			'novalidate'   => true,
		];
		foreach ( [ 'input', 'select', 'option', 'textarea', 'form', 'label', 'fieldset' ] as $tag ) {
			$allowed[ $tag ] = array_merge( $allowed[ $tag ] ?? [], $form_attrs );
		}

		// SVG and friends — used for medal/star/icon rendering in blocks.
		$svg_attrs = array_merge(
			$shared_attrs,
			[
				'class'               => true,
				'style'               => true,
				'width'               => true,
				'height'              => true,
				'viewBox'             => true,
				'xmlns'               => true,
				'fill'                => true,
				'stroke'              => true,
				'stroke-width'        => true,
				'stroke-linecap'      => true,
				'stroke-linejoin'     => true,
				'stroke-dasharray'    => true,
				'stroke-dashoffset'   => true,
				'transform'           => true,
				'opacity'             => true,
				'preserveAspectRatio' => true,
			]
		);

		$allowed['svg']      = $svg_attrs;
		$allowed['path']     = $svg_attrs + [
			'd' => true,
		];
		$allowed['circle']   = $svg_attrs + [
			'cx' => true,
			'cy' => true,
			'r'  => true,
		];
		$allowed['ellipse']  = $svg_attrs + [
			'cx' => true,
			'cy' => true,
			'rx' => true,
			'ry' => true,
		];
		$allowed['rect']     = $svg_attrs + [
			'x'  => true,
			'y'  => true,
			'rx' => true,
			'ry' => true,
		];
		$allowed['line']     = $svg_attrs + [
			'x1' => true,
			'y1' => true,
			'x2' => true,
			'y2' => true,
		];
		$allowed['polyline'] = $svg_attrs + [
			'points' => true,
		];
		$allowed['polygon']  = $svg_attrs + [
			'points' => true,
		];
		$allowed['text']     = $svg_attrs + [
			'x'           => true,
			'y'           => true,
			'dx'          => true,
			'dy'          => true,
			'text-anchor' => true,
			'font-size'   => true,
			'font-weight' => true,
			'font-family' => true,
		];
		$allowed['g']        = $svg_attrs;
		$allowed['defs']     = $svg_attrs;
		$allowed['use']      = $svg_attrs + [
			'href'       => true,
			'xlink:href' => true,
			'x'          => true,
			'y'          => true,
		];
		$allowed['title']    = $svg_attrs;
		$allowed['desc']     = $svg_attrs;

		self::$block_allowed = $allowed;

		return $allowed;
	}
}
