<?php
/**
 * Tests for the CollectionParams factory.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest;

use MissionDP\Rest\CollectionParams;
use WP_UnitTestCase;

/**
 * CollectionParams test class.
 */
class CollectionParamsTest extends WP_UnitTestCase {

	/**
	 * Test the standard admin-collection shape.
	 */
	public function test_standard_shape(): void {
		$params = CollectionParams::base(
			orderby: [ 'date_created', 'amount' ],
			default_orderby: 'date_created'
		);

		$this->assertSame( [ 'page', 'per_page', 'orderby', 'order', 'search' ], array_keys( $params ) );

		$this->assertSame( 1, $params['page']['default'] );
		$this->assertSame( 1, $params['page']['minimum'] );
		$this->assertSame( 25, $params['per_page']['default'] );
		$this->assertSame( 100, $params['per_page']['maximum'] );
		$this->assertSame( [ 'date_created', 'amount' ], $params['orderby']['enum'] );
		$this->assertSame( 'date_created', $params['orderby']['default'] );
		$this->assertSame( 'DESC', $params['order']['default'] );

		// Every constrained param must be validated.
		foreach ( [ 'page', 'per_page', 'orderby', 'order' ] as $key ) {
			$this->assertSame( 'rest_validate_request_arg', $params[ $key ]['validate_callback'], $key );
		}
	}

	/**
	 * Test orderby/order are omitted when no orderby values are allowed.
	 */
	public function test_no_orderby(): void {
		$params = CollectionParams::base();

		$this->assertArrayNotHasKey( 'orderby', $params );
		$this->assertArrayNotHasKey( 'order', $params );
	}

	/**
	 * Test per_page defaults and search can be customized for public collections.
	 */
	public function test_public_variant(): void {
		$params = CollectionParams::base( per_page: 12, max_per_page: 50, search: false );

		$this->assertSame( 12, $params['per_page']['default'] );
		$this->assertSame( 50, $params['per_page']['maximum'] );
		$this->assertArrayNotHasKey( 'search', $params );
	}
}
