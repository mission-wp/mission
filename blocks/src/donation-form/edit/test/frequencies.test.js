/* eslint-env jest */

import {
  buildFrequencyArray,
  resolveDefaultFrequency,
  frequencyAttributes,
} from '../frequencies';

describe( 'buildFrequencyArray', () => {
  it( 'returns only one_time when recurring is off', () => {
    expect(
      buildFrequencyArray( {
        recurringEnabled: false,
        recurringFrequencies: [ 'monthly' ],
      } )
    ).toEqual( [ 'one_time' ] );
  } );

  it( 'returns only one_time when no recurring frequencies are set', () => {
    expect(
      buildFrequencyArray( {
        recurringEnabled: true,
        recurringFrequencies: [],
      } )
    ).toEqual( [ 'one_time' ] );
  } );

  it( 'puts one_time first when it is enabled', () => {
    expect(
      buildFrequencyArray( {
        recurringEnabled: true,
        recurringFrequencies: [ 'monthly', 'annually' ],
      } )
    ).toEqual( [ 'one_time', 'monthly', 'annually' ] );
  } );

  it( 'omits one_time when it is switched off', () => {
    expect(
      buildFrequencyArray( {
        oneTimeEnabled: false,
        recurringEnabled: true,
        recurringFrequencies: [ 'monthly', 'annually' ],
      } )
    ).toEqual( [ 'monthly', 'annually' ] );
  } );

  it( 'ignores oneTimeEnabled=false when nothing recurring is offered', () => {
    expect(
      buildFrequencyArray( {
        oneTimeEnabled: false,
        recurringEnabled: false,
        recurringFrequencies: [],
      } )
    ).toEqual( [ 'one_time' ] );
  } );

  it( 'infers recurringEnabled from the frequency list when unset', () => {
    expect(
      buildFrequencyArray( { recurringFrequencies: [ 'weekly' ] } )
    ).toEqual( [ 'one_time', 'weekly' ] );
  } );
} );

describe( 'resolveDefaultFrequency', () => {
  it( 'keeps the current default when it is offered', () => {
    expect(
      resolveDefaultFrequency( [ 'one_time', 'monthly' ], 'monthly' )
    ).toBe( 'monthly' );
  } );

  it( 'falls back to one_time when offered', () => {
    expect(
      resolveDefaultFrequency( [ 'one_time', 'annually' ], 'monthly' )
    ).toBe( 'one_time' );
  } );

  it( 'prefers monthly on a recurring-only form', () => {
    expect(
      resolveDefaultFrequency( [ 'quarterly', 'monthly' ], 'one_time' )
    ).toBe( 'monthly' );
  } );

  it( 'uses the first offered frequency otherwise', () => {
    expect( resolveDefaultFrequency( [ 'annually' ], 'one_time' ) ).toBe(
      'annually'
    );
  } );
} );

describe( 'frequencyAttributes', () => {
  it( 'splits a mixed list into the block attributes', () => {
    expect(
      frequencyAttributes( [ 'one_time', 'monthly', 'annually' ], 'monthly' )
    ).toEqual( {
      oneTimeEnabled: true,
      recurringEnabled: true,
      recurringFrequencies: [ 'monthly', 'annually' ],
      recurringDefault: 'monthly',
    } );
  } );

  it( 'records a recurring-only form and moves the default off one_time', () => {
    expect(
      frequencyAttributes( [ 'monthly', 'annually' ], 'one_time' )
    ).toEqual( {
      oneTimeEnabled: false,
      recurringEnabled: true,
      recurringFrequencies: [ 'monthly', 'annually' ],
      recurringDefault: 'monthly',
    } );
  } );

  it( 'keeps one_time on when recurring is off', () => {
    expect( frequencyAttributes( [ 'one_time' ], 'monthly' ) ).toEqual( {
      oneTimeEnabled: true,
      recurringEnabled: false,
      recurringFrequencies: [],
      recurringDefault: 'one_time',
    } );
  } );

  it( 'round-trips through buildFrequencyArray', () => {
    const list = [ 'monthly', 'quarterly' ];
    expect(
      buildFrequencyArray( frequencyAttributes( list, 'quarterly' ) )
    ).toEqual( list );
  } );
} );
