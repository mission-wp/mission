/* eslint-env jest */

import { renderHook, waitFor, act } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { usePaginatedFetch } from '../use-paginated-fetch';

jest.mock(
  '@wordpress/api-fetch',
  () => ( {
    __esModule: true,
    default: jest.fn(),
  } ),
  { virtual: true }
);

const BASE_VIEW = {
  page: 1,
  perPage: 25,
  search: '',
  filters: [],
  sort: { field: 'date_created', direction: 'desc' },
};

function mockResponse( items, { total = items.length, pages = 1 } = {} ) {
  return {
    headers: {
      get: ( header ) =>
        header === 'X-WP-Total' ? String( total ) : String( pages ),
    },
    json: async () => items,
  };
}

function requestedQuery( call = 0 ) {
  const path = apiFetch.mock.calls[ call ][ 0 ].path;
  return new URLSearchParams( path.split( '?' )[ 1 ] );
}

describe( 'usePaginatedFetch', () => {
  beforeEach( () => {
    apiFetch.mockReset();
    apiFetch.mockResolvedValue( mockResponse( [] ) );
  } );

  it( 'builds pagination and sort params from the view', async () => {
    const { result } = renderHook( () =>
      usePaginatedFetch( {
        path: '/mission-donation-platform/v1/donors',
        view: { ...BASE_VIEW, page: 3, perPage: 50 },
      } )
    );

    await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

    const params = requestedQuery();
    expect( params.get( 'page' ) ).toBe( '3' );
    expect( params.get( 'per_page' ) ).toBe( '50' );
    expect( params.get( 'order' ) ).toBe( 'DESC' );
    expect( params.get( 'orderby' ) ).toBe( 'date_created' );
    expect( params.has( 'search' ) ).toBe( false );
  } );

  it( 'falls back to defaultOrderby when the view has no sort', async () => {
    // Stable reference: an inline `sort: {}` would change identity per render.
    const view = { ...BASE_VIEW, sort: {} };

    const { result } = renderHook( () =>
      usePaginatedFetch( {
        path: '/mission-donation-platform/v1/campaigns',
        view,
        defaultOrderby: 'date',
      } )
    );

    await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

    expect( requestedQuery().get( 'orderby' ) ).toBe( 'date' );
  } );

  it( 'includes search, mapped filters, and extra params', async () => {
    // Stable reference: view identity only changes on real view updates.
    const view = {
      ...BASE_VIEW,
      search: 'jane',
      filters: [
        { field: 'status', value: 'completed' },
        { field: 'ignored', value: 'x' },
      ],
    };

    const { result } = renderHook( () =>
      usePaginatedFetch( {
        path: '/mission-donation-platform/v1/transactions',
        view,
        filterFields: [ 'status', 'campaign_id' ],
        extraParams: { campaign_id: '7' },
      } )
    );

    await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

    const params = requestedQuery();
    expect( params.get( 'search' ) ).toBe( 'jane' );
    expect( params.get( 'status' ) ).toBe( 'completed' );
    expect( params.get( 'campaign_id' ) ).toBe( '7' );
    expect( params.has( 'ignored' ) ).toBe( false );
  } );

  it( 'returns the items and header totals', async () => {
    apiFetch.mockResolvedValue(
      mockResponse( [ { id: 1 }, { id: 2 } ], { total: 60, pages: 3 } )
    );

    const { result } = renderHook( () =>
      usePaginatedFetch( {
        path: '/mission-donation-platform/v1/donors',
        view: BASE_VIEW,
      } )
    );

    await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

    expect( result.current.data ).toEqual( [ { id: 1 }, { id: 2 } ] );
    expect( result.current.totalItems ).toBe( 60 );
    expect( result.current.totalPages ).toBe( 3 );
  } );

  it( 'resets to an empty list with zero totals on error', async () => {
    apiFetch.mockResolvedValueOnce(
      mockResponse( [ { id: 1 } ], { total: 1, pages: 1 } )
    );

    const { result, rerender } = renderHook(
      ( { view } ) =>
        usePaginatedFetch( {
          path: '/mission-donation-platform/v1/donors',
          view,
        } ),
      { initialProps: { view: BASE_VIEW } }
    );

    await waitFor( () => expect( result.current.totalItems ).toBe( 1 ) );

    apiFetch.mockRejectedValueOnce( new Error( 'boom' ) );
    rerender( { view: { ...BASE_VIEW, page: 2 } } );

    await waitFor( () => expect( result.current.totalItems ).toBe( 0 ) );
    expect( result.current.data ).toEqual( [] );
    expect( result.current.totalPages ).toBe( 0 );
    expect( result.current.isLoading ).toBe( false );
  } );

  it( 'refetches when refresh() is called', async () => {
    const { result } = renderHook( () =>
      usePaginatedFetch( {
        path: '/mission-donation-platform/v1/donors',
        view: BASE_VIEW,
      } )
    );

    await waitFor( () => expect( result.current.isLoading ).toBe( false ) );
    expect( apiFetch ).toHaveBeenCalledTimes( 1 );

    await act( async () => {
      await result.current.refresh();
    } );

    expect( apiFetch ).toHaveBeenCalledTimes( 2 );
  } );

  it( 'refetches when the view changes but not on unrelated rerenders', async () => {
    const { result, rerender } = renderHook(
      ( { view } ) =>
        usePaginatedFetch( {
          path: '/mission-donation-platform/v1/donors',
          view,
          // Inline literals on purpose: they must not retrigger fetches.
          filterFields: [ 'status' ],
          extraParams: {},
        } ),
      { initialProps: { view: BASE_VIEW } }
    );

    await waitFor( () => expect( result.current.isLoading ).toBe( false ) );
    expect( apiFetch ).toHaveBeenCalledTimes( 1 );

    rerender( { view: BASE_VIEW } );
    await waitFor( () => expect( result.current.isLoading ).toBe( false ) );
    expect( apiFetch ).toHaveBeenCalledTimes( 1 );

    rerender( { view: { ...BASE_VIEW, page: 2 } } );
    await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
    expect( requestedQuery( 1 ).get( 'page' ) ).toBe( '2' );
  } );
} );
