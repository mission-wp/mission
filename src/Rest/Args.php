<?php
/**
 * Factory for REST route arg schemas.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Builds register_rest_route() arg arrays with safe defaults.
 *
 * Any arg carrying a schema constraint (enum, minimum, maximum, pattern,
 * format) automatically gets `rest_validate_request_arg` — without it,
 * WordPress silently ignores those constraints. Explicit callbacks passed
 * in $props always win.
 */
class Args {

	/**
	 * Default sanitizers per type. Booleans intentionally have none.
	 *
	 * @var array<string, string>
	 */
	private const SANITIZERS = [
		'integer' => 'absint',
		'string'  => 'sanitize_text_field',
	];

	/**
	 * Schema keys that are silently ignored without a validate_callback.
	 *
	 * @var array<string, bool>
	 */
	private const CONSTRAINT_KEYS = [
		'enum'    => true,
		'minimum' => true,
		'maximum' => true,
		'pattern' => true,
		'format'  => true,
	];

	/**
	 * Integer arg, sanitized with absint.
	 *
	 * @param array<string, mixed> $props Additional schema properties.
	 * @return array<string, mixed>
	 */
	public static function integer( array $props = [] ): array {
		return self::build( 'integer', $props );
	}

	/**
	 * String arg, sanitized with sanitize_text_field.
	 *
	 * @param array<string, mixed> $props Additional schema properties.
	 * @return array<string, mixed>
	 */
	public static function string( array $props = [] ): array {
		return self::build( 'string', $props );
	}

	/**
	 * Boolean arg.
	 *
	 * @param array<string, mixed> $props Additional schema properties.
	 * @return array<string, mixed>
	 */
	public static function boolean( array $props = [] ): array {
		return self::build( 'boolean', $props );
	}

	/**
	 * String arg restricted to an enum, validated automatically.
	 *
	 * @param string[]             $values Allowed values.
	 * @param array<string, mixed> $props  Additional schema properties.
	 * @return array<string, mixed>
	 */
	public static function enum( array $values, array $props = [] ): array {
		return self::build( 'string', [ 'enum' => $values ] + $props );
	}

	/**
	 * The standard route ID arg.
	 *
	 * @param bool $required Whether the arg is required.
	 * @return array<string, mixed>
	 */
	public static function id( bool $required = true ): array {
		return self::build( 'integer', [ 'required' => $required ] );
	}

	/**
	 * Assemble the arg array, filling in default callbacks.
	 *
	 * @param string               $type  JSON Schema type.
	 * @param array<string, mixed> $props Schema properties; explicit callbacks win.
	 * @return array<string, mixed>
	 */
	private static function build( string $type, array $props ): array {
		$arg = [ 'type' => $type ] + $props;

		if ( ! isset( $arg['sanitize_callback'] ) && isset( self::SANITIZERS[ $type ] ) ) {
			$arg['sanitize_callback'] = self::SANITIZERS[ $type ];
		}

		if ( ! isset( $arg['validate_callback'] ) && array_intersect_key( $arg, self::CONSTRAINT_KEYS ) ) {
			$arg['validate_callback'] = 'rest_validate_request_arg';
		}

		return $arg;
	}
}
