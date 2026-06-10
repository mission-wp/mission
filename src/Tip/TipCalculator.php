<?php
/**
 * Centralized tip calculation utilities.
 *
 * Mission absorbs the incremental Stripe processing fee caused by adding a tip
 * to the charge, so nonprofits never pay higher fees because of our tip. These
 * methods handle that fee absorption math in one place.
 *
 * @package MissionDP
 */

namespace MissionDP\Tip;

use MissionDP\Currency\Currency;
use MissionDP\Models\Transaction;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Tip calculator class.
 */
class TipCalculator {

	/**
	 * Default Stripe fixed fee in major units (0.30 = $0.30, €0.30, …).
	 */
	private const DEFAULT_FIXED_FEE_MAJOR = 0.30;

	/**
	 * Get the default Stripe fixed fee in minor units for a currency.
	 *
	 * 0.30 major units scaled by the currency's decimals: 30 for USD,
	 * 300 for KWD, 0 for zero-decimal currencies like JPY (which Stripe
	 * prices without a fixed fee).
	 *
	 * @param string $currency ISO 4217 currency code.
	 * @return int Fixed fee in minor units.
	 */
	public static function default_fixed_fee( string $currency ): int {
		return (int) round( self::DEFAULT_FIXED_FEE_MAJOR * 10 ** Currency::get_decimals( $currency ) );
	}

	/**
	 * Get the maximum allowed Stripe fixed fee in minor units for a currency.
	 *
	 * Sanity cap of 10 major units, with a floor of 1000 minor units so
	 * zero-decimal currencies (where one unit is worth very little) can still
	 * express realistic fixed fees.
	 *
	 * @param string $currency ISO 4217 currency code.
	 * @return int Maximum fixed fee in minor units.
	 */
	public static function max_fixed_fee( string $currency ): int {
		return (int) max( 1000, 10 ** ( Currency::get_decimals( $currency ) + 1 ) );
	}

	/**
	 * Absorb the Stripe fee increment caused by the tip.
	 *
	 * Shifts the incremental fee from the tip to the donation so the total
	 * charge stays the same but the nonprofit doesn't pay extra fees.
	 *
	 * @param int    $donation_amount Donation amount in minor units (modified by reference).
	 * @param int    $tip_amount      Tip amount in minor units (modified by reference).
	 * @param float  $fee_rate        Stripe fee rate as a decimal (e.g. 0.029).
	 * @param int    $fee_fixed       Stripe fixed fee in minor units (e.g. 30).
	 * @param string $currency        ISO 4217 currency code of the amounts.
	 * @return void
	 */
	public static function absorb_fee( int &$donation_amount, int &$tip_amount, float $fee_rate, int $fee_fixed, string $currency = 'USD' ): void {
		if ( $tip_amount <= 0 ) {
			return;
		}

		$unit            = Currency::rounding_unit( $currency );
		$total           = $donation_amount + $tip_amount;
		$fee_with_tip    = (int) round( $total * $fee_rate + $fee_fixed );
		$fee_without_tip = (int) round( $donation_amount * $fee_rate + $fee_fixed );
		$incremental_fee = $fee_with_tip - $fee_without_tip;
		// Keep whole-unit currencies (ISK, UGX) chargeable after the shift.
		$incremental_fee  = (int) ( round( $incremental_fee / $unit ) * $unit );
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
		$unit                     = Currency::rounding_unit( $txn->currency );
		$donation_amount          = $txn->amount + $txn->fee_amount;
		$fee_with                 = (int) round( $txn->total_amount * $fee_rate + $fee_fixed );
		$fee_without              = (int) round( $donation_amount * $fee_rate + $fee_fixed );
		$incremental_fee          = (int) ( round( ( $fee_with - $fee_without ) / $unit ) * $unit );

		return max( 0, $txn->tip_amount - $incremental_fee );
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

		$default_fixed = self::default_fixed_fee( (string) $settings->get( 'currency', 'USD' ) );
		$fee_fixed     = null !== $fixed && '' !== $fixed
			? (int) $fixed
			: (int) $settings->get( 'stripe_fee_fixed', $default_fixed );

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
		$fee_fixed = (int) $settings->get(
			'stripe_fee_fixed',
			self::default_fixed_fee( (string) $settings->get( 'currency', 'USD' ) )
		);

		return [ $fee_rate, $fee_fixed ];
	}
}
