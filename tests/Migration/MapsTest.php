<?php
/**
 * Tests for the GiveWP mapping helpers and AmountConverter.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Migration;

use MissionDP\Migration\AmountConverter;
use MissionDP\Migration\Migrators\GiveWP\Maps\FrequencyMap;
use MissionDP\Migration\Migrators\GiveWP\Maps\GatewayMap;
use MissionDP\Migration\Migrators\GiveWP\Maps\StatusMap;
use WP_UnitTestCase;

/**
 * Pure mapping tables: every branch, including the lossy ones.
 */
class MapsTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// StatusMap
	// -------------------------------------------------------------------------

	/**
	 * Payment statuses map onto Mission transaction statuses.
	 */
	public function test_payment_status_map(): void {
		$this->assertSame( 'completed', StatusMap::payment_status( 'publish' ) );
		$this->assertSame( 'completed', StatusMap::payment_status( 'give_subscription' ) );
		$this->assertSame( 'pending', StatusMap::payment_status( 'pending' ) );
		$this->assertSame( 'pending', StatusMap::payment_status( 'processing' ) );
		$this->assertSame( 'pending', StatusMap::payment_status( 'preapproval' ) );
		$this->assertSame( 'refunded', StatusMap::payment_status( 'refunded' ) );
		$this->assertSame( 'cancelled', StatusMap::payment_status( 'cancelled' ) );
		$this->assertSame( 'failed', StatusMap::payment_status( 'failed' ) );
		$this->assertSame( 'failed', StatusMap::payment_status( 'abandoned' ) );
		$this->assertSame( 'failed', StatusMap::payment_status( 'revoked' ) );
		$this->assertSame( 'failed', StatusMap::payment_status( 'something_new' ) );
	}

	/**
	 * Subscription statuses map onto Mission subscription statuses; GiveWP's
	 * terminal completed/expired collapse to cancelled.
	 */
	public function test_subscription_status_map(): void {
		$this->assertSame( 'active', StatusMap::subscription_status( 'active' ) );
		$this->assertSame( 'pending', StatusMap::subscription_status( 'pending' ) );
		$this->assertSame( 'past_due', StatusMap::subscription_status( 'failing' ) );
		$this->assertSame( 'paused', StatusMap::subscription_status( 'paused' ) );
		$this->assertSame( 'paused', StatusMap::subscription_status( 'suspended' ) );
		$this->assertSame( 'cancelled', StatusMap::subscription_status( 'cancelled' ) );
		$this->assertSame( 'cancelled', StatusMap::subscription_status( 'completed' ) );
		$this->assertSame( 'cancelled', StatusMap::subscription_status( 'expired' ) );
	}

	/**
	 * Campaign goal types map onto Mission goal types.
	 */
	public function test_campaign_goal_type_map(): void {
		$this->assertSame( 'amount', StatusMap::campaign_goal_type( 'amount' ) );
		$this->assertSame( 'amount', StatusMap::campaign_goal_type( 'amountFromSubscriptions' ) );
		$this->assertSame( 'donations', StatusMap::campaign_goal_type( 'donations' ) );
		$this->assertSame( 'donations', StatusMap::campaign_goal_type( 'subscriptions' ) );
		$this->assertSame( 'donors', StatusMap::campaign_goal_type( 'donors' ) );
		$this->assertSame( 'donors', StatusMap::campaign_goal_type( 'donorsFromSubscriptions' ) );
	}

	// -------------------------------------------------------------------------
	// FrequencyMap
	// -------------------------------------------------------------------------

	/**
	 * Exact cadences map losslessly.
	 */
	public function test_frequency_exact_mappings(): void {
		$this->assertSame(
			[
				'frequency' => 'weekly',
				'lossy'     => false,
			],
			FrequencyMap::map( 'week', 1 )
		);
		$this->assertSame(
			[
				'frequency' => 'monthly',
				'lossy'     => false,
			],
			FrequencyMap::map( 'month', 1 )
		);
		$this->assertSame(
			[
				'frequency' => 'quarterly',
				'lossy'     => false,
			],
			FrequencyMap::map( 'quarter', 1 )
		);
		$this->assertSame(
			[
				'frequency' => 'quarterly',
				'lossy'     => false,
			],
			FrequencyMap::map( 'month', 3 )
		);
		$this->assertSame(
			[
				'frequency' => 'annually',
				'lossy'     => false,
			],
			FrequencyMap::map( 'year', 1 )
		);
	}

	/**
	 * Unsupported cadences fall back to monthly and are flagged lossy.
	 */
	public function test_frequency_lossy_mappings(): void {
		foreach ( [ [ 'day', 1 ], [ 'week', 2 ], [ 'month', 6 ], [ 'year', 2 ], [ 'half_year', 1 ] ] as $pair ) {
			$result = FrequencyMap::map( $pair[0], $pair[1] );
			$this->assertSame( 'monthly', $result['frequency'], "{$pair[0]} x{$pair[1]}" );
			$this->assertTrue( $result['lossy'], "{$pair[0]} x{$pair[1]} should be lossy" );
		}
	}

	// -------------------------------------------------------------------------
	// GatewayMap
	// -------------------------------------------------------------------------

	/**
	 * Gateway slugs normalize; unknown slugs pass through.
	 */
	public function test_gateway_map(): void {
		$this->assertSame( 'stripe', GatewayMap::map( 'stripe' ) );
		$this->assertSame( 'stripe', GatewayMap::map( 'stripe_checkout' ) );
		$this->assertSame( 'stripe', GatewayMap::map( 'stripe_payment_element' ) );
		$this->assertSame( 'stripe', GatewayMap::map( 'give-stripe' ) );
		$this->assertSame( 'paypal', GatewayMap::map( 'paypal' ) );
		$this->assertSame( 'paypal', GatewayMap::map( 'paypal-commerce' ) );
		$this->assertSame( 'manual', GatewayMap::map( 'manual' ) );
		$this->assertSame( 'manual', GatewayMap::map( 'offline' ) );
		$this->assertSame( 'manual', GatewayMap::map( 'test-gateway' ) );
		$this->assertSame( 'manual', GatewayMap::map( '' ) );
		$this->assertSame( 'razorpay', GatewayMap::map( 'razorpay' ) );
	}

	// -------------------------------------------------------------------------
	// AmountConverter
	// -------------------------------------------------------------------------

	/**
	 * Decimal strings convert to currency-aware minor units.
	 */
	public function test_amount_converter(): void {
		$this->assertSame( 2500, AmountConverter::to_minor_units( '25.000000', 'USD' ) );
		$this->assertSame( 2500, AmountConverter::to_minor_units( '25.00', 'usd' ) );
		$this->assertSame( 1999, AmountConverter::to_minor_units( '19.99', 'EUR' ) );
		$this->assertSame( 500, AmountConverter::to_minor_units( '500', 'JPY' ) );
		$this->assertSame( 0, AmountConverter::to_minor_units( '', 'USD' ) );
		$this->assertSame( 0, AmountConverter::to_minor_units( 'abc', 'USD' ) );
		$this->assertSame( 1050, AmountConverter::to_minor_units( '$10.50', 'USD' ) );
	}
}
