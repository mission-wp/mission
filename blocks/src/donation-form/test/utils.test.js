/* eslint-env jest */

/**
 * Tests for the donation form pure utility functions.
 */
import {
  formatCurrency,
  getEffectiveAmount,
  getAmountsForFrequency,
  resetAmountForFrequency,
  getFeeParams,
  calculateFee,
  calculateTip,
  getPaymentStep,
  validateCustomFields,
} from '../utils';

// ── formatCurrency ──

describe( 'formatCurrency', () => {
  it( 'formats USD minor units correctly', () => {
    expect( formatCurrency( 1050, 'USD' ) ).toContain( '10.50' );
  } );

  it( 'formats whole dollar amounts with .00', () => {
    const result = formatCurrency( 5000, 'USD' );
    expect( result ).toContain( '50' );
    expect( result ).toContain( '.00' );
  } );

  it( 'formats zero-decimal currencies without decimals', () => {
    const result = formatCurrency( 500, 'JPY' );
    expect( result ).toContain( '500' );
    expect( result ).not.toContain( '.' );
  } );

  it( 'formats three-decimal currencies', () => {
    const result = formatCurrency( 1500, 'BHD' );
    // 1500 minor → 1.500 BHD
    expect( result ).toContain( '1.500' );
  } );

  it( 'strips .00 when stripZeroCents is true and amount is whole', () => {
    const result = formatCurrency( 5000, 'USD', true );
    expect( result ).toContain( '50' );
    expect( result ).not.toContain( '.00' );
  } );

  it( 'keeps decimals when stripZeroCents is true but amount has cents', () => {
    const result = formatCurrency( 1050, 'USD', true );
    expect( result ).toContain( '10.50' );
  } );

  it( 'falls back for invalid currency code', () => {
    const result = formatCurrency( 5000, 'INVALID' );
    expect( result ).toBe( '$50.00' );
  } );

  it( 'defaults to USD when no currency code provided', () => {
    const result = formatCurrency( 2500 );
    expect( result ).toContain( '25.00' );
  } );

  it( 'formats zero amount', () => {
    const result = formatCurrency( 0, 'USD' );
    expect( result ).toContain( '0.00' );
  } );
} );

// ── getEffectiveAmount ──

describe( 'getEffectiveAmount', () => {
  it( 'returns selectedAmount when not custom', () => {
    const ctx = {
      isCustomAmount: false,
      selectedAmount: 5000,
      settings: { currency: 'USD' },
    };
    expect( getEffectiveAmount( ctx ) ).toBe( 5000 );
  } );

  it( 'returns converted custom amount when isCustomAmount is true', () => {
    const ctx = {
      isCustomAmount: true,
      customAmountValue: '25.00',
      selectedAmount: 0,
      settings: { currency: 'USD' },
    };
    expect( getEffectiveAmount( ctx ) ).toBe( 2500 );
  } );

  it( 'returns 0 when custom amount is empty', () => {
    const ctx = {
      isCustomAmount: true,
      customAmountValue: '',
      selectedAmount: 0,
      settings: { currency: 'USD' },
    };
    expect( getEffectiveAmount( ctx ) ).toBe( 0 );
  } );

  it( 'handles zero-decimal currency conversion', () => {
    const ctx = {
      isCustomAmount: true,
      customAmountValue: '500',
      selectedAmount: 0,
      settings: { currency: 'JPY' },
    };
    // 500 JPY in minor units is 500 (zero-decimal).
    expect( getEffectiveAmount( ctx ) ).toBe( 500 );
  } );

  it( 'returns 0 when selectedAmount is falsy and not custom', () => {
    const ctx = {
      isCustomAmount: false,
      selectedAmount: 0,
      settings: { currency: 'USD' },
    };
    expect( getEffectiveAmount( ctx ) ).toBe( 0 );
  } );

  it( 'returns 0 for non-numeric custom amount', () => {
    const ctx = {
      isCustomAmount: true,
      customAmountValue: 'abc',
      selectedAmount: 0,
      settings: { currency: 'USD' },
    };
    expect( getEffectiveAmount( ctx ) ).toBe( 0 );
  } );

  it( 'defaults to USD when currency is not set', () => {
    const ctx = {
      isCustomAmount: true,
      customAmountValue: '10.00',
      selectedAmount: 0,
      settings: {},
    };
    expect( getEffectiveAmount( ctx ) ).toBe( 1000 );
  } );
} );

// ── getAmountsForFrequency ──

describe( 'getAmountsForFrequency', () => {
  const ctx = {
    amountsByFrequency: {
      one_time: [ 1000, 2500, 5000 ],
      monthly: [ 500, 1000, 2500 ],
    },
  };

  it( 'returns correct array for the given frequency', () => {
    expect( getAmountsForFrequency( ctx, 'monthly' ) ).toEqual( [
      500, 1000, 2500,
    ] );
  } );

  it( 'falls back to one_time for unknown frequency', () => {
    expect( getAmountsForFrequency( ctx, 'biweekly' ) ).toEqual( [
      1000, 2500, 5000,
    ] );
  } );

  it( 'returns empty array when no amounts configured', () => {
    expect( getAmountsForFrequency( {}, 'monthly' ) ).toEqual( [] );
  } );
} );

// ── resetAmountForFrequency ──

describe( 'resetAmountForFrequency', () => {
  it( 'uses per-frequency default when set', () => {
    const ctx = {
      amountsByFrequency: { monthly: [ 500, 1000, 2500 ] },
      defaultAmounts: { monthly: 1000 },
      selectedAmount: 5000,
      isCustomAmount: true,
      customAmountValue: '50',
    };
    resetAmountForFrequency( ctx, 'monthly' );
    expect( ctx.selectedAmount ).toBe( 1000 );
    expect( ctx.isCustomAmount ).toBe( false );
    expect( ctx.customAmountValue ).toBe( '' );
  } );

  it( 'falls back to first amount when no default', () => {
    const ctx = {
      amountsByFrequency: { quarterly: [ 1500, 3000, 7500 ] },
      defaultAmounts: {},
      selectedAmount: 5000,
      isCustomAmount: false,
      customAmountValue: '',
    };
    resetAmountForFrequency( ctx, 'quarterly' );
    expect( ctx.selectedAmount ).toBe( 1500 );
  } );

  it( 'sets 0 when no amounts and no default', () => {
    const ctx = {
      amountsByFrequency: {},
      defaultAmounts: {},
      selectedAmount: 5000,
      isCustomAmount: false,
      customAmountValue: '',
    };
    resetAmountForFrequency( ctx, 'weekly' );
    expect( ctx.selectedAmount ).toBe( 0 );
  } );

  it( 'clears custom amount state', () => {
    const ctx = {
      amountsByFrequency: { monthly: [ 500 ] },
      defaultAmounts: {},
      selectedAmount: 0,
      isCustomAmount: true,
      customAmountValue: '99.99',
    };
    resetAmountForFrequency( ctx, 'monthly' );
    expect( ctx.isCustomAmount ).toBe( false );
    expect( ctx.customAmountValue ).toBe( '' );
  } );
} );

// ── getFeeParams ──

describe( 'getFeeParams', () => {
  it( 'returns rate as decimal and fixed in minor units', () => {
    const ctx = { stripeFeePercent: 2.9, stripeFeeFixed: 30 };
    const { rate, fixed } = getFeeParams( ctx );
    expect( rate ).toBeCloseTo( 0.029 );
    expect( fixed ).toBe( 30 );
  } );

  it( 'uses defaults when context values are missing', () => {
    const { rate, fixed } = getFeeParams( {} );
    expect( rate ).toBeCloseTo( 0.029 );
    expect( fixed ).toBe( 30 );
  } );

  it( 'respects custom fee values', () => {
    const ctx = { stripeFeePercent: 3.5, stripeFeeFixed: 50 };
    const { rate, fixed } = getFeeParams( ctx );
    expect( rate ).toBeCloseTo( 0.035 );
    expect( fixed ).toBe( 50 );
  } );
} );

// ── calculateFee ──

describe( 'calculateFee', () => {
  const rate = 0.029;
  const fixed = 30;

  it( 'calculates correct fee for a $25 donation', () => {
    expect( calculateFee( 2500, rate, fixed ) ).toBe( 106 );
  } );

  it( 'calculates correct fee for a $50 donation', () => {
    expect( calculateFee( 5000, rate, fixed ) ).toBe( 180 );
  } );

  it( 'calculates correct fee for a $100 donation', () => {
    expect( calculateFee( 10000, rate, fixed ) ).toBe( 330 );
  } );

  it( 'handles zero donation amount', () => {
    expect( calculateFee( 0, rate, fixed ) ).toBe( 31 );
  } );

  it( 'calculates correct fee for a large donation ($10,000)', () => {
    expect( calculateFee( 1000000, rate, fixed ) ).toBe( 29897 );
  } );

  it( 'fee is self-consistent with Stripe pricing', () => {
    // Stripe's fee on (donation + fee) should equal the fee itself.
    const fee = calculateFee( 5000, rate, fixed );
    const stripeFee = Math.round( ( 5000 + fee ) * rate + fixed );
    expect( fee ).toBe( stripeFee );
  } );

  it( 'defaults platformRate to 0 (no platform fee)', () => {
    // Calling without platformRate should match calling with 0 explicitly.
    expect( calculateFee( 5000, rate, fixed ) ).toBe(
      calculateFee( 5000, rate, fixed, 0 )
    );
  } );

  describe( 'with 3% platform fee', () => {
    const platformRate = 0.03;

    it( 'covers both Stripe and platform fees for $100 donation', () => {
      const fee = calculateFee( 10000, rate, fixed, platformRate );
      const total = 10000 + fee;
      const stripeFee = Math.round( total * rate + fixed );
      const platformFee = Math.round( total * platformRate );
      // Nonprofit should net the original donation amount.
      expect( total - stripeFee - platformFee ).toBe( 10000 );
    } );

    it( 'covers both fees for $50 donation', () => {
      const fee = calculateFee( 5000, rate, fixed, platformRate );
      const total = 5000 + fee;
      const stripeFee = Math.round( total * rate + fixed );
      const platformFee = Math.round( total * platformRate );
      expect( total - stripeFee - platformFee ).toBe( 5000 );
    } );

    it( 'is larger than Stripe-only fee', () => {
      const stripeOnly = calculateFee( 5000, rate, fixed );
      const withPlatform = calculateFee( 5000, rate, fixed, platformRate );
      expect( withPlatform ).toBeGreaterThan( stripeOnly );
    } );

    it( 'fee is self-consistent with combined rates', () => {
      const fee = calculateFee( 5000, rate, fixed, platformRate );
      const combinedFee = Math.round(
        ( 5000 + fee ) * ( rate + platformRate ) + fixed
      );
      expect( fee ).toBe( combinedFee );
    } );
  } );
} );

// ── calculateTip ──

describe( 'calculateTip', () => {
  it( 'calculates 15% tip on $25', () => {
    expect( calculateTip( 2500, 15 ) ).toBe( 375 );
  } );

  it( 'returns 0 for 0% tip', () => {
    expect( calculateTip( 2500, 0 ) ).toBe( 0 );
  } );

  it( 'calculates 20% tip on $50', () => {
    expect( calculateTip( 5000, 20 ) ).toBe( 1000 );
  } );

  it( 'rounds correctly for odd amounts', () => {
    // 15% of 3333 = 499.95 → rounds to 500.
    expect( calculateTip( 3333, 15 ) ).toBe( 500 );
  } );

  it( 'handles large amounts', () => {
    expect( calculateTip( 1000000, 10 ) ).toBe( 100000 );
  } );
} );

// ── getPaymentStep ──

describe( 'getPaymentStep', () => {
  it( 'returns 3 when hasCustomFields is true', () => {
    expect( getPaymentStep( { hasCustomFields: true } ) ).toBe( 3 );
  } );

  it( 'returns 2 when hasCustomFields is false', () => {
    expect( getPaymentStep( { hasCustomFields: false } ) ).toBe( 2 );
  } );
} );

// ── validateCustomFields ──

describe( 'validateCustomFields', () => {
  it( 'returns true when no required fields', () => {
    const ctx = {
      customFields: [ { id: 'opt', type: 'text', required: false } ],
      customFieldValues: {},
    };
    expect( validateCustomFields( ctx ) ).toBe( true );
    expect( ctx.customFieldErrors ).toEqual( {} );
  } );

  it( 'returns false for empty required text field', () => {
    const ctx = {
      customFields: [ { id: 'name', type: 'text', required: true } ],
      customFieldValues: {},
    };
    expect( validateCustomFields( ctx ) ).toBe( false );
    expect( ctx.customFieldErrors.name ).toBe( true );
  } );

  it( 'returns false for whitespace-only required text field', () => {
    const ctx = {
      customFields: [ { id: 'name', type: 'text', required: true } ],
      customFieldValues: { name: '   ' },
    };
    expect( validateCustomFields( ctx ) ).toBe( false );
    expect( ctx.customFieldErrors.name ).toBe( true );
  } );

  it( 'returns false for unchecked required checkbox', () => {
    const ctx = {
      customFields: [ { id: 'agree', type: 'checkbox', required: true } ],
      customFieldValues: { agree: false },
    };
    expect( validateCustomFields( ctx ) ).toBe( false );
    expect( ctx.customFieldErrors.agree ).toBe( true );
  } );

  it( 'returns true for checked required checkbox', () => {
    const ctx = {
      customFields: [ { id: 'agree', type: 'checkbox', required: true } ],
      customFieldValues: { agree: true },
    };
    expect( validateCustomFields( ctx ) ).toBe( true );
  } );

  it( 'returns false for empty required multiselect', () => {
    const ctx = {
      customFields: [ { id: 'tags', type: 'multiselect', required: true } ],
      customFieldValues: { tags: [] },
    };
    expect( validateCustomFields( ctx ) ).toBe( false );
    expect( ctx.customFieldErrors.tags ).toBe( true );
  } );

  it( 'returns true for populated required multiselect', () => {
    const ctx = {
      customFields: [ { id: 'tags', type: 'multiselect', required: true } ],
      customFieldValues: { tags: [ 'a', 'b' ] },
    };
    expect( validateCustomFields( ctx ) ).toBe( true );
  } );

  it( 'skips non-required fields', () => {
    const ctx = {
      customFields: [
        { id: 'opt', type: 'text', required: false },
        { id: 'req', type: 'text', required: true },
      ],
      customFieldValues: { req: 'filled' },
    };
    expect( validateCustomFields( ctx ) ).toBe( true );
    expect( ctx.customFieldErrors ).toEqual( {} );
  } );

  it( 'validates multiple fields and reports all errors', () => {
    const ctx = {
      customFields: [
        { id: 'a', type: 'text', required: true },
        { id: 'b', type: 'checkbox', required: true },
        { id: 'c', type: 'multiselect', required: true },
      ],
      customFieldValues: {},
    };
    expect( validateCustomFields( ctx ) ).toBe( false );
    expect( ctx.customFieldErrors.a ).toBe( true );
    expect( ctx.customFieldErrors.b ).toBe( true );
    expect( ctx.customFieldErrors.c ).toBe( true );
  } );

  it( 'returns false for empty required select field', () => {
    const ctx = {
      customFields: [ { id: 'color', type: 'select', required: true } ],
      customFieldValues: { color: '' },
    };
    expect( validateCustomFields( ctx ) ).toBe( false );
    expect( ctx.customFieldErrors.color ).toBe( true );
  } );

  it( 'returns true for populated required select field', () => {
    const ctx = {
      customFields: [ { id: 'color', type: 'select', required: true } ],
      customFieldValues: { color: 'blue' },
    };
    expect( validateCustomFields( ctx ) ).toBe( true );
  } );

  it( 'returns false for empty required textarea field', () => {
    const ctx = {
      customFields: [ { id: 'notes', type: 'textarea', required: true } ],
      customFieldValues: { notes: '  ' },
    };
    expect( validateCustomFields( ctx ) ).toBe( false );
    expect( ctx.customFieldErrors.notes ).toBe( true );
  } );
} );

// ── Total calculation integration ──

describe( 'total calculation (amount + fee + tip)', () => {
  it( 'computes correct total for a typical donation', () => {
    const ctx = {
      isCustomAmount: false,
      selectedAmount: 5000, // $50
      settings: { currency: 'USD' },
    };
    const amount = getEffectiveAmount( ctx );
    const { rate, fixed } = getFeeParams( {
      stripeFeePercent: 2.9,
      stripeFeeFixed: 30,
    } );
    const fee = calculateFee( amount, rate, fixed );
    const tip = calculateTip( amount, 15 );
    const total = amount + fee + tip;

    expect( amount ).toBe( 5000 );
    expect( fee ).toBe( 180 );
    expect( tip ).toBe( 750 );
    expect( total ).toBe( 5930 );
  } );

  it( 'total equals donation when no fee recovery and no tip', () => {
    const ctx = {
      isCustomAmount: false,
      selectedAmount: 2500,
      settings: { currency: 'USD' },
    };
    const amount = getEffectiveAmount( ctx );
    const tip = calculateTip( amount, 0 );
    const total = amount + tip;

    expect( total ).toBe( 2500 );
  } );
} );
