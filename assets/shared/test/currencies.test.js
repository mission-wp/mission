/* eslint-env jest */

import { getCurrencyDecimals, minorToMajor, majorToMinor } from '../currencies';

describe( 'getCurrencyDecimals', () => {
  it( 'returns 0 for zero-decimal currencies (JPY, KRW)', () => {
    expect( getCurrencyDecimals( 'JPY' ) ).toBe( 0 );
    expect( getCurrencyDecimals( 'KRW' ) ).toBe( 0 );
  } );

  it( 'returns 2 for standard currencies (USD, EUR)', () => {
    expect( getCurrencyDecimals( 'USD' ) ).toBe( 2 );
    expect( getCurrencyDecimals( 'EUR' ) ).toBe( 2 );
  } );

  it( 'returns 3 for three-decimal currencies (BHD, JOD)', () => {
    expect( getCurrencyDecimals( 'BHD' ) ).toBe( 3 );
    expect( getCurrencyDecimals( 'JOD' ) ).toBe( 3 );
  } );
} );

describe( 'minorToMajor', () => {
  it( 'converts 1000 USD minor units to 10', () => {
    expect( minorToMajor( 1000, 'USD' ) ).toBe( 10 );
  } );

  it( 'returns the same value for zero-decimal currencies (JPY)', () => {
    expect( minorToMajor( 1000, 'JPY' ) ).toBe( 1000 );
  } );

  it( 'converts 1000 BHD minor units to 1', () => {
    expect( minorToMajor( 1000, 'BHD' ) ).toBe( 1 );
  } );
} );

describe( 'majorToMinor', () => {
  it( 'converts 10.00 USD to 1000 minor units', () => {
    expect( majorToMinor( 10.0, 'USD' ) ).toBe( 1000 );
  } );

  it( 'returns the same value for zero-decimal currencies (JPY)', () => {
    expect( majorToMinor( 1000, 'JPY' ) ).toBe( 1000 );
  } );

  it( 'converts 1.000 BHD to 1000 minor units', () => {
    expect( majorToMinor( 1.0, 'BHD' ) ).toBe( 1000 );
  } );

  it( 'rounds to avoid float errors (19.99 → 1999)', () => {
    expect( majorToMinor( 19.99, 'USD' ) ).toBe( 1999 );
  } );
} );
