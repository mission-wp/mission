/* eslint-env jest */

import { render, screen } from '@testing-library/react';
import StatCard, { getDelta } from '../StatCard';

describe( 'getDelta', () => {
  it( 'returns neutral with value 0 when previous is falsy', () => {
    expect( getDelta( 100, 0 ) ).toEqual( { value: 0, direction: 'neutral' } );
    expect( getDelta( 100, null ) ).toEqual( {
      value: 0,
      direction: 'neutral',
    } );
  } );

  it( 'returns a positive delta rounded to one decimal', () => {
    expect( getDelta( 150, 100 ) ).toEqual( {
      value: 50,
      direction: 'positive',
    } );
    expect( getDelta( 101, 99 ) ).toEqual( {
      value: 2,
      direction: 'positive',
    } );
  } );

  it( 'returns a negative delta as an absolute value', () => {
    expect( getDelta( 75, 100 ) ).toEqual( {
      value: 25,
      direction: 'negative',
    } );
  } );

  it( 'returns neutral when the values are equal', () => {
    expect( getDelta( 100, 100 ) ).toEqual( {
      value: 0,
      direction: 'neutral',
    } );
  } );
} );

describe( 'StatCard', () => {
  it( 'renders label and value in a plain div card', () => {
    const { container } = render(
      <StatCard label="Total Revenue" value="$1,200" />
    );

    const card = container.querySelector( 'div.mission-stat-card' );
    expect( card ).not.toBeNull();
    expect( screen.getByText( 'Total Revenue' ) ).toBeTruthy();
    expect( screen.getByText( '$1,200' ) ).toBeTruthy();
  } );

  it( 'shows skeletons in the value and delta slots while loading', () => {
    const { container } = render(
      <StatCard label="Total Revenue" value="$1,200" isLoading />
    );

    expect(
      container.querySelectorAll(
        '.mission-stat-card__value .mission-skeleton'
      )
    ).toHaveLength( 1 );
    expect(
      container.querySelectorAll(
        '.mission-stat-card__delta .mission-skeleton'
      )
    ).toHaveLength( 1 );
    expect( screen.queryByText( '$1,200' ) ).toBeNull();
  } );

  it( 'renders a positive delta with the direction class and an arrow', () => {
    const { container } = render(
      <StatCard
        label="Donations"
        value="42"
        delta={ { value: 12.5, direction: 'positive', label: 'vs last month' } }
      />
    );

    const deltaEl = container.querySelector( '.mission-stat-card__delta' );
    expect( deltaEl.classList.contains( 'is-positive' ) ).toBe( true );
    expect( deltaEl.querySelector( 'svg' ) ).not.toBeNull();
    expect( deltaEl.textContent ).toContain( '12.5% vs last month' );
  } );

  it( 'renders a neutral delta without an arrow', () => {
    const { container } = render(
      <StatCard
        label="Donations"
        value="42"
        delta={ { value: 0, direction: 'neutral', label: 'vs last month' } }
      />
    );

    const deltaEl = container.querySelector( '.mission-stat-card__delta' );
    expect( deltaEl.classList.contains( 'is-neutral' ) ).toBe( true );
    expect( deltaEl.querySelector( 'svg' ) ).toBeNull();
  } );

  it( 'renders the subtitle only when no delta is given', () => {
    const { container, rerender } = render(
      <StatCard label="Refunds" value="$10" subtitle="2 refunds (1.0%)" />
    );

    expect(
      container.querySelector( '.mission-stat-card__subtitle' ).textContent
    ).toBe( '2 refunds (1.0%)' );

    rerender(
      <StatCard
        label="Refunds"
        value="$10"
        subtitle="2 refunds (1.0%)"
        delta={ { value: 1, direction: 'positive', label: 'vs last month' } }
      />
    );

    expect(
      container.querySelector( '.mission-stat-card__subtitle' )
    ).toBeNull();
  } );

  it( 'appends the className prop', () => {
    const { container } = render(
      <StatCard
        label="Top Campaign"
        value="Annual Fund"
        className="mission-stat-card--text-value"
      />
    );

    const card = container.querySelector( '.mission-stat-card' );
    expect( card.classList.contains( 'mission-stat-card--text-value' ) ).toBe(
      true
    );
  } );
} );
