/* eslint-env jest */

import {
  withDeadline,
  buildAppearance,
  buildElementsOptions,
  buildPaymentElementOptions,
  paymentIntentIdFrom,
  getPaymentConfig,
  resetPaymentConfigCache,
} from '../stripe';

describe( 'withDeadline', () => {
  beforeEach( () => {
    jest.useFakeTimers();
  } );

  afterEach( () => {
    jest.useRealTimers();
  } );

  it( 'resolves with the promise value when it settles in time', async () => {
    const result = withDeadline( Promise.resolve( 'ok' ), 1000, 'too slow' );
    await expect( result ).resolves.toBe( 'ok' );
  } );

  it( 'rejects with the deadline message when the promise never settles', async () => {
    const result = withDeadline( new Promise( () => {} ), 1000, 'too slow' );
    const assertion = expect( result ).rejects.toThrow( 'too slow' );
    jest.advanceTimersByTime( 1001 );
    await assertion;
  } );

  it( 'propagates the underlying rejection before the deadline', async () => {
    const result = withDeadline(
      Promise.reject( new Error( 'card declined' ) ),
      1000,
      'too slow'
    );
    await expect( result ).rejects.toThrow( 'card declined' );
  } );
} );

describe( 'buildAppearance', () => {
  it( 'produces the standard appearance for a primary color', () => {
    expect( buildAppearance( '#123456' ) ).toEqual( {
      theme: 'stripe',
      variables: {
        colorPrimary: '#123456',
        colorDanger: '#dc2626',
        fontFamily:
          '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
        borderRadius: '10px',
      },
      rules: {
        '.Input--invalid': {
          borderColor: '#dc2626',
          boxShadow: '0 0 0 3px rgba(220, 38, 38, 0.12)',
        },
        '.Error': {
          fontSize: '0.8125rem',
        },
      },
    } );
  } );

  it( 'falls back to the brand green without a primary color', () => {
    expect( buildAppearance( '' ).variables.colorPrimary ).toBe( '#2FA36B' );
  } );

  it( 'merges custom theme, variables, and rules over the defaults', () => {
    const appearance = buildAppearance( '#123456', {
      theme: 'night',
      variables: { borderRadius: '2px' },
      rules: { '.Error': { fontSize: '1rem' } },
    } );

    expect( appearance.theme ).toBe( 'night' );
    expect( appearance.variables.borderRadius ).toBe( '2px' );
    expect( appearance.variables.colorPrimary ).toBe( '#123456' );
    expect( appearance.rules[ '.Error' ] ).toEqual( { fontSize: '1rem' } );
    expect( appearance.rules[ '.Input--invalid' ] ).toBeDefined();
  } );
} );

describe( 'buildElementsOptions', () => {
  it( 'builds deferred-mode options with a lowercased currency', () => {
    expect(
      buildElementsOptions( { amount: 2500, currency: 'EUR', appearance: {} } )
    ).toEqual( {
      mode: 'payment',
      amount: 2500,
      currency: 'eur',
      paymentMethodTypes: [ 'card' ],
      appearance: {},
    } );
  } );

  it( 'defaults the currency to usd', () => {
    expect(
      buildElementsOptions( { amount: 100, currency: '', appearance: {} } )
        .currency
    ).toBe( 'usd' );
  } );

  it( 'adds setupFutureUsage only for recurring', () => {
    const oneTime = buildElementsOptions( {
      amount: 100,
      currency: 'USD',
      appearance: {},
    } );
    const recurring = buildElementsOptions( {
      amount: 100,
      currency: 'USD',
      appearance: {},
      recurring: true,
    } );

    expect( oneTime ).not.toHaveProperty( 'setupFutureUsage' );
    expect( recurring.setupFutureUsage ).toBe( 'off_session' );
  } );
} );

describe( 'buildPaymentElementOptions', () => {
  it( 'collects the billing address in the element by default', () => {
    expect( buildPaymentElementOptions() ).toEqual( {
      layout: 'tabs',
      fields: {
        billingDetails: { name: 'never', email: 'never', address: 'auto' },
      },
      wallets: { link: 'never' },
    } );
  } );

  it( 'passes through address: never for a separate Address Element', () => {
    expect(
      buildPaymentElementOptions( { address: 'never' } ).fields.billingDetails
        .address
    ).toBe( 'never' );
  } );
} );

describe( 'paymentIntentIdFrom', () => {
  it( 'extracts the PaymentIntent ID from a client secret', () => {
    expect( paymentIntentIdFrom( 'pi_123_secret_abc' ) ).toBe( 'pi_123' );
  } );
} );

describe( 'getPaymentConfig', () => {
  const REST_URL = 'https://example.org/wp-json/mission-donation-platform/v1/';

  afterEach( () => {
    resetPaymentConfigCache();
    delete global.fetch;
  } );

  it( 'memoizes a successful lookup', async () => {
    global.fetch = jest.fn().mockResolvedValue( {
      ok: true,
      json: () => Promise.resolve( { connected_account_id: 'acct_1' } ),
    } );

    const first = await getPaymentConfig( REST_URL );
    const second = await getPaymentConfig( REST_URL );

    expect( first ).toEqual( { connected_account_id: 'acct_1' } );
    expect( second ).toEqual( first );
    expect( global.fetch ).toHaveBeenCalledTimes( 1 );
    expect( global.fetch ).toHaveBeenCalledWith(
      `${ REST_URL }donations/payment-config`
    );
  } );

  it( 'resolves null on an error response and evicts the memo', async () => {
    global.fetch = jest
      .fn()
      .mockResolvedValueOnce( { ok: false } )
      .mockResolvedValue( {
        ok: true,
        json: () => Promise.resolve( { connected_account_id: 'acct_1' } ),
      } );

    expect( await getPaymentConfig( REST_URL ) ).toBeNull();

    // The failure was not cached: the next call refetches and succeeds.
    expect( await getPaymentConfig( REST_URL ) ).toEqual( {
      connected_account_id: 'acct_1',
    } );
    expect( global.fetch ).toHaveBeenCalledTimes( 2 );
  } );

  it( 'resolves null on a network error instead of rejecting', async () => {
    global.fetch = jest.fn().mockRejectedValue( new Error( 'offline' ) );

    await expect( getPaymentConfig( REST_URL ) ).resolves.toBeNull();
  } );

  it( 'does not cache an unconnected lookup', async () => {
    global.fetch = jest.fn().mockResolvedValue( {
      ok: true,
      json: () => Promise.resolve( { connected_account_id: '' } ),
    } );

    await getPaymentConfig( REST_URL );
    await getPaymentConfig( REST_URL );

    expect( global.fetch ).toHaveBeenCalledTimes( 2 );
  } );

  it( 'refetches after resetPaymentConfigCache', async () => {
    global.fetch = jest.fn().mockResolvedValue( {
      ok: true,
      json: () => Promise.resolve( { connected_account_id: 'acct_1' } ),
    } );

    await getPaymentConfig( REST_URL );
    resetPaymentConfigCache();
    await getPaymentConfig( REST_URL );

    expect( global.fetch ).toHaveBeenCalledTimes( 2 );
  } );
} );
