/* eslint-env jest */

import { render, screen, fireEvent, act } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import ImportPanel from '../ImportPanel';

// @wordpress/api-fetch is a webpack external (not installed), so the mock
// must be virtual.
jest.mock(
  '@wordpress/api-fetch',
  () => ( {
    __esModule: true,
    default: jest.fn(),
  } ),
  { virtual: true }
);

const VALIDATION_FIXTURE = {
  file_id: 'file_1',
  filename: 'donors.csv',
  filesize: 2048,
  rows_detected: 10,
  rows_importable: 10,
  rows_skipped: 0,
  columns_matched: 2,
  duplicates: 0,
  warnings: [],
  preview_headers: [ 'email', 'first_name' ],
  preview_rows: [ [ 'a@example.com', 'A' ] ],
};

const jobFixture = ( overrides = {} ) => ( {
  job_id: 'job_1',
  status: 'processing',
  total_rows: 10,
  processed_rows: 5,
  percentage: 50,
  imported: 5,
  skipped: 0,
  updated: 0,
  errors: 0,
  ...overrides,
} );

/**
 * Point apiFetch at a route map: first key found in options.path wins.
 * Handlers receive the request options; non-function values resolve as-is.
 *
 * @param {Object} routes Map of path fragment to response or handler.
 */
function mockRoutes( routes ) {
  const merged = {
    'import/columns': {
      columns: [ 'email', 'first_name' ],
      required: [ 'email' ],
    },
    'import/active': {},
    ...routes,
  };
  apiFetch.mockImplementation( ( options ) => {
    const path = options.path || '';
    for ( const [ fragment, handler ] of Object.entries( merged ) ) {
      if ( path.includes( fragment ) ) {
        const result =
          typeof handler === 'function' ? handler( options ) : handler;
        return result instanceof Promise ? result : Promise.resolve( result );
      }
    }
    return Promise.reject( { message: `Unmocked path: ${ path }` } );
  } );
}

/**
 * Count apiFetch calls whose path contains the fragment.
 *
 * @param {string} fragment Path fragment.
 * @return {number} Matching call count.
 */
function callsTo( fragment ) {
  return apiFetch.mock.calls.filter( ( [ options ] ) =>
    ( options.path || '' ).includes( fragment )
  ).length;
}

/**
 * Fire a file selection through the hidden input.
 *
 * @param {HTMLElement} container Render container.
 * @param {File}        file      File to select.
 */
async function selectFile( container, file ) {
  const input = container.querySelector( 'input[type="file"]' );
  await act( async () => {
    fireEvent.change( input, { target: { files: [ file ] } } );
  } );
}

/**
 * Render the panel and flush the initial columns/active fetches.
 *
 * @return {Object} Testing-library render utils.
 */
async function renderPanel() {
  let utils;
  await act( async () => {
    utils = render( <ImportPanel /> );
  } );
  return utils;
}

/**
 * Drive the panel from upload through validation into a started import.
 *
 * @param {HTMLElement} container Render container.
 */
async function startImport( container ) {
  await selectFile(
    container,
    new File( [ 'email\na@example.com' ], 'donors.csv', { type: 'text/csv' } )
  );
  await act( async () => {
    fireEvent.click(
      screen.getByRole( 'button', { name: /Import 10 Donors/i } )
    );
  } );
}

describe( 'ImportPanel', () => {
  beforeEach( () => {
    jest.useFakeTimers();
    window.missiondpAdmin = {
      currency: 'USD',
      restUrl: 'http://example.com/wp-json/mission-donation-platform/v1/',
      restNonce: 'nonce',
      adminUrl: 'http://example.com/wp-admin/',
    };
    apiFetch.mockReset();
    mockRoutes( {} );
  } );

  afterEach( () => {
    jest.useRealTimers();
    delete window.missiondpAdmin;
  } );

  // -----------------------------------------------------------------------
  // Upload state.
  // -----------------------------------------------------------------------

  it( 'renders the upload state with expected columns', async () => {
    await renderPanel();

    expect( screen.getByText( 'Import Data' ) ).toBeTruthy();
    expect( screen.getByText( 'Drag and drop your file here' ) ).toBeTruthy();
    // Columns from the API render as code chips.
    expect( screen.getByText( 'first_name' ) ).toBeTruthy();
    expect( callsTo( 'import/columns?type=donors' ) ).toBe( 1 );
  } );

  it( 'rejects unsupported file extensions without an API call', async () => {
    const { container } = await renderPanel();

    await selectFile(
      container,
      new File( [ 'x' ], 'data.txt', { type: 'text/plain' } )
    );

    expect( screen.getByRole( 'alert' ).textContent ).toContain(
      'Only .csv and .json files are supported.'
    );
    expect( callsTo( 'import/validate' ) ).toBe( 0 );
  } );

  it( 'rejects files over the 10 MB limit without an API call', async () => {
    const { container } = await renderPanel();

    const big = new File( [ 'x' ], 'big.csv', { type: 'text/csv' } );
    Object.defineProperty( big, 'size', { value: 10 * 1024 * 1024 + 1 } );
    await selectFile( container, big );

    expect( screen.getByRole( 'alert' ).textContent ).toContain(
      'File exceeds the 10 MB limit.'
    );
    expect( callsTo( 'import/validate' ) ).toBe( 0 );
  } );

  // -----------------------------------------------------------------------
  // Validation state.
  // -----------------------------------------------------------------------

  it( 'shows the validation summary after a successful upload', async () => {
    mockRoutes( { 'import/validate': VALIDATION_FIXTURE } );
    const { container } = await renderPanel();

    await selectFile(
      container,
      new File( [ 'email\na@example.com' ], 'donors.csv', { type: 'text/csv' } )
    );

    expect( screen.getByText( 'File Summary' ) ).toBeTruthy();
    expect( screen.getByText( 'donors.csv' ) ).toBeTruthy();
    expect( screen.getByText( '2.0 KB' ) ).toBeTruthy();
    expect( screen.getByText( 'rows detected' ) ).toBeTruthy();
    expect(
      screen.getByRole( 'button', { name: /Import 10 Donors/i } )
    ).toBeTruthy();
  } );

  it( 'stays in the upload state when validation fails', async () => {
    mockRoutes( {
      'import/validate': () =>
        Promise.reject( { message: 'The file is empty.' } ),
    } );
    const { container } = await renderPanel();

    await selectFile(
      container,
      new File( [ '' ], 'donors.csv', { type: 'text/csv' } )
    );

    expect( screen.getByRole( 'alert' ).textContent ).toContain(
      'The file is empty.'
    );
    expect( screen.getByText( 'Import Data' ) ).toBeTruthy();
  } );

  it( 'sends the selected duplicate strategy when starting the import', async () => {
    mockRoutes( {
      'import/validate': { ...VALIDATION_FIXTURE, duplicates: 4 },
      'import/start': jobFixture(),
      'import/status': jobFixture(),
    } );
    const { container } = await renderPanel();

    await selectFile(
      container,
      new File( [ 'email\na@example.com' ], 'donors.csv', { type: 'text/csv' } )
    );

    // With duplicates present, the strategy picker appears.
    expect( screen.getByText( 'Import Options' ) ).toBeTruthy();
    await act( async () => {
      fireEvent.click( screen.getByText( 'Update existing' ) );
    } );

    await act( async () => {
      fireEvent.click(
        screen.getByRole( 'button', { name: /Import 10 Donors/i } )
      );
    } );

    const startCall = apiFetch.mock.calls.find( ( [ options ] ) =>
      ( options.path || '' ).includes( 'import/start' )
    );
    expect( startCall[ 0 ].data ).toEqual( {
      file_id: 'file_1',
      duplicate_strategy: 'update',
    } );
  } );

  // -----------------------------------------------------------------------
  // Progress state and polling.
  // -----------------------------------------------------------------------

  it( 'enters the progress state and polls every two seconds', async () => {
    mockRoutes( {
      'import/validate': VALIDATION_FIXTURE,
      'import/start': jobFixture(),
      'import/status': jobFixture(),
    } );
    const { container } = await renderPanel();

    await startImport( container );

    expect( screen.getByText( 'Importing your donors' ) ).toBeTruthy();
    const initial = callsTo( 'import/status' );

    await act( async () => {
      jest.advanceTimersByTime( 2000 );
    } );
    expect( callsTo( 'import/status' ) ).toBe( initial + 1 );

    await act( async () => {
      jest.advanceTimersByTime( 4000 );
    } );
    expect( callsTo( 'import/status' ) ).toBe( initial + 3 );
  } );

  it( 'reaches the success state and stops polling when the job completes', async () => {
    mockRoutes( {
      'import/validate': VALIDATION_FIXTURE,
      'import/start': jobFixture(),
      'import/status': jobFixture( {
        status: 'completed',
        processed_rows: 10,
        percentage: 100,
        imported: 8,
        skipped: 2,
      } ),
    } );
    const { container } = await renderPanel();

    await startImport( container );

    // First poll returns the terminal status; the success screen waits out
    // the minimum progress duration (2500ms for 10 rows).
    await act( async () => {
      jest.advanceTimersByTime( 3000 );
    } );

    expect( screen.getByText( 'Import complete' ) ).toBeTruthy();
    // Result lines join into a single summary sentence.
    expect(
      screen.getByText(
        '8 donors were successfully imported. 2 duplicates were skipped.'
      )
    ).toBeTruthy();

    // Polling stopped: no further status calls however long we wait.
    const settled = callsTo( 'import/status' );
    await act( async () => {
      jest.advanceTimersByTime( 10000 );
    } );
    expect( callsTo( 'import/status' ) ).toBe( settled );
  } );

  it( 'reaches the failed state with the backend error message', async () => {
    mockRoutes( {
      'import/validate': VALIDATION_FIXTURE,
      'import/start': jobFixture(),
      'import/status': jobFixture( {
        status: 'failed',
        last_error: 'Row 7 exploded.',
        imported: 3,
      } ),
    } );
    const { container } = await renderPanel();

    await startImport( container );
    await act( async () => {
      jest.advanceTimersByTime( 3000 );
    } );

    expect( screen.getByText( 'Import failed' ) ).toBeTruthy();
    expect( screen.getByText( 'Row 7 exploded.' ) ).toBeTruthy();
    expect(
      screen.getByText( '3 imported, 0 updated, 0 skipped before the failure.' )
    ).toBeTruthy();
  } );

  it( 'shows the start error and returns to validation when the import cannot start', async () => {
    mockRoutes( {
      'import/validate': VALIDATION_FIXTURE,
      'import/start': () => Promise.reject( { message: 'Server said no.' } ),
    } );
    const { container } = await renderPanel();

    await startImport( container );

    expect( screen.getByRole( 'alert' ).textContent ).toContain(
      'Server said no.'
    );
    expect( screen.getByText( 'File Summary' ) ).toBeTruthy();
  } );

  it( 'resumes the existing job when start returns a 409 conflict', async () => {
    mockRoutes( {
      'import/validate': VALIDATION_FIXTURE,
      'import/start': () =>
        Promise.reject( {
          code: 'import_in_progress',
          data: { job: jobFixture( { job_id: 'job_existing' } ) },
        } ),
      'import/status': jobFixture( { job_id: 'job_existing' } ),
    } );
    const { container } = await renderPanel();

    await startImport( container );

    // Resumed into progress instead of erroring.
    expect( screen.getByText( 'Importing your donors' ) ).toBeTruthy();

    await act( async () => {
      jest.advanceTimersByTime( 2000 );
    } );
    const statusCall = apiFetch.mock.calls.find( ( [ options ] ) =>
      ( options.path || '' ).includes( 'import/status' )
    );
    expect( statusCall[ 0 ].path ).toContain( 'job_id=job_existing' );
  } );

  it( 'resumes an in-flight job found on mount', async () => {
    mockRoutes( {
      'import/active': { job: jobFixture( { job_id: 'job_resumed' } ) },
      'import/status': jobFixture( { job_id: 'job_resumed' } ),
    } );
    await renderPanel();

    expect( screen.getByText( 'Importing your donors' ) ).toBeTruthy();
  } );

  it( 'stops polling on unmount', async () => {
    mockRoutes( {
      'import/validate': VALIDATION_FIXTURE,
      'import/start': jobFixture(),
      'import/status': jobFixture(),
    } );
    const { container, unmount } = await renderPanel();

    await startImport( container );
    await act( async () => {
      jest.advanceTimersByTime( 2000 );
    } );
    const beforeUnmount = callsTo( 'import/status' );

    unmount();
    await act( async () => {
      jest.advanceTimersByTime( 10000 );
    } );

    expect( callsTo( 'import/status' ) ).toBe( beforeUnmount );
  } );

  it( 'returns to the upload state from the success screen', async () => {
    mockRoutes( {
      'import/validate': VALIDATION_FIXTURE,
      'import/start': jobFixture(),
      'import/status': jobFixture( {
        status: 'completed',
        processed_rows: 10,
        percentage: 100,
        imported: 10,
      } ),
    } );
    const { container } = await renderPanel();

    await startImport( container );
    await act( async () => {
      jest.advanceTimersByTime( 3000 );
    } );
    expect( screen.getByText( 'Import complete' ) ).toBeTruthy();

    await act( async () => {
      fireEvent.click(
        screen.getByRole( 'button', { name: /Import more data/i } )
      );
    } );

    expect( screen.getByText( 'Import Data' ) ).toBeTruthy();
  } );
} );
