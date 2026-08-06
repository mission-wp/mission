/* eslint-env jest */

import { render, screen } from '@testing-library/react';
import GoalMeter, { daysUntil } from '../GoalMeter';

describe( 'daysUntil', () => {
  afterEach( () => {
    jest.useRealTimers();
  } );

  it( 'returns null for empty or invalid dates', () => {
    expect( daysUntil( '' ) ).toBeNull();
    expect( daysUntil( null ) ).toBeNull();
    expect( daysUntil( 'not-a-date' ) ).toBeNull();
  } );

  it( 'returns whole days remaining for a future date', () => {
    jest.useFakeTimers().setSystemTime( new Date( '2026-07-04T12:00:00' ) );
    expect( daysUntil( '2026-07-28 00:00:00' ) ).toBe( 24 );
  } );

  it( 'returns null for a past date', () => {
    jest.useFakeTimers().setSystemTime( new Date( '2026-07-04T12:00:00' ) );
    expect( daysUntil( '2026-07-01 00:00:00' ) ).toBeNull();
  } );
} );

describe( 'GoalMeter', () => {
  it( 'renders nothing without a positive goal', () => {
    const { container } = render( <GoalMeter raised={ 100 } goal={ 0 } /> );
    expect( container.firstChild ).toBeNull();
  } );

  it( 'shows the percentage of goal, capped at 100', () => {
    render( <GoalMeter raised={ 8000 } goal={ 10000 } /> );
    expect( screen.getByText( '80% of goal' ) ).toBeTruthy();

    render( <GoalMeter raised={ 25000 } goal={ 10000 } /> );
    expect( screen.getByText( '100% of goal' ) ).toBeTruthy();
  } );

  it( 'prefers the server-computed days left over the end date math', () => {
    render(
      <GoalMeter
        raised={ 100 }
        goal={ 1000 }
        endDate="2099-01-01 00:00:00"
        daysLeft={ 5 }
      />
    );
    expect( screen.getByText( '5 days left' ) ).toBeTruthy();
  } );
} );
