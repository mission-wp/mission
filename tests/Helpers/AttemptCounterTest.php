<?php
/**
 * Tests for the AttemptCounter helper.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Helpers;

use MissionDP\Helpers\AttemptCounter;
use WP_UnitTestCase;

/**
 * AttemptCounter test class.
 *
 * The counter stores rows in the options table via raw $wpdb; tests read and
 * seed those rows directly to exercise the atomic-claim semantics.
 */
class AttemptCounterTest extends WP_UnitTestCase {

	/**
	 * Read a raw counter option row.
	 *
	 * @param string $name Option name.
	 * @return string|null Option value, or null when the row is absent.
	 */
	private function row( string $name ): ?string {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
		);
	}

	/**
	 * Test exactly $limit claims succeed, then further claims are refused.
	 */
	public function test_claims_up_to_limit_then_refuses(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( AttemptCounter::claim( 'cap', 3, 60 ), "Claim {$i} should succeed." );
		}

		$this->assertFalse( AttemptCounter::claim( 'cap', 3, 60 ) );
		$this->assertFalse( AttemptCounter::claim( 'cap', 3, 60 ) );
	}

	/**
	 * Test the atomic gate refuses when the count row is already at the cap.
	 *
	 * Seeds the rows directly, standing in for the state a concurrent sibling
	 * request would leave behind.
	 */
	public function test_claim_refused_on_seeded_row_at_cap(): void {
		global $wpdb;

		$wpdb->insert( $wpdb->options, [ 'option_name' => 'missiondp_attempts_timeout_seeded', 'option_value' => (string) ( time() + 60 ), 'autoload' => 'no' ] );
		$wpdb->insert( $wpdb->options, [ 'option_name' => 'missiondp_attempts_seeded', 'option_value' => '5', 'autoload' => 'no' ] );

		$this->assertFalse( AttemptCounter::claim( 'seeded', 5, 60 ) );
		$this->assertSame( '5', $this->row( 'missiondp_attempts_seeded' ) );
	}

	/**
	 * Test a refused claim leaves the window untouched (no lockout extension).
	 */
	public function test_refused_claim_does_not_extend_window(): void {
		AttemptCounter::claim( 'window', 1, 60 );
		$before = $this->row( 'missiondp_attempts_timeout_window' );

		$this->assertFalse( AttemptCounter::claim( 'window', 1, 60 ) );
		$this->assertSame( $before, $this->row( 'missiondp_attempts_timeout_window' ) );
	}

	/**
	 * Test a zero or negative limit refuses without creating rows.
	 */
	public function test_zero_limit_refuses_without_rows(): void {
		$this->assertFalse( AttemptCounter::claim( 'zero', 0, 60 ) );
		$this->assertNull( $this->row( 'missiondp_attempts_zero' ) );
		$this->assertNull( $this->row( 'missiondp_attempts_timeout_zero' ) );
	}

	/**
	 * Test reset() clears the counter so claims start over.
	 */
	public function test_reset_clears_counter(): void {
		AttemptCounter::claim( 'fresh', 1, 60 );
		$this->assertFalse( AttemptCounter::claim( 'fresh', 1, 60 ) );

		AttemptCounter::reset( 'fresh' );

		$this->assertNull( $this->row( 'missiondp_attempts_fresh' ) );
		$this->assertTrue( AttemptCounter::claim( 'fresh', 1, 60 ) );
	}

	/**
	 * Test an expired window restarts the count on the next claim.
	 */
	public function test_expired_window_restarts_count(): void {
		global $wpdb;

		AttemptCounter::claim( 'stale', 2, 60 );
		AttemptCounter::claim( 'stale', 2, 60 );
		$this->assertFalse( AttemptCounter::claim( 'stale', 2, 60 ) );

		// Age the window past its expiry.
		$wpdb->update(
			$wpdb->options,
			[ 'option_value' => (string) ( time() - 1 ) ],
			[ 'option_name' => 'missiondp_attempts_timeout_stale' ]
		);

		$this->assertTrue( AttemptCounter::claim( 'stale', 2, 60 ) );
		$this->assertSame( '1', $this->row( 'missiondp_attempts_stale' ) );
		$this->assertGreaterThan( time(), (int) $this->row( 'missiondp_attempts_timeout_stale' ) );
	}

	/**
	 * Test purge_expired() removes spent counters and keeps active ones.
	 */
	public function test_purge_expired_removes_only_expired_counters(): void {
		global $wpdb;

		AttemptCounter::claim( 'old', 5, 60 );
		AttemptCounter::claim( 'active', 5, 60 );

		$wpdb->update(
			$wpdb->options,
			[ 'option_value' => (string) ( time() - 1 ) ],
			[ 'option_name' => 'missiondp_attempts_timeout_old' ]
		);

		AttemptCounter::purge_expired();

		$this->assertNull( $this->row( 'missiondp_attempts_old' ) );
		$this->assertNull( $this->row( 'missiondp_attempts_timeout_old' ) );
		$this->assertSame( '1', $this->row( 'missiondp_attempts_active' ) );
		$this->assertNotNull( $this->row( 'missiondp_attempts_timeout_active' ) );
	}
}
