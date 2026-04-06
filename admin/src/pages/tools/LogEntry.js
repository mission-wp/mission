import { buildLogMessage } from './logs-utils';
import LogEntryDetail from './LogEntryDetail';

const chevron = (
  <svg
    className="mission-logs-chevron"
    width="14"
    height="14"
    viewBox="0 0 14 14"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.8"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <path d="M5 3l4 4-4 4" />
  </svg>
);

function formatTimestamp( dateString ) {
  const d = new Date( dateString + 'Z' );
  const pad = ( n ) => String( n ).padStart( 2, '0' );

  return (
    `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad(
      d.getDate()
    ) } ` +
    `${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }:${ pad(
      d.getSeconds()
    ) }`
  );
}

export default function LogEntry( { entry, isExpanded, onToggle } ) {
  const message = buildLogMessage( entry );
  const level = entry.level || 'info';
  const category = entry.category || 'system';

  return (
    <>
      <button
        type="button"
        className={ `mission-logs-entry${ isExpanded ? ' is-expanded' : '' }` }
        onClick={ onToggle }
      >
        <span className={ `mission-logs-dot is-${ level }` } />
        <span className="mission-logs-body">
          <span
            className="mission-logs-message"
            dangerouslySetInnerHTML={ { __html: message } }
          />
          <span className="mission-logs-meta">
            <span className="mission-logs-time">
              { formatTimestamp( entry.date_created ) }
            </span>
            <span className={ `mission-logs-badge is-${ category }` }>
              { category }
            </span>
          </span>
        </span>
        { chevron }
      </button>
      { isExpanded && <LogEntryDetail entry={ entry } /> }
    </>
  );
}
