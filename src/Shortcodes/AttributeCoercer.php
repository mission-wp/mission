<?php
/**
 * Coerces shortcode attribute strings into typed block attributes.
 *
 * @package MissionDP
 */

namespace MissionDP\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Maps snake_case shortcode attributes onto a block's declared attribute
 * schema and casts the string values to the types declared in block.json.
 */
class AttributeCoercer {

	/**
	 * Attributes that must never be settable from a shortcode, even though
	 * their declared type is coercible (arrays of objects, editor-only config).
	 *
	 * @var array<string>
	 */
	private const DENIED = [ 'customFields' ];

	/**
	 * Coerce raw shortcode attributes against a block attribute schema.
	 *
	 * Attribute names are matched case-insensitively in both snake_case
	 * (campaign_id) and flat lowercase (campaignid) forms. Values that cannot
	 * be coerced to the declared type are dropped so the block default applies.
	 *
	 * @param array<string, array<string, mixed>> $schema   Block attribute schema from block.json.
	 * @param array<string, mixed>                $raw_atts Raw shortcode attributes.
	 *
	 * @return array<string, mixed> Typed block attributes.
	 */
	public static function coerce( array $schema, array $raw_atts ): array {
		$names      = self::name_map( $schema );
		$attributes = [];

		foreach ( $raw_atts as $name => $value ) {
			$attribute = $names[ strtolower( (string) $name ) ] ?? null;

			if ( null === $attribute || ! is_scalar( $value ) ) {
				continue;
			}

			$type    = $schema[ $attribute ]['type'] ?? 'string';
			$coerced = self::coerce_value( is_array( $type ) ? (string) reset( $type ) : (string) $type, (string) $value );

			if ( null !== $coerced ) {
				$attributes[ $attribute ] = $coerced;
			}
		}

		return $attributes;
	}

	/**
	 * Build a lookup of accepted shortcode names to declared attribute names.
	 *
	 * @param array<string, array<string, mixed>> $schema Block attribute schema.
	 *
	 * @return array<string, string> Lowercase shortcode name => camelCase attribute name.
	 */
	private static function name_map( array $schema ): array {
		$names = [];

		foreach ( array_keys( $schema ) as $attribute ) {
			if ( in_array( $attribute, self::DENIED, true ) ) {
				continue;
			}

			$snake = strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $attribute ) );

			$names[ $snake ]                   = $attribute;
			$names[ strtolower( $attribute ) ] = $attribute;
		}

		return $names;
	}

	/**
	 * Coerce a single string value to a declared block attribute type.
	 *
	 * @param string $type  Declared type from block.json.
	 * @param string $value Raw shortcode value.
	 *
	 * @return mixed Coerced value, or null to drop the attribute.
	 */
	private static function coerce_value( string $type, string $value ): mixed {
		return match ( $type ) {
			'number', 'integer' => self::to_number( $value ),
			'boolean'           => self::to_boolean( $value ),
			'string'            => '' === $value ? null : $value,
			'array'             => self::to_array( $value ),
			default             => null,
		};
	}

	/**
	 * Cast a numeric string to int or float.
	 *
	 * @param string $value Raw value.
	 *
	 * @return int|float|null Number, or null when not numeric.
	 */
	private static function to_number( string $value ): int|float|null {
		$value = trim( $value );

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		return str_contains( $value, '.' ) ? (float) $value : (int) $value;
	}

	/**
	 * Interpret a boolean-ish string.
	 *
	 * @param string $value Raw value.
	 *
	 * @return bool|null Boolean, or null when unrecognized.
	 */
	private static function to_boolean( string $value ): ?bool {
		return match ( strtolower( trim( $value ) ) ) {
			'true', '1', 'yes', 'on'   => true,
			'false', '0', 'no', 'off' => false,
			default                    => null,
		};
	}

	/**
	 * Split a comma-separated list into an array of scalars.
	 *
	 * @param string $value Raw value.
	 *
	 * @return array<int|float|string>|null Items, or null when the list is empty.
	 */
	private static function to_array( string $value ): ?array {
		$items = array_filter(
			array_map( 'trim', explode( ',', $value ) ),
			static fn( string $item ): bool => '' !== $item
		);

		if ( ! $items ) {
			return null;
		}

		return array_values(
			array_map(
				static fn( string $item ) => is_numeric( $item ) ? self::to_number( $item ) : $item,
				$items
			)
		);
	}
}
