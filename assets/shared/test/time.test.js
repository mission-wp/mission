/* eslint-env jest */

import { timeAgo } from '../time';

describe( 'timeAgo', () => {
  beforeEach( () => {
    // Fix "now" to 2026-03-13T12:00:00Z.
    jest
      .spyOn( Date, 'now' )
      .mockReturnValue( new Date( '2026-03-13T12:00:00Z' ).getTime() );
  } );

  afterEach( () => {
    jest.restoreAllMocks();
  } );

  it( 'returns "just now" for less than 1 minute ago', () => {
    expect( timeAgo( '2026-03-13T12:00:00' ) ).toBe( 'just now' );
    expect( timeAgo( '2026-03-13T11:59:30' ) ).toBe( 'just now' );
  } );

  it( 'returns "Xm ago" for minutes', () => {
    expect( timeAgo( '2026-03-13T11:55:00' ) ).toBe( '5m ago' );
    expect( timeAgo( '2026-03-13T11:30:00' ) ).toBe( '30m ago' );
  } );

  it( 'returns "Xh ago" for hours', () => {
    expect( timeAgo( '2026-03-13T10:00:00' ) ).toBe( '2h ago' );
    expect( timeAgo( '2026-03-13T06:00:00' ) ).toBe( '6h ago' );
  } );

  it( 'returns "X days ago" for days, with singular form', () => {
    expect( timeAgo( '2026-03-12T12:00:00' ) ).toBe( '1 day ago' );
    expect( timeAgo( '2026-03-10T12:00:00' ) ).toBe( '3 days ago' );
  } );

  it( 'transitions from "just now" to "1m ago" at exactly 60 seconds', () => {
    expect( timeAgo( '2026-03-13T11:59:00' ) ).toBe( '1m ago' );
  } );

  it( 'transitions from minutes to "1h ago" at exactly 60 minutes', () => {
    expect( timeAgo( '2026-03-13T11:00:00' ) ).toBe( '1h ago' );
  } );

  it( 'transitions from hours to "1 day ago" at exactly 24 hours', () => {
    expect( timeAgo( '2026-03-12T12:00:00' ) ).toBe( '1 day ago' );
  } );
} );
