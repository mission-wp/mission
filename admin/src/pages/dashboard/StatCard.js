function getDelta( current, previous ) {
  if ( ! previous ) {
    return { value: 0, direction: 'neutral' };
  }

  const pct = ( ( current - previous ) / previous ) * 100;
  const rounded = Math.abs( Math.round( pct * 10 ) / 10 );

  if ( pct > 0 ) {
    return { value: rounded, direction: 'positive' };
  }
  if ( pct < 0 ) {
    return { value: rounded, direction: 'negative' };
  }
  return { value: 0, direction: 'neutral' };
}

const ArrowUp = () => (
  <svg
    width="12"
    height="12"
    viewBox="0 0 12 12"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <polyline points="2,8 6,3 10,8" />
  </svg>
);

const ArrowDown = () => (
  <svg
    width="12"
    height="12"
    viewBox="0 0 12 12"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <polyline points="2,4 6,9 10,4" />
  </svg>
);

export default function StatCard( {
  label,
  value,
  current,
  previous,
  periodLabel,
  isLoading,
} ) {
  const delta = getDelta( current, previous );

  return (
    <div className="mission-stat-card">
      <div className="mission-stat-card__label">{ label }</div>
      <div className="mission-stat-card__value">
        { isLoading ? <span className="mission-skeleton">&nbsp;</span> : value }
      </div>
      <div className={ `mission-stat-card__delta is-${ delta.direction }` }>
        { isLoading ? (
          <span className="mission-skeleton">&nbsp;</span>
        ) : (
          <>
            { delta.direction === 'positive' && <ArrowUp /> }
            { delta.direction === 'negative' && <ArrowDown /> }
            <span>
              { delta.value }% { periodLabel }
            </span>
          </>
        ) }
      </div>
    </div>
  );
}
