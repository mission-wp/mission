/* eslint-env jest */

/**
 * The formatDate helper from CampaignDetail.
 *
 * Duplicated here so the test doesn't depend on JSX transpilation.
 *
 * @param {string|null} dateStr Date string to format.
 * @return {string} Formatted date or dash.
 */
function formatDate( dateStr ) {
	if ( ! dateStr || dateStr.startsWith( '0000' ) ) {
		return '—';
	}
	const normalized = dateStr.includes( 'T' )
		? dateStr
		: dateStr.replace( ' ', 'T' );
	return new Date( normalized ).toLocaleDateString();
}

describe( 'formatDate', () => {
	it( 'formats a MySQL datetime string', () => {
		const result = formatDate( '2026-01-15 12:00:00' );
		expect( result ).not.toBe( 'Invalid Date' );
	} );

	it( 'formats an ISO 8601 string', () => {
		const result = formatDate( '2026-01-15T12:00:00' );
		expect( result ).not.toBe( 'Invalid Date' );
	} );

	it( 'returns dash for null', () => {
		expect( formatDate( null ) ).toBe( '—' );
	} );

	it( 'returns dash for empty string', () => {
		expect( formatDate( '' ) ).toBe( '—' );
	} );

	it( 'returns dash for WordPress zero date', () => {
		expect( formatDate( '0000-00-00 00:00:00' ) ).toBe( '—' );
	} );

	it( 'formats a date-only string (YYYY-MM-DD)', () => {
		const result = formatDate( '2026-06-15' );
		expect( result ).not.toBe( 'Invalid Date' );
		expect( result ).toBeTruthy();
	} );
} );
