<?php
/**
 * Tests for the DashboardStrings and DashboardLabels script-facing labels.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\DonorDashboard;

use MissionDP\DonorDashboard\DashboardLabels;
use MissionDP\DonorDashboard\DashboardStrings;
use WP_UnitTestCase;

/**
 * DashboardStrings test class.
 */
class DashboardStringsTest extends WP_UnitTestCase {

	/**
	 * Every entry is a non-empty string, or a map of non-empty strings.
	 */
	public function test_all_strings_are_filled(): void {
		$strings = DashboardStrings::all();

		$this->assertNotEmpty( $strings );

		foreach ( $strings as $key => $value ) {
			foreach ( (array) $value as $text ) {
				$this->assertIsString( $text, "{$key} should hold strings." );
				$this->assertNotSame( '', $text, "{$key} should not be empty." );
			}
		}
	}

	/**
	 * The scripts fill these placeholders, so translations must keep them.
	 */
	public function test_placeholder_strings_keep_their_placeholders(): void {
		$strings = DashboardStrings::all();

		$this->assertStringContainsString( '%1$s', $strings['cardEndingIn'] );
		$this->assertStringContainsString( '%2$s', $strings['cardEndingIn'] );
		$this->assertStringContainsString( '%s', $strings['cardExpires'] );
		$this->assertStringContainsString( '%s', $strings['updateTo'] );
	}

	/**
	 * Status labels cover every transaction status the history can show.
	 */
	public function test_statuses_cover_transaction_statuses(): void {
		$statuses = DashboardStrings::all()['statuses'];

		foreach ( [ 'pending', 'completed', 'refunded', 'cancelled', 'failed' ] as $status ) {
			$this->assertArrayHasKey( $status, $statuses );
		}
	}

	/**
	 * Strings can be changed through the filter.
	 */
	public function test_strings_are_filterable(): void {
		$filter = static function ( array $strings ): array {
			$strings['saved'] = 'All set';
			return $strings;
		};

		add_filter( 'mission_donor_dashboard_strings', $filter );
		$strings = DashboardStrings::all();
		remove_filter( 'mission_donor_dashboard_strings', $filter );

		$this->assertSame( 'All set', $strings['saved'] );
	}

	/**
	 * Transaction status labels are readable, with a fallback for unknown statuses.
	 */
	public function test_transaction_status_label(): void {
		$this->assertSame( 'Completed', DashboardLabels::transaction_status_label( 'completed' ) );
		$this->assertSame( 'Disputed', DashboardLabels::transaction_status_label( 'disputed' ) );
	}

	/**
	 * Saved cards read "Brand ending in 1234", or "Card" when the brand is unknown.
	 */
	public function test_payment_method_label(): void {
		$this->assertSame( 'Visa ending in 4242', DashboardLabels::payment_method_label( 'visa', '4242' ) );
		$this->assertSame( 'Card ending in 4242', DashboardLabels::payment_method_label( '', '4242' ) );
	}
}
