/* eslint-env jest */

import { render, screen, fireEvent } from '@testing-library/react';
import FrequencyChips, { FREQUENCIES } from '../FrequencyChips';

describe( 'FREQUENCIES constant', () => {
  it( 'has the 5 expected frequency IDs', () => {
    const ids = FREQUENCIES.map( ( f ) => f.id );
    expect( ids ).toEqual( [
      'one_time',
      'weekly',
      'monthly',
      'quarterly',
      'annually',
    ] );
  } );
} );

describe( 'FrequencyChips', () => {
  it( 'calls onChange with the frequency added when toggling an unselected chip', () => {
    const onChange = jest.fn();
    render(
      <FrequencyChips selected={ [ 'one_time' ] } onChange={ onChange } />
    );

    fireEvent.click( screen.getByText( 'Monthly' ) );
    expect( onChange ).toHaveBeenCalledWith( [ 'one_time', 'monthly' ] );
  } );

  it( 'calls onChange without the frequency when toggling a selected chip', () => {
    const onChange = jest.fn();
    render(
      <FrequencyChips
        selected={ [ 'one_time', 'monthly' ] }
        onChange={ onChange }
      />
    );

    fireEvent.click( screen.getByText( 'Monthly' ) );
    expect( onChange ).toHaveBeenCalledWith( [ 'one_time' ] );
  } );

  it( 'applies is-selected class only to selected chips', () => {
    render(
      <FrequencyChips selected={ [ 'monthly' ] } onChange={ jest.fn() } />
    );

    expect( screen.getByText( 'Monthly' ).className ).toContain(
      'is-selected'
    );
    expect( screen.getByText( 'Weekly' ).className ).not.toContain(
      'is-selected'
    );
  } );

  it( 'does not call onChange when trying to deselect the last remaining frequency', () => {
    const onChange = jest.fn();
    render(
      <FrequencyChips selected={ [ 'monthly' ] } onChange={ onChange } />
    );

    fireEvent.click( screen.getByText( 'Monthly' ) );
    expect( onChange ).not.toHaveBeenCalled();
  } );
} );
