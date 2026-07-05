/* eslint-env jest */

import { render, screen, fireEvent } from '@testing-library/react';
import TransactionsTableCard from '../TransactionsTableCard';

const makeTxns = ( count ) =>
  Array.from( { length: count }, ( _, i ) => ( {
    id: i + 1,
    donor_id: i + 1,
    donor_name: `Donor ${ i + 1 }`,
    amount: 5000,
    status: 'completed',
    type: 'one_time',
    date_created: '2026-07-01 12:00:00',
  } ) );

describe( 'TransactionsTableCard', () => {
  it( 'shows the empty state without transactions', () => {
    render(
      <TransactionsTableCard
        title="Donations"
        transactions={ [] }
        columns={ [ 'date', 'donor', 'amount' ] }
      />
    );
    expect( screen.getByText( 'No donations yet.' ) ).toBeTruthy();
  } );

  it( 'collapses to collapsedCount rows with a Show all button', () => {
    render(
      <TransactionsTableCard
        title="Donations"
        transactions={ makeTxns( 12 ) }
        columns={ [ 'date', 'donor', 'amount' ] }
        collapsedCount={ 5 }
      />
    );

    expect( screen.getAllByRole( 'row' ) ).toHaveLength( 6 ); // header + 5
    const showAll = screen.getByText( 'Show all 12 donations' );

    fireEvent.click( showAll );

    // Expanded: first page of 10 rows, no Show all button.
    expect( screen.getAllByRole( 'row' ) ).toHaveLength( 11 );
    expect( screen.queryByText( 'Show all 12 donations' ) ).toBeNull();
  } );

  it( 'paginates without collapsedCount', () => {
    render(
      <TransactionsTableCard
        title="Donations"
        transactions={ makeTxns( 12 ) }
        columns={ [ 'date', 'donor', 'amount' ] }
      />
    );

    expect( screen.getAllByRole( 'row' ) ).toHaveLength( 11 ); // header + 10
    expect( screen.queryByText( /Show all/ ) ).toBeNull();
  } );

  it( 'clamps the page when the transactions prop shrinks', () => {
    const { rerender } = render(
      <TransactionsTableCard
        title="Donations"
        transactions={ makeTxns( 25 ) }
        columns={ [ 'date', 'donor', 'amount' ] }
      />
    );

    fireEvent.click( screen.getByRole( 'button', { name: '3' } ) );
    expect( screen.getByText( 'Donor 21' ) ).toBeTruthy();

    // A background refetch shrinks the list to a single page.
    rerender(
      <TransactionsTableCard
        title="Donations"
        transactions={ makeTxns( 8 ) }
        columns={ [ 'date', 'donor', 'amount' ] }
      />
    );

    expect( screen.getAllByRole( 'row' ) ).toHaveLength( 9 ); // header + 8
    expect( screen.getByText( 'Donor 1' ) ).toBeTruthy();
  } );
} );
