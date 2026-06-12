/* eslint-env jest */

import { render } from '@testing-library/react';
import SkeletonBar from '../SkeletonBar';

describe( 'SkeletonBar', () => {
  it( 'renders a span with the mission-skeleton class and default shape', () => {
    const { container } = render( <SkeletonBar /> );
    const bar = container.querySelector( 'span.mission-skeleton' );

    expect( bar ).not.toBeNull();
    expect( bar.style.display ).toBe( 'block' );
    expect( bar.style.width ).toBe( '60%' );
    expect( bar.style.height ).toBe( '16px' );
    expect( bar.style.borderRadius ).toBe( '4px' );
    expect( bar.style.background ).toBe( 'rgb(226, 228, 233)' );
  } );

  it( 'applies width and height overrides', () => {
    const { container } = render( <SkeletonBar width="120px" height="13px" /> );
    const bar = container.querySelector( '.mission-skeleton' );

    expect( bar.style.width ).toBe( '120px' );
    expect( bar.style.height ).toBe( '13px' );
  } );

  it( 'merges the style prop over the defaults', () => {
    const { container } = render(
      <SkeletonBar
        style={ { display: 'inline-block', verticalAlign: 'middle' } }
      />
    );
    const bar = container.querySelector( '.mission-skeleton' );

    expect( bar.style.display ).toBe( 'inline-block' );
    expect( bar.style.verticalAlign ).toBe( 'middle' );
    expect( bar.style.borderRadius ).toBe( '4px' );
  } );
} );
