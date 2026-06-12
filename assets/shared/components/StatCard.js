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

/**
 * Compute the percentage change between two values for a StatCard delta.
 *
 * @param {number} current  Current period value.
 * @param {number} previous Previous period value.
 * @return {Object} { value, direction } where direction is 'positive', 'negative', or 'neutral'.
 */
export function getDelta( current, previous ) {
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

/**
 * Summary stat card with optional period-over-period delta or subtitle.
 *
 * Visuals come entirely from the .mission-stat-card SCSS, so this renders a
 * plain div rather than a Card. While loading, the value and the delta row
 * show pulsing skeletons sized by the same SCSS.
 *
 * @param {Object}  props
 * @param {string}  props.label       Uppercase label above the value.
 * @param {*}       props.value       The stat value.
 * @param {Object}  [props.delta]     { value, direction, label } from getDelta plus a period label.
 * @param {string}  [props.subtitle]  Muted line shown when there is no delta.
 * @param {boolean} [props.isLoading] Show skeleton placeholders.
 * @param {string}  [props.className] Extra class names for the card.
 * @return {JSX.Element} The stat card.
 */
export default function StatCard( {
  label,
  value,
  delta,
  subtitle,
  isLoading,
  className,
} ) {
  const classes = [ 'mission-stat-card', className ]
    .filter( Boolean )
    .join( ' ' );

  return (
    <div className={ classes }>
      <div className="mission-stat-card__label">{ label }</div>
      <div className="mission-stat-card__value">
        { isLoading ? <span className="mission-skeleton">&nbsp;</span> : value }
      </div>
      { isLoading && (
        <div className="mission-stat-card__delta">
          <span className="mission-skeleton">&nbsp;</span>
        </div>
      ) }
      { ! isLoading && delta && (
        <div className={ `mission-stat-card__delta is-${ delta.direction }` }>
          { delta.direction === 'positive' && <ArrowUp /> }
          { delta.direction === 'negative' && <ArrowDown /> }
          <span>
            { delta.value }% { delta.label }
          </span>
        </div>
      ) }
      { ! isLoading && ! delta && subtitle && (
        <div className="mission-stat-card__subtitle">{ subtitle }</div>
      ) }
    </div>
  );
}
