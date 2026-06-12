/* eslint-env jest */

import {
  formatBytes,
  computeMinDuration,
  scaleCounter,
  MIN_PROGRESS_FLOOR_MS,
  MIN_PROGRESS_CEIL_MS,
} from '../import-utils';

describe( 'formatBytes', () => {
  it( 'shows bytes below 1 KB', () => {
    expect( formatBytes( 0 ) ).toBe( '0 B' );
    expect( formatBytes( 1023 ) ).toBe( '1023 B' );
  } );

  it( 'shows kilobytes with one decimal', () => {
    expect( formatBytes( 1024 ) ).toBe( '1.0 KB' );
    expect( formatBytes( 1536 ) ).toBe( '1.5 KB' );
    expect( formatBytes( 1024 * 1024 - 1 ) ).toBe( '1024.0 KB' );
  } );

  it( 'shows megabytes with one decimal', () => {
    expect( formatBytes( 1024 * 1024 ) ).toBe( '1.0 MB' );
    expect( formatBytes( 10 * 1024 * 1024 ) ).toBe( '10.0 MB' );
  } );
} );

describe( 'computeMinDuration', () => {
  it( 'returns the floor for empty or invalid row counts', () => {
    expect( computeMinDuration( 0 ) ).toBe( MIN_PROGRESS_FLOOR_MS );
    expect( computeMinDuration( null ) ).toBe( MIN_PROGRESS_FLOOR_MS );
    expect( computeMinDuration( -5 ) ).toBe( MIN_PROGRESS_FLOOR_MS );
  } );

  it( 'clamps tiny imports to the floor', () => {
    // 5 rows * 250ms = 1250ms, below the 2500ms floor.
    expect( computeMinDuration( 5 ) ).toBe( MIN_PROGRESS_FLOOR_MS );
  } );

  it( 'scales with row count between the clamps', () => {
    expect( computeMinDuration( 20 ) ).toBe( 5000 );
  } );

  it( 'clamps huge imports to the ceiling', () => {
    // 100 rows * 250ms = 25000ms, above the 8000ms ceiling.
    expect( computeMinDuration( 100 ) ).toBe( MIN_PROGRESS_CEIL_MS );
    expect( computeMinDuration( 1000000 ) ).toBe( MIN_PROGRESS_CEIL_MS );
  } );
} );

describe( 'scaleCounter', () => {
  it( 'returns zero before anything is processed', () => {
    expect( scaleCounter( 10, 0, 0 ) ).toBe( 0 );
  } );

  it( 'scales proportionally to displayed progress', () => {
    // Backend processed 100 with 40 imported; display shows 50 processed.
    expect( scaleCounter( 40, 50, 100 ) ).toBe( 20 );
  } );

  it( 'never exceeds the real value', () => {
    expect( scaleCounter( 40, 100, 100 ) ).toBe( 40 );
    expect( scaleCounter( 40, 150, 100 ) ).toBe( 40 );
  } );

  it( 'is monotonic as displayed progress advances', () => {
    let previous = 0;
    for ( let processed = 0; processed <= 100; processed += 10 ) {
      const value = scaleCounter( 37, processed, 100 );
      expect( value ).toBeGreaterThanOrEqual( previous );
      previous = value;
    }
    expect( previous ).toBe( 37 );
  } );
} );
