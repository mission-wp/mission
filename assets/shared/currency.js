/**
 * Currency utilities.
 *
 * Reads the site currency from the localized `missionAdmin.currency` value
 * (defaults to USD) and provides formatting helpers.
 */
import { getCurrencyDecimals, minorToMajor } from '@shared/currencies';

/**
 * Get the configured currency code.
 *
 * @return {string} Uppercase ISO 4217 code (e.g. "USD").
 */
export function getCurrencyCode() {
  return window.missionAdmin?.currency || 'USD';
}

/**
 * Get the currency symbol for a given currency code (or the site default).
 *
 * @param {string=} currencyCode Optional ISO 4217 code (e.g. "EUR"). Defaults to site currency.
 * @return {string} Symbol like "$", "€", "£", "¥".
 */
export function getCurrencySymbol( currencyCode ) {
  const code = currencyCode?.toUpperCase() || getCurrencyCode();

  try {
    const formatted = new Intl.NumberFormat( undefined, {
      style: 'currency',
      currency: code,
      maximumFractionDigits: 0,
    } ).format( 0 );

    return formatted.replace( /[\d.,\s]/g, '' ).trim();
  } catch {
    return '$';
  }
}

/**
 * Format a value in minor units (cents) as a currency string.
 *
 * @param {number}  minorUnits   Amount in minor units (e.g. 4500 = $45.00).
 * @param {string=} currencyCode Optional ISO 4217 code override (e.g. "EUR").
 * @return {string} Formatted string like "$45.00" or "¥4500".
 */
export function formatAmount( minorUnits, currencyCode ) {
  const code = currencyCode?.toUpperCase() || getCurrencyCode();
  const decimals = getCurrencyDecimals( code );
  const value = minorToMajor( minorUnits, code );

  return new Intl.NumberFormat( undefined, {
    style: 'currency',
    currency: code,
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  } ).format( value );
}
