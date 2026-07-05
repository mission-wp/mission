/**
 * Client-side image downscaling for frontend uploads.
 *
 * Phone photos routinely weigh 5–15 MB, which trips strict host upload
 * limits. Files over the re-encode threshold are downscaled and re-encoded
 * in the browser before upload. Every failure path returns the original
 * file: resizing is an enhancement, never a gate — the server still
 * validates size and type.
 */

// WordPress' own "big image" threshold; it scales anything larger anyway.
const DEFAULT_MAX_EDGE = 2560;

const DEFAULT_RECODE_BYTES = 2 * 1024 * 1024;

const ENCODE_QUALITY = 0.82;

/**
 * Whether a file should be downscaled before upload.
 *
 * @param {Object} file                  File-like object with type and size.
 * @param {Object} [options]             Options.
 * @param {number} [options.maxBytes]    Server upload limit in bytes (0 = unknown).
 * @param {number} [options.recodeBytes] Size above which files are re-encoded.
 * @return {boolean} True when the file should be resized.
 */
export function needsResize(
  file,
  { maxBytes = 0, recodeBytes = DEFAULT_RECODE_BYTES } = {}
) {
  if ( file.type === 'image/gif' ) {
    // Re-encoding would flatten animations to a single frame.
    return false;
  }
  const threshold =
    maxBytes > 0 ? Math.min( recodeBytes, maxBytes ) : recodeBytes;
  return file.size > threshold;
}

/**
 * Downscale an image file for upload.
 *
 * Images that need it are capped to `maxEdge` on their longest side and
 * re-encoded. PNG and WebP keep their type when the result fits the limit
 * (so transparency survives), but fall back to JPEG flattened onto white
 * when it doesn't — a downscaled photo saved as PNG can easily stay over
 * the limit. On very strict limits the edge steps down further. Returns
 * the original file when resizing isn't needed, doesn't help, or fails.
 *
 * @param {File}   file               Image file from a file input.
 * @param {Object} [options]          Options.
 * @param {number} [options.maxBytes] Server upload limit in bytes.
 * @param {number} [options.maxEdge]  Longest allowed edge in pixels.
 * @return {Promise<File>} The resized file, or the original.
 */
export async function resizeImageFile(
  file,
  { maxBytes = 0, maxEdge = DEFAULT_MAX_EDGE } = {}
) {
  if (
    ! needsResize( file, { maxBytes } ) ||
    typeof window.createImageBitmap !== 'function'
  ) {
    return file;
  }

  try {
    // 'from-image' bakes in EXIF rotation so phone photos come out upright.
    const bitmap = await window.createImageBitmap( file, {
      imageOrientation: 'from-image',
    } );
    const limit = maxBytes > 0 ? maxBytes : Infinity;

    const keepType = [ 'image/png', 'image/webp' ].includes( file.type )
      ? file.type
      : 'image/jpeg';
    const attempts = [ { type: keepType, edge: maxEdge } ];
    if ( keepType !== 'image/jpeg' ) {
      attempts.push( { type: 'image/jpeg', edge: maxEdge } );
    }
    attempts.push(
      { type: 'image/jpeg', edge: 1920 },
      { type: 'image/jpeg', edge: 1280 }
    );

    let result = null;
    for ( const { type, edge } of attempts ) {
      const blob = await encodeScaled( bitmap, type, edge );
      if ( blob && blob.size <= limit && blob.size < file.size ) {
        result = { blob, type };
        break;
      }
    }
    bitmap.close();

    if ( ! result ) {
      // Nothing both fit the limit and shrank the file; the caller's size
      // validation decides whether the original is acceptable.
      return file;
    }

    return new File( [ result.blob ], renameForType( file.name, result.type ), {
      type: result.type,
    } );
  } catch {
    return file;
  }
}

/**
 * Draw the bitmap scaled to fit `edge` and encode it.
 *
 * JPEG output is composited onto white first so transparent regions don't
 * turn black.
 *
 * @param {ImageBitmap} bitmap Decoded source image.
 * @param {string}      type   Output mime type.
 * @param {number}      edge   Longest allowed edge in pixels.
 * @return {Promise<Blob|null>} The encoded image.
 */
function encodeScaled( bitmap, type, edge ) {
  const scale = Math.min( 1, edge / Math.max( bitmap.width, bitmap.height ) );
  const width = Math.max( 1, Math.round( bitmap.width * scale ) );
  const height = Math.max( 1, Math.round( bitmap.height * scale ) );

  const canvas = document.createElement( 'canvas' );
  canvas.width = width;
  canvas.height = height;

  const context = canvas.getContext( '2d' );
  if ( type === 'image/jpeg' ) {
    context.fillStyle = '#fff';
    context.fillRect( 0, 0, width, height );
  }
  context.drawImage( bitmap, 0, 0, width, height );

  return new Promise( ( resolve ) =>
    canvas.toBlob( resolve, type, ENCODE_QUALITY )
  );
}

/**
 * Swap the file extension when converting to JPEG.
 *
 * @param {string} name Original filename.
 * @param {string} type Output mime type.
 * @return {string} Filename matching the output type.
 */
function renameForType( name, type ) {
  if ( type !== 'image/jpeg' ) {
    return name;
  }
  const base = name.replace( /\.[^.]+$/, '' );
  return `${ base || name }.jpg`;
}
