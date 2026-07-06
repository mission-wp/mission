<?php
/**
 * Tests for the RateLimitTrait.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Traits;

use MissionDP\Rest\Traits\RateLimitTrait;
use WP_Error;
use WP_UnitTestCase;

/**
 * RateLimitTrait test class.
 */
class RateLimitTraitTest extends WP_UnitTestCase {

	/**
	 * Original REMOTE_ADDR to restore.
	 *
	 * @var string|null
	 */
	private ?string $remote_addr_backup = null;

	/**
	 * Back up REMOTE_ADDR.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->remote_addr_backup = $_SERVER['REMOTE_ADDR'] ?? null;
	}

	/**
	 * Restore REMOTE_ADDR.
	 */
	public function tear_down(): void {
		if ( null === $this->remote_addr_backup ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr_backup;
		}
		parent::tear_down();
	}

	/**
	 * Build an object exposing the trait's private check_rate_limit().
	 *
	 * @return object
	 */
	private function limiter(): object {
		return new class() {
			use RateLimitTrait;

			/**
			 * Public wrapper for the private trait method.
			 *
			 * @param string $action Action identifier.
			 * @param int    $limit  Maximum attempts.
			 * @param int    $window Window in seconds.
			 * @return WP_Error|null
			 */
			public function check( string $action, int $limit, int $window ): ?\WP_Error {
				return $this->check_rate_limit( $action, $limit, $window );
			}
		};
	}

	/**
	 * Test the limit allows exactly $limit attempts, then returns a 429 error.
	 */
	public function test_allows_up_to_limit_then_rejects(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$limiter                = $this->limiter();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertNull( $limiter->check( 'test_action', 3, 300 ), "Attempt {$i} should pass." );
		}

		$error = $limiter->check( 'test_action', 3, 300 );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'rate_limited', $error->get_error_code() );
		$this->assertSame( 429, $error->get_error_data()['status'] );

		// Still rejected on the next attempt.
		$this->assertInstanceOf( WP_Error::class, $limiter->check( 'test_action', 3, 300 ) );
	}

	/**
	 * Test limits are tracked per IP address.
	 */
	public function test_limits_are_per_ip(): void {
		$limiter = $this->limiter();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		$this->assertNull( $limiter->check( 'per_ip', 1, 300 ) );
		$this->assertInstanceOf( WP_Error::class, $limiter->check( 'per_ip', 1, 300 ) );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.2';
		$this->assertNull( $limiter->check( 'per_ip', 1, 300 ) );
	}

	/**
	 * Test limits are tracked per action for the same IP.
	 */
	public function test_limits_are_per_action(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.3';
		$limiter                = $this->limiter();

		$this->assertNull( $limiter->check( 'action_a', 1, 300 ) );
		$this->assertInstanceOf( WP_Error::class, $limiter->check( 'action_a', 1, 300 ) );
		$this->assertNull( $limiter->check( 'action_b', 1, 300 ) );
	}

	/**
	 * Test the mission_rate_limit filter can raise the cap.
	 */
	public function test_limit_filter_is_applied(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.4';
		$limiter                = $this->limiter();

		add_filter( 'mission_rate_limit', fn() => 2 );

		$this->assertNull( $limiter->check( 'filtered', 1, 300 ) );
		$this->assertNull( $limiter->check( 'filtered', 1, 300 ) );
		$this->assertInstanceOf( WP_Error::class, $limiter->check( 'filtered', 1, 300 ) );
	}
}
