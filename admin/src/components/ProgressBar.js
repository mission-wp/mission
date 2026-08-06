/**
 * Percent of goal reached, clamped to 0-100.
 *
 * @param {number} raised Amount raised in minor units.
 * @param {number} goal   Goal in minor units.
 * @return {number} Whole percent, 0 when there is no positive goal.
 */
export function goalPercent( raised, goal ) {
  return goal > 0 ? Math.min( 100, Math.round( ( raised / goal ) * 100 ) ) : 0;
}

/**
 * Mini progress bar for admin tables: track, fill, and a trailing text label.
 *
 * @param {Object}  props          Component props.
 * @param {number}  props.percent  Fill percent (see goalPercent()).
 * @param {boolean} props.wide     Use the wider detail-table track.
 * @param {*}       props.children Text label rendered after the track.
 * @return {JSX.Element} The bar.
 */
export default function ProgressBar( { percent, wide, children } ) {
  const className = wide
    ? 'mission-progress-bar mission-progress-bar--wide'
    : 'mission-progress-bar';

  return (
    <span className={ className }>
      <span className="mission-progress-bar__track">
        <span
          className="mission-progress-bar__fill"
          style={ { width: `${ percent }%` } }
        />
      </span>
      <span className="mission-progress-bar__text">{ children }</span>
    </span>
  );
}
