/* eslint-env jest */

/**
 * Tests for the shared fee and tip calculation utilities.
 */
import {
  calculateFee,
  calculateTip,
  defaultFixedFee,
  maxFixedFee,
  PLATFORM_FEE_RATE,
} from '../fees';

// ── defaultFixedFee ──

describe( 'defaultFixedFee', () => {
  it( 'returns 30 minor units for two-decimal currencies', () => {
    expect( defaultFixedFee( 'USD' ) ).toBe( 30 );
    expect( defaultFixedFee( 'EUR' ) ).toBe( 30 );
  } );

  it( 'returns 0 for zero-decimal currencies', () => {
    expect( defaultFixedFee( 'JPY' ) ).toBe( 0 );
    expect( defaultFixedFee( 'KRW' ) ).toBe( 0 );
  } );

  it( 'returns 300 for three-decimal currencies', () => {
    expect( defaultFixedFee( 'KWD' ) ).toBe( 300 );
    expect( defaultFixedFee( 'BHD' ) ).toBe( 300 );
  } );

  it( 'defaults to USD when no code provided', () => {
    expect( defaultFixedFee() ).toBe( 30 );
  } );
} );

// ── maxFixedFee ──

describe( 'maxFixedFee', () => {
  it( 'caps at 10 major units for two-decimal currencies', () => {
    expect( maxFixedFee( 'USD' ) ).toBe( 1000 );
  } );

  it( 'keeps a 1000 minor-unit floor for zero-decimal currencies', () => {
    expect( maxFixedFee( 'JPY' ) ).toBe( 1000 );
  } );

  it( 'caps at 10 major units for three-decimal currencies', () => {
    expect( maxFixedFee( 'KWD' ) ).toBe( 10000 );
  } );
} );

// ── calculateFee ──

describe( 'calculateFee', () => {
  it( 'covers the Stripe fee on the grossed-up charge', () => {
    // fee = round((5000 + estimate) * 0.029 + 30) with the estimate solved
    // algebraically; the donor covers the fee on donation + fee.
    const fee = calculateFee( 5000, 0.029, 30 );
    expect( fee ).toBe( Math.round( ( 5000 + fee ) * 0.029 + 30 ) );
  } );

  it( 'includes the platform rate when provided', () => {
    const fee = calculateFee( 5000, 0.029, 30, PLATFORM_FEE_RATE );
    const combined = 0.029 + PLATFORM_FEE_RATE;
    expect( fee ).toBe( Math.round( ( 5000 + fee ) * combined + 30 ) );
  } );

  it( 'works with a zero fixed fee (zero-decimal currency default)', () => {
    const fee = calculateFee( 5000, 0.036, defaultFixedFee( 'JPY' ) );
    expect( fee ).toBe( Math.round( ( 5000 + fee ) * 0.036 ) );
  } );
} );

// ── calculateTip ──

describe( 'calculateTip', () => {
  it( 'calculates a percentage tip in minor units', () => {
    expect( calculateTip( 5000, 15 ) ).toBe( 750 );
  } );

  it( 'rounds to the nearest minor unit', () => {
    expect( calculateTip( 333, 15 ) ).toBe( 50 ); // 49.95 → 50
  } );

  it( 'returns 0 for a 0% tip', () => {
    expect( calculateTip( 5000, 0 ) ).toBe( 0 );
  } );

  it( 'rounds to whole units for ISK and UGX', () => {
    // 15% of 500 ISK (50000 minor) = 75.00 ISK — already whole.
    expect( calculateTip( 50000, 15 ) % 1 ).toBe( 0 );
    // 15% of 5.55 ISK-style input would be fractional; result must be
    // a multiple of 100 minor units.
    expect( calculateTip( 555, 15, 'ISK' ) % 100 ).toBe( 0 );
    expect( calculateTip( 33333, 10, 'UGX' ) % 100 ).toBe( 0 );
  } );
} );

// ── whole-unit fee rounding ──

describe( 'calculateFee with whole-unit currencies', () => {
  it( 'returns a multiple of 100 minor units for ISK and UGX', () => {
    expect( calculateFee( 50000, 0.029, 30, 0, 'ISK' ) % 100 ).toBe( 0 );
    expect(
      calculateFee( 50000, 0.029, 30, PLATFORM_FEE_RATE, 'UGX' ) % 100
    ).toBe( 0 );
  } );
} );
