/* eslint-env jest */

import { needsResize, resizeImageFile } from '../image-resize';

const MB = 1024 * 1024;

describe( 'needsResize', () => {
  it( 'passes small files through', () => {
    expect( needsResize( { type: 'image/jpeg', size: MB } ) ).toBe( false );
  } );

  it( 'resizes files over the re-encode threshold', () => {
    expect( needsResize( { type: 'image/jpeg', size: 3 * MB } ) ).toBe( true );
  } );

  it( 'never resizes GIFs', () => {
    expect( needsResize( { type: 'image/gif', size: 10 * MB } ) ).toBe( false );
  } );

  it( 'tightens the threshold to a stricter server limit', () => {
    expect(
      needsResize( { type: 'image/jpeg', size: 1.5 * MB }, { maxBytes: MB } )
    ).toBe( true );
    expect(
      needsResize( { type: 'image/jpeg', size: 0.8 * MB }, { maxBytes: MB } )
    ).toBe( false );
  } );
} );

describe( 'resizeImageFile', () => {
  afterEach( () => {
    delete window.createImageBitmap;
    jest.restoreAllMocks();
  } );

  /**
   * Stub createImageBitmap and canvas so the encode path runs under jsdom.
   *
   * @param {Object} bitmap Fake bitmap ({ width, height }).
   * @param {Blob}   blob   Blob "produced" by canvas.toBlob.
   * @return {Object} The fake canvas, for asserting dimensions and encoding.
   */
  function stubCanvasPipeline( bitmap, blob ) {
    window.createImageBitmap = jest
      .fn()
      .mockResolvedValue( { ...bitmap, close: jest.fn() } );

    const canvas = {
      getContext: () => ( { drawImage: jest.fn(), fillRect: jest.fn() } ),
      toBlob: jest.fn( ( resolve, type ) => {
        canvas.encodedType = type;
        resolve( typeof blob === 'function' ? blob( type ) : blob );
      } ),
    };
    jest.spyOn( document, 'createElement' ).mockReturnValue( canvas );

    return canvas;
  }

  it( 'returns the original when the browser lacks createImageBitmap', async () => {
    const file = { type: 'image/jpeg', size: 10 * MB, name: 'big.jpg' };

    await expect( resizeImageFile( file ) ).resolves.toBe( file );
  } );

  it( 'returns the original when no resize is needed', async () => {
    window.createImageBitmap = jest.fn();
    const file = { type: 'image/jpeg', size: MB, name: 'small.jpg' };

    await expect( resizeImageFile( file ) ).resolves.toBe( file );
    expect( window.createImageBitmap ).not.toHaveBeenCalled();
  } );

  it( 'downscales oversized photos and swaps the extension to .jpg', async () => {
    const canvas = stubCanvasPipeline(
      { width: 5120, height: 2560 },
      new Blob( [ new Uint8Array( 100 ) ] )
    );
    const file = new File( [ new Uint8Array( 3 * MB ) ], 'holiday photo.jpeg', {
      type: 'image/jpeg',
    } );

    const result = await resizeImageFile( file );

    expect( canvas.width ).toBe( 2560 );
    expect( canvas.height ).toBe( 1280 );
    expect( result.name ).toBe( 'holiday photo.jpg' );
    expect( result.type ).toBe( 'image/jpeg' );
    expect( result.size ).toBe( 100 );
  } );

  it( 'keeps PNG as PNG so transparency survives', async () => {
    const canvas = stubCanvasPipeline(
      { width: 4000, height: 4000 },
      new Blob( [ new Uint8Array( 100 ) ] )
    );
    const file = new File( [ new Uint8Array( 3 * MB ) ], 'logo.png', {
      type: 'image/png',
    } );

    const result = await resizeImageFile( file );

    expect( canvas.encodedType ).toBe( 'image/png' );
    expect( result.name ).toBe( 'logo.png' );
    expect( result.type ).toBe( 'image/png' );
  } );

  it( 'falls back to JPEG when the PNG re-encode still exceeds the limit', async () => {
    // Noise/photo PNGs stay huge even after downscaling.
    stubCanvasPipeline( { width: 4000, height: 3000 }, ( type ) =>
      type === 'image/png'
        ? new Blob( [ new Uint8Array( 8 * MB ) ] )
        : new Blob( [ new Uint8Array( MB ) ] )
    );
    const file = new File( [ new Uint8Array( 20 * MB ) ], 'photo.png', {
      type: 'image/png',
    } );

    const result = await resizeImageFile( file, { maxBytes: 5 * MB } );

    expect( result.type ).toBe( 'image/jpeg' );
    expect( result.name ).toBe( 'photo.jpg' );
    expect( result.size ).toBe( MB );
  } );

  it( 'keeps the original when re-encoding does not shrink it', async () => {
    stubCanvasPipeline(
      { width: 3000, height: 2000 },
      new Blob( [ new Uint8Array( 4 * MB ) ] )
    );
    const file = new File( [ new Uint8Array( 3 * MB ) ], 'optimized.jpg', {
      type: 'image/jpeg',
    } );

    await expect( resizeImageFile( file ) ).resolves.toBe( file );
  } );

  it( 'falls back to the original when decoding fails', async () => {
    window.createImageBitmap = jest
      .fn()
      .mockRejectedValue( new Error( 'broken image' ) );
    const file = { type: 'image/jpeg', size: 10 * MB, name: 'corrupt.jpg' };

    await expect( resizeImageFile( file ) ).resolves.toBe( file );
  } );
} );
