/**
 * Pure utility functions for the donation form.
 *
 * Extracted from view.js so they can be tested in isolation and reused.
 */
import { getCurrencyDecimals, majorToMinor } from '@shared/currencies';
import { calculateTip } from '@shared/fees';

/**
 * Format minor units (cents) as a currency string.
 *
 * @param {number}  minorUnits     Amount in minor units (e.g. 1000 = $10.00).
 * @param {string}  currencyCode   ISO 4217 currency code.
 * @param {boolean} stripZeroCents Drop ".00" when the amount is a whole number.
 * @return {string}                  Formatted currency string.
 */
export function formatCurrency(
  minorUnits,
  currencyCode = 'USD',
  stripZeroCents = false
) {
  const decimals = getCurrencyDecimals( currencyCode );
  const major = decimals === 0 ? minorUnits : minorUnits / 10 ** decimals;
  const fractionDigits =
    stripZeroCents && Number.isInteger( major ) ? 0 : decimals;
  try {
    return new Intl.NumberFormat( undefined, {
      style: 'currency',
      currency: currencyCode,
      minimumFractionDigits: fractionDigits,
      maximumFractionDigits: fractionDigits,
    } ).format( major );
  } catch {
    return `$${ fractionDigits ? major.toFixed( decimals ) : major }`;
  }
}

/**
 * Get the effective donation amount in minor units.
 *
 * @param {Object} ctx Interactivity context.
 * @return {number} Amount in minor units.
 */
export function getEffectiveAmount( ctx ) {
  if ( ctx.isCustomAmount && ctx.customAmountValue ) {
    return (
      majorToMinor(
        parseFloat( ctx.customAmountValue ),
        ctx.settings.currency || 'USD'
      ) || 0
    );
  }
  return ctx.selectedAmount || 0;
}

/**
 * Get the amounts array for a given frequency key.
 *
 * @param {Object} ctx       Interactivity context.
 * @param {string} frequency Frequency key (e.g. 'one_time', 'monthly').
 * @return {number[]} Amounts in minor units.
 */
export function getAmountsForFrequency( ctx, frequency ) {
  return (
    ctx.amountsByFrequency?.[ frequency ] ||
    ctx.amountsByFrequency?.one_time ||
    []
  );
}

/**
 * Reset the selected amount when switching frequency.
 *
 * Uses the per-frequency default if set, otherwise falls back to the first
 * amount in the frequency's list.
 *
 * @param {Object} ctx       Interactivity context.
 * @param {string} frequency Frequency key.
 */
export function resetAmountForFrequency( ctx, frequency ) {
  const amounts = getAmountsForFrequency( ctx, frequency );
  const defaultAmt = ctx.defaultAmounts?.[ frequency ];
  ctx.selectedAmount = defaultAmt ?? amounts[ 0 ] ?? 0;
  ctx.isCustomAmount = false;
  ctx.customAmountValue = '';
}

/**
 * Get the fee rate and fixed amount from the Interactivity context.
 *
 * @param {Object} ctx Interactivity context.
 * @return {{ rate: number, fixed: number }} rate as decimal (e.g. 0.029), fixed in minor units.
 */
export function getFeeParams( ctx ) {
  const percent = ctx.stripeFeePercent ?? 2.9;
  const fixed = ctx.stripeFeeFixed ?? 30;
  return { rate: percent / 100, fixed };
}

// Re-export shared fee/tip calculation utilities.
export { calculateFee, calculateTip } from '@shared/fees';

/**
 * Get tip amount for a given context.
 *
 * Returns the custom tip amount if in custom tip mode,
 * otherwise calculates from the selected percentage.
 *
 * @param {Object} ctx    Interactivity context.
 * @param {number} amount Donation amount in minor units.
 * @return {number} Tip in minor units.
 */
export function getTipAmount( ctx, amount ) {
  if ( ctx.isCustomTip ) {
    return Math.max( 0, ctx.customTipAmount || 0 );
  }
  return calculateTip( amount, ctx.selectedTipPercent );
}

/**
 * Get the step number for the payment step.
 *
 * @param {Object} ctx Interactivity context.
 * @return {number} 2 or 3 depending on whether custom fields exist.
 */
export function getPaymentStep( ctx ) {
  return ctx.hasCustomFields ? 3 : 2;
}

/**
 * Validate required custom fields.
 *
 * @param {Object} ctx Interactivity context.
 * @return {boolean} True if all required fields pass.
 */
export function validateCustomFields( ctx ) {
  const errors = {};
  let valid = true;

  for ( const field of ctx.customFields ) {
    if ( ! field.required ) {
      continue;
    }

    const value = ctx.customFieldValues?.[ field.id ];

    if ( field.type === 'checkbox' ) {
      if ( ! value ) {
        errors[ field.id ] = true;
        valid = false;
      }
    } else if ( field.type === 'multiselect' ) {
      if ( ! Array.isArray( value ) || value.length === 0 ) {
        errors[ field.id ] = true;
        valid = false;
      }
    } else if ( ! value || ( typeof value === 'string' && ! value.trim() ) ) {
      errors[ field.id ] = true;
      valid = false;
    }
  }

  ctx.customFieldErrors = errors;
  return valid;
}
