<?php
/**
 * Centralized tip calculation utilities.
 *
 * Mission absorbs the incremental Stripe processing fee caused by adding a tip
 * to the charge, so nonprofits never pay higher fees because of our tip. These
 * methods handle that fee absorption math in one place.
 *
 * @package Mission
 */

namespace Mission\Tip;

use Mission\Models\Transaction;
use Mission\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Tip calculator class.
 */
class TipCalculator {

	/**
	 * Absorb the Stripe fee increment caused by the tip.
	 *
	 * Shifts the incremental fee from the tip to the donation so the total
	 * charge stays the same but the nonprofit doesn't pay extra fees.
	 *
	 * @param int   $donation_amount Donation amount in minor units (modified by reference).
	 * @param int   $tip_amount      Tip amount in minor units (modified by reference).
	 * @param float $fee_rate        Stripe fee rate as a decimal (e.g. 0.029).
	 * @param int   $fee_fixed       Stripe fixed fee in minor units (e.g. 30).
	 * @return void
	 */
	public static function absorb_fee( int &$donation_amount, int &$tip_amount, float $fee_rate, int $fee_fixed ): void {
		if ( $tip_amount <= 0 ) {
			return;
		}

		$total            = $donation_amount + $tip_amount;
		$fee_with_tip     = (int) round( $total * $fee_rate + $fee_fixed );
		$fee_without_tip  = (int) round( $donation_amount * $fee_rate + $fee_fixed );
		$incremental_fee  = $fee_with_tip - $fee_without_tip;
		$tip_amount      -= $incremental_fee;
		$donation_amount += $incremental_fee;
	}

	/**
	 * Calculate the adjusted tip after fee absorption for display purposes.
	 *
	 * This is the inverse of absorb_fee() — given a transaction's stored amounts,
	 * it computes what the tip looks like after the fee was absorbed from it.
	 *
	 * @param Transaction $txn Transaction model.
	 * @return int Adjusted tip in minor units.
	 */
	public static function adjusted_tip( Transaction $txn ): int {
		if ( $txn->tip_amount <= 0 ) {
			return 0;
		}

		[ $fee_rate, $fee_fixed ] = self::get_fee_params( $txn );
		$donation_amount          = $txn->amount + $txn->fee_amount;
		$fee_with                 = (int) round( $txn->total_amount * $fee_rate + $fee_fixed );
		$fee_without              = (int) round( $donation_amount * $fee_rate + $fee_fixed );

		return max( 0, $txn->tip_amount - ( $fee_with - $fee_without ) );
	}

	/**
	 * Get the fee rate and fixed amount for a transaction.
	 *
	 * Uses per-transaction meta if available (stored at payment time),
	 * otherwise falls back to the current global setting.
	 *
	 * @param Transaction $txn Transaction model.
	 * @return array{float, int} [ rate as decimal, fixed in minor units ]
	 */
	public static function get_fee_params( Transaction $txn ): array {
		$settings = new SettingsService();

		$percent = $txn->get_meta( 'stripe_fee_percent' );
		$fixed   = $txn->get_meta( 'stripe_fee_fixed' );

		$fee_rate = null !== $percent && '' !== $percent
			? (float) $percent / 100
			: (float) $settings->get( 'stripe_fee_percent', 2.9 ) / 100;

		$fee_fixed = null !== $fixed && '' !== $fixed
			? (int) $fixed
			: (int) $settings->get( 'stripe_fee_fixed', 30 );

		return [ $fee_rate, $fee_fixed ];
	}

	/**
	 * Get the current fee rate and fixed amount from settings.
	 *
	 * @param SettingsService $settings Settings service instance.
	 * @return array{float, int} [ rate as decimal, fixed in minor units ]
	 */
	public static function get_fee_params_from_settings( SettingsService $settings ): array {
		$fee_rate  = (float) $settings->get( 'stripe_fee_percent', 2.9 ) / 100;
		$fee_fixed = (int) $settings->get( 'stripe_fee_fixed', 30 );

		return [ $fee_rate, $fee_fixed ];
	}
}
