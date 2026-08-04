/* eslint-env jest */

import { render, screen } from '@testing-library/react';
import ProgressBar, { goalPercent } from '../ProgressBar';

describe( 'goalPercent', () => {
  it( 'returns 0 without a positive goal', () => {
    expect( goalPercent( 500, 0 ) ).toBe( 0 );
    expect( goalPercent( 500, -100 ) ).toBe( 0 );
  } );

  it( 'rounds to a whole percent', () => {
    expect( goalPercent( 333, 1000 ) ).toBe( 33 );
    expect( goalPercent( 335, 1000 ) ).toBe( 34 );
  } );

  it( 'caps at 100', () => {
    expect( goalPercent( 25000, 10000 ) ).toBe( 100 );
  } );
} );

describe( 'ProgressBar', () => {
  it( 'fills the track to the given percent and renders the label', () => {
    const { container } = render(
      <ProgressBar percent={ 80 }>80%</ProgressBar>
    );
    const fill = container.querySelector( '.mission-progress-bar__fill' );
    expect( fill.style.width ).toBe( '80%' );
    expect( screen.getByText( '80%' ) ).toBeTruthy();
  } );

  it( 'adds the wide modifier only when asked', () => {
    const { container } = render( <ProgressBar percent={ 10 } /> );
    expect(
      container.querySelector( '.mission-progress-bar--wide' )
    ).toBeNull();

    const wide = render( <ProgressBar percent={ 10 } wide /> ).container;
    expect( wide.querySelector( '.mission-progress-bar--wide' ) ).toBeTruthy();
  } );
} );
