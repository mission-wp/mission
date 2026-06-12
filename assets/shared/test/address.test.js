/* eslint-env jest */

import {
  COUNTRIES,
  US_STATES,
  CA_PROVINCES,
  getRegionConfig,
} from '../address';

describe( 'getRegionConfig', () => {
  it( 'returns state/zip config with options for the US', () => {
    const config = getRegionConfig( 'US' );
    expect( config.label ).toBe( 'State' );
    expect( config.postalLabel ).toBe( 'Zip' );
    expect( config.options ).toBe( US_STATES );
  } );

  it( 'returns province/postal-code config with options for Canada', () => {
    const config = getRegionConfig( 'CA' );
    expect( config.label ).toBe( 'Province' );
    expect( config.postalLabel ).toBe( 'Postal Code' );
    expect( config.options ).toBe( CA_PROVINCES );
  } );

  it( 'returns a free-text region for other countries', () => {
    for ( const code of [ 'GB', 'DE', '', undefined ] ) {
      const config = getRegionConfig( code );
      expect( config.label ).toBe( 'Region' );
      expect( config.postalLabel ).toBe( 'Postal Code' );
      expect( config.options ).toBeNull();
    }
  } );
} );

describe( 'address data lists', () => {
  it( 'has unique country codes', () => {
    const codes = COUNTRIES.map( ( c ) => c.value );
    expect( new Set( codes ).size ).toBe( codes.length );
  } );

  it( 'includes the US and uses two-letter codes throughout', () => {
    expect( COUNTRIES ).toContainEqual( {
      value: 'US',
      label: 'United States',
    } );
    for ( const { value } of COUNTRIES ) {
      expect( value ).toMatch( /^[A-Z]{2}$/ );
    }
  } );

  it( 'lists the 50 states plus DC and five territories', () => {
    expect( US_STATES ).toHaveLength( 56 );
    const codes = US_STATES.map( ( s ) => s.value );
    expect( new Set( codes ).size ).toBe( codes.length );
  } );

  it( 'lists 13 Canadian provinces and territories', () => {
    expect( CA_PROVINCES ).toHaveLength( 13 );
  } );
} );
