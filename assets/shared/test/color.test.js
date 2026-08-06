/* eslint-env jest */

import { darkenColor, computePrimaryColorVars } from '../color';

describe( 'darkenColor', () => {
  it( 'darkens each channel by the given percentage', () => {
    expect( darkenColor( '#2fa36b', 12 ) ).toBe( '#298f5e' );
  } );

  it( 'returns the same color at 0%', () => {
    expect( darkenColor( '#2fa36b', 0 ) ).toBe( '#2fa36b' );
  } );

  it( 'returns black at 100%', () => {
    expect( darkenColor( '#2fa36b', 100 ) ).toBe( '#000000' );
  } );

  it( 'pads single-digit channels with zeros', () => {
    expect( darkenColor( '#100000', 50 ) ).toBe( '#080000' );
  } );
} );

describe( 'computePrimaryColorVars', () => {
  it( 'gives dark colors white button text', () => {
    const vars = computePrimaryColorVars( '#2fa36b' );
    expect( vars[ '--mission-primary' ] ).toBe( '#2fa36b' );
    expect( vars[ '--mission-primary-text' ] ).toBe( '#ffffff' );
    expect( vars[ '--mission-primary-text-on-light' ] ).toBe( '#2fa36b' );
    expect( vars[ '--mission-primary-hover' ] ).toBe( '#298f5e' );
    // Stylesheets derive --mission-primary-light with color-mix; it must not
    // be emitted inline where kses would strip function values.
    expect( vars ).not.toHaveProperty( '--mission-primary-light' );
  } );

  it( 'gives light colors dark text and a darkened on-light variant', () => {
    const vars = computePrimaryColorVars( '#ffeb3b' );
    expect( vars[ '--mission-primary-text' ] ).toBe( '#1e1e1e' );
    expect( vars[ '--mission-primary-text-on-light' ] ).toBe( '#8c8120' );
  } );

  it( 'flips text color at the luminance threshold', () => {
    expect(
      computePrimaryColorVars( '#808080' )[ '--mission-primary-text' ]
    ).toBe( '#1e1e1e' );
    expect(
      computePrimaryColorVars( '#7f7f7f' )[ '--mission-primary-text' ]
    ).toBe( '#ffffff' );
  } );
} );
