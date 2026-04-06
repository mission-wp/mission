/**
 * Date formatting utilities.
 *
 * Render dates as <time> elements with a full timestamp in the title attribute.
 */
import { createElement } from '@wordpress/element';

const FULL_DATE_TIME = {
  weekday: 'long',
  year: 'numeric',
  month: 'long',
  day: 'numeric',
  hour: 'numeric',
  minute: '2-digit',
  second: '2-digit',
  timeZoneName: 'short',
};

const FULL_DATE_ONLY = {
  weekday: 'long',
  year: 'numeric',
  month: 'long',
  day: 'numeric',
};

/**
 * Parse a date string, normalizing space-separated timestamps.
 *
 * @param {string} dateStr Date string (ISO or "YYYY-MM-DD HH:MM:SS").
 * @return {Date} Parsed Date object.
 */
function parseDate( dateStr ) {
  const normalized = dateStr.includes( 'T' )
    ? dateStr
    : dateStr.replace( ' ', 'T' );
  return new Date( normalized );
}

/**
 * Format a date string as a short date with a full-timestamp title.
 *
 * @param {string} dateStr Date string.
 * @return {JSX.Element|string} <time> element or em-dash for empty dates.
 */
export function formatDate( dateStr ) {
  if ( ! dateStr || dateStr.startsWith( '0000' ) ) {
    return '\u2014';
  }
  const date = parseDate( dateStr );
  return createElement(
    'time',
    {
      dateTime: date.toISOString(),
      title: date.toLocaleString( undefined, FULL_DATE_TIME ),
    },
    date.toLocaleDateString()
  );
}

/**
 * Format a date string as "Mon D, YYYY at H:MM AM/PM" with a full title.
 *
 * @param {string} dateStr Date string.
 * @return {JSX.Element|string} <time> element or em-dash for empty dates.
 */
export function formatDateTime( dateStr ) {
  if ( ! dateStr || dateStr.startsWith( '0000' ) ) {
    return '\u2014';
  }
  const date = parseDate( dateStr );
  const display =
    date.toLocaleDateString( undefined, {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    } ) +
    ' at ' +
    date.toLocaleTimeString( undefined, {
      hour: 'numeric',
      minute: '2-digit',
    } );
  return createElement(
    'time',
    {
      dateTime: date.toISOString(),
      title: date.toLocaleString( undefined, FULL_DATE_TIME ),
    },
    display
  );
}

/**
 * Format a UTC date string (without timezone suffix) as a short date.
 *
 * Used for timestamps stored without a 'Z' suffix (e.g. note dates).
 *
 * @param {string} dateStr UTC date string without timezone.
 * @return {JSX.Element} <time> element.
 */
export function formatUtcDate( dateStr ) {
  const date = new Date( dateStr + 'Z' );
  const display = date.toLocaleDateString( undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  } );
  return createElement(
    'time',
    {
      dateTime: date.toISOString(),
      title: date.toLocaleString( undefined, FULL_DATE_TIME ),
    },
    display
  );
}

/**
 * Format a date-only string (no time component) with a date-only title.
 *
 * Used for fields like campaign start/end dates.
 *
 * @param {string} dateStr Date string.
 * @return {JSX.Element} <time> element.
 */
export function formatDateOnly( dateStr ) {
  const date = new Date( dateStr );
  return createElement(
    'time',
    {
      dateTime: date.toISOString(),
      title: date.toLocaleString( undefined, FULL_DATE_ONLY ),
    },
    date.toLocaleDateString()
  );
}
