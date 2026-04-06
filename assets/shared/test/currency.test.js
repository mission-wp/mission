/* eslint-env jest */

import { formatAmount, getCurrencySymbol, getCurrencyCode } from '../currency';

describe( 'formatAmount', () => {
  beforeEach( () => {
    window.missionAdmin = { currency: 'USD' };
  } );

  afterEach( () => {
    delete window.missionAdmin;
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
    window.missionAdmin = { currency: 'USD' };
  } );

  afterEach( () => {
    delete window.missionAdmin;
  } );

  it( 'returns "$" for USD', () => {
    expect( getCurrencySymbol() ).toBe( '$' );
  } );

  it( 'returns correct symbols for EUR, GBP, JPY', () => {
    window.missionAdmin.currency = 'EUR';
    expect( getCurrencySymbol() ).toBe( '€' );

    window.missionAdmin.currency = 'GBP';
    expect( getCurrencySymbol() ).toBe( '£' );

    window.missionAdmin.currency = 'JPY';
    expect( getCurrencySymbol() ).toBe( '¥' );
  } );
} );

describe( 'getCurrencyCode', () => {
  afterEach( () => {
    delete window.missionAdmin;
  } );

  it( 'defaults to USD when missionAdmin is undefined', () => {
    delete window.missionAdmin;
    expect( getCurrencyCode() ).toBe( 'USD' );
  } );

  it( 'defaults to USD when currency is falsy', () => {
    window.missionAdmin = { currency: '' };
    expect( getCurrencyCode() ).toBe( 'USD' );
  } );

  it( 'returns the configured currency', () => {
    window.missionAdmin = { currency: 'EUR' };
    expect( getCurrencyCode() ).toBe( 'EUR' );
  } );
} );
