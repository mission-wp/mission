/**
 * Relative time utility.
 *
 * Converts a date string into a human-readable relative time (e.g. "3m ago").
 */

/**
 * Convert a date string to a relative time string.
 *
 * @param {string} dateString Date string (UTC).
 * @return {string} Relative time like "3m ago", "2h ago", "5 days ago".
 */
export function timeAgo( dateString ) {
  const now = Date.now();
  const then = new Date( dateString + 'Z' ).getTime();
  const minutes = Math.max( 0, Math.floor( ( now - then ) / 60000 ) );

  if ( minutes < 1 ) {
    return 'just now';
  }
  if ( minutes < 60 ) {
    return `${ minutes }m ago`;
  }

  const hours = Math.floor( minutes / 60 );
  if ( hours < 24 ) {
    return `${ hours }h ago`;
  }

  const days = Math.floor( hours / 24 );
  return days === 1 ? '1 day ago' : `${ days } days ago`;
}
