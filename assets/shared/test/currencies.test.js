/* eslint-env jest */

import {
  getCurrencyDecimals,
  minorToMajor,
  majorToMinor,
  roundingUnit,
  roundToCurrency,
} from '../currencies';

describe( 'getCurrencyDecimals', () => {
  it( 'returns 0 for zero-decimal currencies (JPY, KRW)', () => {
    expect( getCurrencyDecimals( 'JPY' ) ).toBe( 0 );
    expect( getCurrencyDecimals( 'KRW' ) ).toBe( 0 );
  } );

  it( 'returns 2 for standard currencies (USD, EUR)', () => {
    expect( getCurrencyDecimals( 'USD' ) ).toBe( 2 );
    expect( getCurrencyDecimals( 'EUR' ) ).toBe( 2 );
  } );

  it( 'returns 2 for Stripe special cases ISK and UGX', () => {
    // Both are zero-decimal in ISO 4217, but Stripe represents them as
    // two-decimal values (charge 5 UGX = amount 500).
    expect( getCurrencyDecimals( 'ISK' ) ).toBe( 2 );
    expect( getCurrencyDecimals( 'UGX' ) ).toBe( 2 );
  } );

  it( 'returns 3 for three-decimal currencies (BHD, JOD)', () => {
    expect( getCurrencyDecimals( 'BHD' ) ).toBe( 3 );
    expect( getCurrencyDecimals( 'JOD' ) ).toBe( 3 );
  } );
} );

describe( 'roundingUnit', () => {
  it( 'returns 100 for whole-unit-only currencies (ISK, UGX)', () => {
    expect( roundingUnit( 'ISK' ) ).toBe( 100 );
    expect( roundingUnit( 'UGX' ) ).toBe( 100 );
    expect( roundingUnit( 'isk' ) ).toBe( 100 );
  } );

  it( 'returns 1 for other currencies', () => {
    expect( roundingUnit( 'USD' ) ).toBe( 1 );
    expect( roundingUnit( 'JPY' ) ).toBe( 1 );
    expect( roundingUnit( undefined ) ).toBe( 1 );
  } );
} );

describe( 'roundToCurrency', () => {
  it( 'rounds ISK amounts to whole units', () => {
    expect( roundToCurrency( 1050, 'ISK' ) ).toBe( 1100 );
    expect( roundToCurrency( 1049, 'ISK' ) ).toBe( 1000 );
  } );

  it( 'leaves other currencies untouched', () => {
    expect( roundToCurrency( 1050, 'USD' ) ).toBe( 1050 );
    expect( roundToCurrency( 333, 'JPY' ) ).toBe( 333 );
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
