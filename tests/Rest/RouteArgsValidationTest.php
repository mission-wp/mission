<?php
/**
 * Guard test: constrained REST args must declare a validate_callback.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest;

use WP_UnitTestCase;

/**
 * Asserts that every registered route arg with a schema constraint
 * (enum, minimum, maximum, pattern, format) has a validate_callback.
 *
 * Without one, register_rest_route() silently ignores those constraints,
 * so invalid values pass straight through to handlers. This test makes
 * that bug class impossible to reintroduce regardless of how args are
 * declared.
 */
class RouteArgsValidationTest extends WP_UnitTestCase {

	/**
	 * Schema keys that are dead weight without a validate_callback.
	 *
	 * @var string[]
	 */
	private const CONSTRAINT_KEYS = [ 'enum', 'minimum', 'maximum', 'pattern', 'format' ];

	/**
	 * Test every constrained arg on every plugin route declares a validate_callback.
	 */
	public function test_constrained_args_have_validate_callback(): void {
		global $wp_rest_server;
		$server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		$violations = [];
		$checked    = 0;

		foreach ( $server->get_routes( 'mission-donation-platform/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( empty( $handler['args'] ) || ! is_array( $handler['args'] ) ) {
					continue;
				}

				foreach ( $handler['args'] as $name => $arg ) {
					if ( ! is_array( $arg ) || ! array_intersect_key( $arg, array_flip( self::CONSTRAINT_KEYS ) ) ) {
						continue;
					}

					++$checked;

					if ( empty( $arg['validate_callback'] ) ) {
						$methods      = implode( ',', array_keys( array_filter( $handler['methods'] ?? [] ) ) );
						$violations[] = "{$methods} {$route} arg '{$name}'";
					}
				}
			}
		}

		$this->assertGreaterThan( 0, $checked, 'No constrained args were found — route registration may be broken.' );
		$this->assertSame(
			[],
			$violations,
			"Constrained args missing a validate_callback (constraints are silently ignored without one):\n" . implode( "\n", $violations )
		);
	}
}
