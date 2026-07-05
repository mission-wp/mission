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
	 * Allowlist for block render output: extends `wp_kses_allowed_html('post')`
	 * with SVG icons and the Interactivity API `data-wp-*` attribute family.
	 *
	 * Pass the result as the second argument to `wp_kses()` when echoing block
	 * render HTML that may have been modified by a third-party filter.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function block_allowed_html(): array {
		if ( null !== self::$block_allowed ) {
			return self::$block_allowed;
		}

		$allowed = wp_kses_allowed_html( 'post' );

		// `class`, `id`, and `style` are listed explicitly because WordPress's
		// `post` allowlist omits some tags entirely (e.g. `input`).
		$shared_attrs = [
			'class'                 => true,
			'id'                    => true,
			'style'                 => true,
			'title'                 => true,
			'hidden'                => true,
			'lang'                  => true,
			'dir'                   => true,
			'data-*'                => true,
			// kses has no aria wildcard (only `data-*` is special-cased), so
			// the ARIA attributes must be listed individually.
			'aria-label'            => true,
			'aria-labelledby'       => true,
			'aria-describedby'      => true,
			'aria-details'          => true,
			'aria-hidden'           => true,
			'aria-live'             => true,
			'aria-atomic'           => true,
			'aria-expanded'         => true,
			'aria-controls'         => true,
			'aria-current'          => true,
			'aria-modal'            => true,
			'aria-pressed'          => true,
			'aria-checked'          => true,
			'aria-selected'         => true,
			'aria-disabled'         => true,
			'aria-required'         => true,
			'aria-invalid'          => true,
			'aria-haspopup'         => true,
			'aria-busy'             => true,
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

		// `template` is load-bearing: data-wp-each renders nothing without it.
		$interactive_tags = [
			'template',
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
