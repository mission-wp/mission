<?php
/**
 * Tests for the AttributeCoercer class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Shortcodes;

use MissionDP\Shortcodes\AttributeCoercer;
use WP_UnitTestCase;

/**
 * AttributeCoercer test class.
 */
class AttributeCoercerTest extends WP_UnitTestCase {

	/**
	 * Synthetic schema with one attribute of each block.json type.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const SCHEMA = [
		'campaignId'         => [ 'type' => 'number' ],
		'showImage'          => [ 'type' => 'boolean' ],
		'buttonText'         => [ 'type' => 'string' ],
		'tipPercentages'     => [ 'type' => 'array' ],
		'amountsByFrequency' => [ 'type' => 'object' ],
		'customFields'       => [ 'type' => 'array' ],
	];

	/**
	 * Numeric strings become ints or floats; garbage is dropped.
	 */
	public function test_number_coercion(): void {
		$this->assertSame( [ 'campaignId' => 123 ], AttributeCoercer::coerce( self::SCHEMA, [ 'campaign_id' => '123' ] ) );
		$this->assertSame( [ 'campaignId' => 2.5 ], AttributeCoercer::coerce( self::SCHEMA, [ 'campaign_id' => '2.5' ] ) );
		$this->assertSame( [], AttributeCoercer::coerce( self::SCHEMA, [ 'campaign_id' => 'abc' ] ) );
		$this->assertSame( [], AttributeCoercer::coerce( self::SCHEMA, [ 'campaign_id' => '' ] ) );
	}

	/**
	 * All accepted boolean tokens parse; unrecognized values are dropped.
	 */
	public function test_boolean_coercion(): void {
		foreach ( [ 'true', '1', 'yes', 'on', 'TRUE' ] as $token ) {
			$this->assertSame( [ 'showImage' => true ], AttributeCoercer::coerce( self::SCHEMA, [ 'show_image' => $token ] ), "Token: $token" );
		}

		foreach ( [ 'false', '0', 'no', 'off', 'FALSE' ] as $token ) {
			$this->assertSame( [ 'showImage' => false ], AttributeCoercer::coerce( self::SCHEMA, [ 'show_image' => $token ] ), "Token: $token" );
		}

		$this->assertSame( [], AttributeCoercer::coerce( self::SCHEMA, [ 'show_image' => 'maybe' ] ) );
	}

	/**
	 * Strings pass through unchanged; empty strings are dropped so block
	 * defaults (including translatable fallbacks) apply.
	 */
	public function test_string_coercion(): void {
		$this->assertSame( [ 'buttonText' => 'Give Now' ], AttributeCoercer::coerce( self::SCHEMA, [ 'button_text' => 'Give Now' ] ) );
		$this->assertSame( [], AttributeCoercer::coerce( self::SCHEMA, [ 'button_text' => '' ] ) );
	}

	/**
	 * Comma lists become arrays with numeric items cast and empties discarded.
	 */
	public function test_array_coercion(): void {
		$this->assertSame( [ 'tipPercentages' => [ 5, 10, 15 ] ], AttributeCoercer::coerce( self::SCHEMA, [ 'tip_percentages' => '5, 10 ,15' ] ) );
		$this->assertSame( [ 'tipPercentages' => [ 'a', 'b' ] ], AttributeCoercer::coerce( self::SCHEMA, [ 'tip_percentages' => 'a,,b' ] ) );
		$this->assertSame( [], AttributeCoercer::coerce( self::SCHEMA, [ 'tip_percentages' => '' ] ) );
	}

	/**
	 * Object-typed attributes are never settable from a shortcode.
	 */
	public function test_object_attributes_are_dropped(): void {
		$this->assertSame( [], AttributeCoercer::coerce( self::SCHEMA, [ 'amounts_by_frequency' => '{"one_time":[1000]}' ] ) );
	}

	/**
	 * Denied attributes are ignored even though their type is coercible.
	 */
	public function test_denied_attributes_are_dropped(): void {
		$this->assertSame( [], AttributeCoercer::coerce( self::SCHEMA, [ 'custom_fields' => 'a,b' ] ) );
		$this->assertSame( [], AttributeCoercer::coerce( self::SCHEMA, [ 'customfields' => 'a,b' ] ) );
	}

	/**
	 * Both snake_case and flat-lowercase attribute names resolve.
	 */
	public function test_name_mapping(): void {
		$this->assertSame( [ 'campaignId' => 7 ], AttributeCoercer::coerce( self::SCHEMA, [ 'campaign_id' => '7' ] ) );
		$this->assertSame( [ 'campaignId' => 7 ], AttributeCoercer::coerce( self::SCHEMA, [ 'campaignid' => '7' ] ) );
	}

	/**
	 * Attributes not in the schema are silently ignored.
	 */
	public function test_unknown_attributes_are_ignored(): void {
		$this->assertSame( [], AttributeCoercer::coerce( self::SCHEMA, [ 'bogus' => '1' ] ) );
	}

	/**
	 * Positional (unnamed) shortcode values are ignored rather than fataling.
	 */
	public function test_positional_attributes_are_ignored(): void {
		$this->assertSame( [ 'campaignId' => 7 ], AttributeCoercer::coerce( self::SCHEMA, [ 0 => 'stray', 'campaign_id' => '7' ] ) );
	}
}
