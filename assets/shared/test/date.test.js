/* eslint-env jest */

import {
  formatDate,
  formatDateTime,
  formatUtcDate,
  formatDateOnly,
} from '../date';

describe( 'formatDate', () => {
  it( 'returns em-dash for null, empty, and zero dates', () => {
    expect( formatDate( null ) ).toBe( '\u2014' );
    expect( formatDate( '' ) ).toBe( '\u2014' );
    expect( formatDate( '0000-00-00 00:00:00' ) ).toBe( '\u2014' );
  } );

  it( 'returns a time element for a valid MySQL datetime', () => {
    const result = formatDate( '2026-01-15 12:00:00' );
    expect( result.type ).toBe( 'time' );
    expect( result.props.dateTime ).toBeTruthy();
    expect( () => new Date( result.props.dateTime ) ).not.toThrow();
  } );
} );

describe( 'formatDateTime', () => {
  it( 'returns em-dash for null, empty, and zero dates', () => {
    expect( formatDateTime( null ) ).toBe( '\u2014' );
    expect( formatDateTime( '' ) ).toBe( '\u2014' );
    expect( formatDateTime( '0000-00-00 00:00:00' ) ).toBe( '\u2014' );
  } );

  it( 'includes "at" in the display text', () => {
    const result = formatDateTime( '2026-01-15 14:30:00' );
    expect( result.type ).toBe( 'time' );
    expect( result.props.children ).toContain( 'at' );
  } );
} );

describe( 'formatUtcDate', () => {
  it( 'handles UTC string without Z suffix', () => {
    const result = formatUtcDate( '2026-03-10 08:00:00' );
    expect( result.type ).toBe( 'time' );
    expect( result.props.dateTime ).toMatch( /^\d{4}-\d{2}-\d{2}T/ );
  } );
} );

describe( 'formatDateOnly', () => {
  it( 'uses date-only format in title (no time components)', () => {
    const result = formatDateOnly( '2026-06-15' );
    expect( result.type ).toBe( 'time' );
    // FULL_DATE_ONLY format has no hour/minute/second, so no ":" in title
    expect( result.props.title ).not.toMatch( /\d+:\d+/ );
  } );
} );
