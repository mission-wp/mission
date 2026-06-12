<?php
/**
 * Tests for the Args factory.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest;

use MissionDP\Rest\Args;
use WP_UnitTestCase;

/**
 * Args test class.
 */
class ArgsTest extends WP_UnitTestCase {

	/**
	 * Test integer args default to absint with no validate_callback when unconstrained.
	 */
	public function test_integer_defaults(): void {
		$arg = Args::integer();

		$this->assertSame( 'integer', $arg['type'] );
		$this->assertSame( 'absint', $arg['sanitize_callback'] );
		$this->assertArrayNotHasKey( 'validate_callback', $arg );
	}

	/**
	 * Test string args default to sanitize_text_field.
	 */
	public function test_string_defaults(): void {
		$arg = Args::string();

		$this->assertSame( 'string', $arg['type'] );
		$this->assertSame( 'sanitize_text_field', $arg['sanitize_callback'] );
		$this->assertArrayNotHasKey( 'validate_callback', $arg );
	}

	/**
	 * Test boolean args get no default sanitizer.
	 */
	public function test_boolean_has_no_default_sanitizer(): void {
		$arg = Args::boolean();

		$this->assertSame( 'boolean', $arg['type'] );
		$this->assertArrayNotHasKey( 'sanitize_callback', $arg );
	}

	/**
	 * Test constrained args automatically get rest_validate_request_arg.
	 *
	 * @dataProvider constraint_provider
	 *
	 * @param array<string, mixed> $props Constraint properties.
	 */
	public function test_constraints_get_validate_callback( array $props ): void {
		$this->assertSame( 'rest_validate_request_arg', Args::integer( $props )['validate_callback'] );
	}

	/**
	 * Constraint property sets that require validation.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public function constraint_provider(): array {
		return [
			'minimum' => [ [ 'minimum' => 1 ] ],
			'maximum' => [ [ 'maximum' => 100 ] ],
			'enum'    => [ [ 'enum' => [ 1, 2 ] ] ],
			'pattern' => [ [ 'pattern' => '^\d+$' ] ],
			'format'  => [ [ 'format' => 'email' ] ],
		];
	}

	/**
	 * Test enum() builds a validated string enum.
	 */
	public function test_enum(): void {
		$arg = Args::enum( [ 'ASC', 'DESC' ], [ 'default' => 'DESC' ] );

		$this->assertSame( 'string', $arg['type'] );
		$this->assertSame( [ 'ASC', 'DESC' ], $arg['enum'] );
		$this->assertSame( 'DESC', $arg['default'] );
		$this->assertSame( 'sanitize_text_field', $arg['sanitize_callback'] );
		$this->assertSame( 'rest_validate_request_arg', $arg['validate_callback'] );
	}

	/**
	 * Test explicit callbacks override the defaults.
	 */
	public function test_explicit_callbacks_win(): void {
		$validate = static fn( $val ) => $val >= 1;
		$arg      = Args::integer(
			[
				'minimum'           => 1,
				'sanitize_callback' => 'intval',
				'validate_callback' => $validate,
			]
		);

		$this->assertSame( 'intval', $arg['sanitize_callback'] );
		$this->assertSame( $validate, $arg['validate_callback'] );
	}

	/**
	 * Test id() produces the standard required route ID arg.
	 */
	public function test_id(): void {
		$this->assertSame(
			[
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
			Args::id()
		);

		$this->assertFalse( Args::id( false )['required'] );
	}
}
