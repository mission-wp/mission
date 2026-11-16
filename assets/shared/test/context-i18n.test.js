/* eslint-env jest */

import { t } from '../context-i18n';

describe( 't', () => {
  it( 'returns the translated string from the context', () => {
    const ctx = { i18n: { saved: 'Enregistré' } };
    expect( t( ctx, 'saved', 'Saved' ) ).toBe( 'Enregistré' );
  } );

  it( 'falls back to English when the key or the map is missing', () => {
    expect( t( { i18n: {} }, 'saved', 'Saved' ) ).toBe( 'Saved' );
    expect( t( {}, 'saved', 'Saved' ) ).toBe( 'Saved' );
    expect( t( undefined, 'saved', 'Saved' ) ).toBe( 'Saved' );
  } );

  it( 'fills a single placeholder', () => {
    const ctx = { i18n: { expires: 'Expire le %s' } };
    expect( t( ctx, 'expires', 'Expires %s', '03/2027' ) ).toBe(
      'Expire le 03/2027'
    );
  } );

  it( 'fills numbered placeholders in the order the translation uses', () => {
    const ctx = { i18n: { card: 'Carte %2$s (%1$s)' } };
    expect( t( ctx, 'card', '%1$s ending in %2$s', 'Visa', '4242' ) ).toBe(
      'Carte 4242 (Visa)'
    );
  } );

  it( 'inserts values containing dollar signs literally', () => {
    expect( t( {}, 'updateTo', 'Update to %s', '$25.00 / month' ) ).toBe(
      'Update to $25.00 / month'
    );
  } );
} );
