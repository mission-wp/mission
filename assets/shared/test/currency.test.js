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
