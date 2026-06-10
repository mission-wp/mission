/**
 * Fee and tip calculation utilities shared across blocks.
 */
import { getCurrencyDecimals, roundToCurrency } from '@shared/currencies';

/**
 * Platform fee rate charged to nonprofits when tips are disabled.
 *
 * Used for fee recovery calculation — controls how much the *donor* pays,
 * not how much Mission charges. The actual fee is enforced API-side.
 */
export const PLATFORM_FEE_RATE = 0.03;

/**
 * Default Stripe fixed fee in major units (0.30 = $0.30, €0.30, …).
 */
const DEFAULT_FIXED_FEE_MAJOR = 0.3;

/**
 * Get the default Stripe fixed fee in minor units for a currency.
 *
 * Mirrors TipCalculator::default_fixed_fee(): 0.30 major units scaled by the
 * currency's decimals — 30 for USD, 300 for KWD, 0 for zero-decimal
 * currencies like JPY (which Stripe prices without a fixed fee).
 *
 * @param {string} currencyCode ISO 4217 currency code.
 * @return {number} Fixed fee in minor units.
 */
export function defaultFixedFee( currencyCode = 'USD' ) {
  return Math.round(
    DEFAULT_FIXED_FEE_MAJOR * 10 ** getCurrencyDecimals( currencyCode )
  );
}

/**
 * Get the maximum allowed Stripe fixed fee in minor units for a currency.
 *
 * Mirrors TipCalculator::max_fixed_fee(): 10 major units, with a floor of
 * 1000 minor units so zero-decimal currencies can express realistic fees.
 *
 * @param {string} currencyCode ISO 4217 currency code.
 * @return {number} Maximum fixed fee in minor units.
 */
export function maxFixedFee( currencyCode = 'USD' ) {
  return Math.max( 1000, 10 ** ( getCurrencyDecimals( currencyCode ) + 1 ) );
}

/**
 * Calculate the processing fee in minor units.
 *
 * Covers the Stripe fee on the donation. When a platform rate is provided
 * (flat 3% fee mode), the fee also covers the platform's cut so the
 * nonprofit nets the full donation amount.
 *
 * Solving fee = rate × (donation + fee) + fixed + platformRate × (donation + fee):
 *   fee = ((rate + platformRate) × donation + fixed) / (1 - rate - platformRate)
 *
 * @param {number} donationAmount Donation amount in minor units.
 * @param {number} rate           Stripe fee rate as a decimal (e.g. 0.029).
 * @param {number} fixed          Stripe fixed fee in minor units (e.g. 30).
 * @param {number} platformRate   Platform fee rate as a decimal (e.g. 0.03). Default 0.
 * @param {string} currencyCode   ISO 4217 code, used to keep whole-unit currencies chargeable.
 * @return {number} Fee in minor units.
 */
export function calculateFee(
  donationAmount,
  rate,
  fixed,
  platformRate = 0,
  currencyCode = 'USD'
) {
  const combinedRate = rate + platformRate;
  // Algebraic estimate of fee needed so donor covers all charges.
  const estimate = Math.round(
    ( combinedRate * donationAmount + fixed ) / ( 1 - combinedRate )
  );
  // Correction pass: simulate the actual fee on the resulting charge
  // to eliminate ±1¢ rounding drift from the algebraic formula.
  return roundToCurrency(
    Math.round( ( donationAmount + estimate ) * combinedRate + fixed ),
    currencyCode
  );
}

/**
 * Calculate tip amount in minor units.
 *
 * @param {number} amount       Donation amount in minor units.
 * @param {number} percent      Tip percentage.
 * @param {string} currencyCode ISO 4217 code, used to keep whole-unit currencies chargeable.
 * @return {number} Tip in minor units.
 */
export function calculateTip( amount, percent, currencyCode = 'USD' ) {
  return roundToCurrency(
    Math.round( ( amount * percent ) / 100 ),
    currencyCode
  );
}
