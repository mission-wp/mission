/**
 * Pure helpers for the import panel: file-size display and the pacing math
 * that keeps the progress UI honest for tiny and huge imports alike.
 */

// Pace the displayed progress so tiny imports don't jump 0→100% before the
// first poll lands. Capped so huge imports aren't artificially stalled.
export const MIN_MS_PER_ROW = 250;
export const MIN_PROGRESS_FLOOR_MS = 2500;
export const MIN_PROGRESS_CEIL_MS = 8000;
export const POLL_INTERVAL_MS = 2000;

/**
 * Format a byte count for display.
 *
 * @param {number} bytes File size in bytes.
 * @return {string} e.g. "512 B", "1.5 KB", "2.0 MB".
 */
export function formatBytes( bytes ) {
  if ( bytes < 1024 ) {
    return `${ bytes } B`;
  }
  if ( bytes < 1024 * 1024 ) {
    return `${ ( bytes / 1024 ).toFixed( 1 ) } KB`;
  }
  return `${ ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) } MB`;
}

/**
 * Minimum on-screen duration for the progress animation.
 *
 * @param {number} totalRows Total rows in the import job.
 * @return {number} Duration in milliseconds, clamped to the floor/ceiling.
 */
export function computeMinDuration( totalRows ) {
  if ( ! totalRows || totalRows <= 0 ) {
    return MIN_PROGRESS_FLOOR_MS;
  }
  return Math.max(
    MIN_PROGRESS_FLOOR_MS,
    Math.min( MIN_PROGRESS_CEIL_MS, totalRows * MIN_MS_PER_ROW )
  );
}

/**
 * Scale a counter by the displayed progress so counts ramp up alongside the
 * bar instead of jumping to their final values on the first poll.
 *
 * @param {number} value         Real counter value from the backend.
 * @param {number} processed     Rows shown as processed so far.
 * @param {number} realProcessed Rows actually processed by the backend.
 * @return {number} Scaled counter, never exceeding the real value.
 */
export function scaleCounter( value, processed, realProcessed ) {
  if ( ! realProcessed ) {
    return 0;
  }
  return Math.min( value, Math.floor( ( value * processed ) / realProcessed ) );
}
