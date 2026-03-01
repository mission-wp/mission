/**
 * Currency utilities.
 *
 * Reads the site currency from the localized `missionAdmin.currency` value
 * (defaults to USD) and provides formatting helpers.
 */

/**
 * Get the configured currency code.
 *
 * @return {string} Uppercase ISO 4217 code (e.g. "USD").
 */
export function getCurrencyCode() {
	return window.missionAdmin?.currency || 'USD';
}

/**
 * Get the currency symbol for the configured currency.
 *
 * @return {string} Symbol like "$", "€", "£", "¥".
 */
export function getCurrencySymbol() {
	const code = getCurrencyCode();
	// Format zero in the currency, then strip digits and whitespace to isolate the symbol.
	const formatted = new Intl.NumberFormat( undefined, {
		style: 'currency',
		currency: code,
		maximumFractionDigits: 0,
	} ).format( 0 );

	return formatted.replace( /[\d.,\s]/g, '' ).trim();
}

/**
 * Format a value in minor units (cents) as a currency string.
 *
 * @param {number} minorUnits Amount in minor units (e.g. 4500 = $45.00).
 * @return {string} Formatted string like "$45.00" or "¥4500".
 */
export function formatAmount( minorUnits ) {
	const code = getCurrencyCode();
	const isZeroDecimal = [ 'JPY' ].includes( code );
	const value = isZeroDecimal ? minorUnits : minorUnits / 100;

	return new Intl.NumberFormat( undefined, {
		style: 'currency',
		currency: code,
		minimumFractionDigits: isZeroDecimal ? 0 : 2,
		maximumFractionDigits: isZeroDecimal ? 0 : 2,
	} ).format( value );
}
