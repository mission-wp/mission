/* eslint-env jest */

import { formatAmount, getCurrencySymbol, getCurrencyCode } from '../currency';

describe( 'formatAmount', () => {
  beforeEach( () => {
    window.missiondpAdmin = { currency: 'USD' };
  } );

  afterEach( () => {
    delete window.missiondpAdmin;
  } );

  it( 'formats 1000 minor units as $10.00', () => {
    expect( formatAmount( 1000 ) ).toBe( '$10.00' );
  } );

  it( 'formats zero-decimal currency without decimals', () => {
    expect( formatAmount( 500, 'JPY' ) ).toBe( '¥500' );
  } );

  it( 'formats whole dollar amounts with .00 by default', () => {
    expect( formatAmount( 5000, 'USD' ) ).toBe( '$50.00' );
  } );

  it( 'formats three-decimal currencies', () => {
    // 1500 minor units = 1.500 BHD.
    expect( formatAmount( 1500, 'BHD' ) ).toContain( '1.500' );
  } );

  it( 'strips .00 when stripZeroCents is set and the amount is whole', () => {
    const result = formatAmount( 5000, 'USD', { stripZeroCents: true } );
    expect( result ).toContain( '50' );
    expect( result ).not.toContain( '.00' );
  } );

  it( 'keeps decimals when stripZeroCents is set but the amount has cents', () => {
    expect( formatAmount( 1050, 'USD', { stripZeroCents: true } ) ).toContain(
      '10.50'
    );
  } );

  it( 'falls back to a dollar string for an invalid currency code', () => {
    expect( formatAmount( 5000, 'INVALID' ) ).toBe( '$50.00' );
  } );

  it( 'formats a zero amount', () => {
    expect( formatAmount( 0, 'USD' ) ).toContain( '0.00' );
  } );
} );

describe( 'getCurrencySymbol', () => {
  beforeEach( () => {
    window.missiondpAdmin = { currency: 'USD' };
  } );

  afterEach( () => {
    delete window.missiondpAdmin;
  } );

  it( 'returns "$" for USD', () => {
    expect( getCurrencySymbol() ).toBe( '$' );
  } );

  it( 'returns correct symbols for EUR, GBP, JPY', () => {
    window.missiondpAdmin.currency = 'EUR';
    expect( getCurrencySymbol() ).toBe( '€' );

    window.missiondpAdmin.currency = 'GBP';
    expect( getCurrencySymbol() ).toBe( '£' );

    window.missiondpAdmin.currency = 'JPY';
    expect( getCurrencySymbol() ).toBe( '¥' );
  } );
} );

describe( 'getCurrencyCode', () => {
  afterEach( () => {
    delete window.missiondpAdmin;
  } );

  it( 'defaults to USD when missiondpAdmin is undefined', () => {
    delete window.missiondpAdmin;
    expect( getCurrencyCode() ).toBe( 'USD' );
  } );

  it( 'defaults to USD when currency is falsy', () => {
    window.missiondpAdmin = { currency: '' };
    expect( getCurrencyCode() ).toBe( 'USD' );
  } );

  it( 'returns the configured currency', () => {
    window.missiondpAdmin = { currency: 'EUR' };
    expect( getCurrencyCode() ).toBe( 'EUR' );
  } );
} );
