/* eslint-env jest */

import { renderHook, act } from '@testing-library/react';
import { usePersistedView } from '../use-persisted-view';

const STORAGE_KEY = 'test_view';
const FULL_KEY = 'mission_view_test_view';

const DEFAULT_VIEW = {
  type: 'table',
  fields: [ 'name', 'email' ],
  perPage: 25,
  sort: { field: 'name', direction: 'asc' },
  search: '',
  filters: [],
  page: 1,
};

describe( 'usePersistedView', () => {
  beforeEach( () => {
    localStorage.clear();
    jest.useFakeTimers();
  } );

  afterEach( () => {
    jest.useRealTimers();
  } );

  it( 'returns defaultView when localStorage is empty', () => {
    const { result } = renderHook( () =>
      usePersistedView( STORAGE_KEY, DEFAULT_VIEW )
    );
    expect( result.current.view ).toEqual( DEFAULT_VIEW );
  } );

  it( 'restores persisted fields from localStorage on init', () => {
    localStorage.setItem(
      FULL_KEY,
      JSON.stringify( {
        perPage: 50,
        sort: { field: 'email', direction: 'desc' },
      } )
    );

    const { result } = renderHook( () =>
      usePersistedView( STORAGE_KEY, DEFAULT_VIEW )
    );

    expect( result.current.view.perPage ).toBe( 50 );
    expect( result.current.view.sort ).toEqual( {
      field: 'email',
      direction: 'desc',
    } );
    // Non-persisted fields remain at defaults.
    expect( result.current.view.search ).toBe( '' );
  } );

  it( 'persists to localStorage after 300ms debounce', () => {
    const { result } = renderHook( () =>
      usePersistedView( STORAGE_KEY, DEFAULT_VIEW )
    );

    act( () => {
      result.current.setView( { ...DEFAULT_VIEW, perPage: 50 } );
    } );

    // Not yet persisted.
    expect( localStorage.getItem( FULL_KEY ) ).toBeNull();

    act( () => {
      jest.advanceTimersByTime( 300 );
    } );

    const stored = JSON.parse( localStorage.getItem( FULL_KEY ) );
    expect( stored.perPage ).toBe( 50 );
  } );

  it( 'isModified returns true when view differs from default', () => {
    const { result } = renderHook( () =>
      usePersistedView( STORAGE_KEY, DEFAULT_VIEW )
    );

    act( () => {
      result.current.setView( { ...DEFAULT_VIEW, perPage: 50 } );
    } );

    expect( result.current.isModified ).toBe( true );
  } );

  it( 'isModified returns false when view matches default', () => {
    const { result } = renderHook( () =>
      usePersistedView( STORAGE_KEY, DEFAULT_VIEW )
    );

    expect( result.current.isModified ).toBe( false );
  } );

  it( 'resetToDefault clears localStorage and resets persisted fields', () => {
    localStorage.setItem( FULL_KEY, JSON.stringify( { perPage: 50 } ) );

    const { result } = renderHook( () =>
      usePersistedView( STORAGE_KEY, DEFAULT_VIEW )
    );

    act( () => {
      result.current.resetToDefault();
    } );

    expect( localStorage.getItem( FULL_KEY ) ).toBeNull();
    expect( result.current.view.perPage ).toBe( DEFAULT_VIEW.perPage );
  } );

  it( 'falls back to defaultView when localStorage contains invalid JSON', () => {
    localStorage.setItem( FULL_KEY, '{invalid' );

    const { result } = renderHook( () =>
      usePersistedView( STORAGE_KEY, DEFAULT_VIEW )
    );

    expect( result.current.view ).toEqual( DEFAULT_VIEW );
  } );

  it( 'persists layout density to localStorage', () => {
    const viewWithLayout = {
      ...DEFAULT_VIEW,
      layout: { density: 'compact' },
    };

    const { result } = renderHook( () =>
      usePersistedView( STORAGE_KEY, DEFAULT_VIEW )
    );

    act( () => {
      result.current.setView( viewWithLayout );
    } );

    act( () => {
      jest.advanceTimersByTime( 300 );
    } );

    const stored = JSON.parse( localStorage.getItem( FULL_KEY ) );
    expect( stored.density ).toBe( 'compact' );
  } );

  it( 'resetToDefault preserves search, filters, and page', () => {
    const { result } = renderHook( () =>
      usePersistedView( STORAGE_KEY, DEFAULT_VIEW )
    );

    act( () => {
      result.current.setView( {
        ...DEFAULT_VIEW,
        perPage: 50,
        search: 'john',
        filters: [ { field: 'status', value: 'active' } ],
        page: 3,
      } );
    } );

    act( () => {
      result.current.resetToDefault();
    } );

    expect( result.current.view.search ).toBe( 'john' );
    expect( result.current.view.filters ).toEqual( [
      { field: 'status', value: 'active' },
    ] );
    expect( result.current.view.page ).toBe( 3 );
    // But persisted fields are reset.
    expect( result.current.view.perPage ).toBe( DEFAULT_VIEW.perPage );
  } );
} );
