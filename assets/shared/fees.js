/**
 * Fee and tip calculation utilities shared across blocks.
 */

/**
 * Platform fee rate charged to nonprofits when tips are disabled.
 *
 * Used for fee recovery calculation — controls how much the *donor* pays,
 * not how much Mission charges. The actual fee is enforced API-side.
 */
export const PLATFORM_FEE_RATE = 0.03;

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
 * @return {number} Fee in minor units.
 */
export function calculateFee( donationAmount, rate, fixed, platformRate = 0 ) {
  const combinedRate = rate + platformRate;
  // Algebraic estimate of fee needed so donor covers all charges.
  const estimate = Math.round(
    ( combinedRate * donationAmount + fixed ) / ( 1 - combinedRate )
  );
  // Correction pass: simulate the actual fee on the resulting charge
  // to eliminate ±1¢ rounding drift from the algebraic formula.
  return Math.round( ( donationAmount + estimate ) * combinedRate + fixed );
}

/**
 * Calculate tip amount in minor units.
 *
 * @param {number} amount  Donation amount in minor units.
 * @param {number} percent Tip percentage.
 * @return {number} Tip in minor units.
 */
export function calculateTip( amount, percent ) {
  return Math.round( ( amount * percent ) / 100 );
}
