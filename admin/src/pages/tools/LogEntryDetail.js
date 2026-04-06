import { buildDetailRows } from './logs-utils';

export default function LogEntryDetail( { entry } ) {
  const rows = buildDetailRows( entry );

  if ( ! rows.length ) {
    return null;
  }

  return (
    <div className="mission-logs-detail">
      <div className="mission-logs-detail__inner">
        { rows.map( ( row ) => (
          <div className="mission-logs-detail__row" key={ row.label }>
            <span className="mission-logs-detail__label">{ row.label }</span>
            <span className="mission-logs-detail__value">{ row.value }</span>
          </div>
        ) ) }
      </div>
    </div>
  );
}
