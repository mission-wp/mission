import { __, _n, sprintf } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { formatDate } from '@shared/date';

/**
 * Days from now until a date string, or null when absent or already past.
 *
 * Exported for unit tests.
 *
 * @param {string} endDate Date string (MySQL or ISO format).
 * @return {number|null} Whole days remaining, or null.
 */
export function daysUntil( endDate ) {
  if ( ! endDate ) {
    return null;
  }
  const end = new Date( endDate.replace( ' ', 'T' ) );
  if ( isNaN( end.getTime() ) ) {
    return null;
  }
  const diff = Math.ceil( ( end - Date.now() ) / ( 1000 * 60 * 60 * 24 ) );
  return diff >= 0 ? diff : null;
}

/**
 * Goal pacing meter: raised of goal, percent, days left, end date, and an
 * animated progress track. Renders nothing without a positive goal.
 *
 * @param {Object} props           Component props.
 * @param {number} props.raised    Amount raised in minor units.
 * @param {number} props.goal      Goal in minor units.
 * @param {string} props.endDate   Optional campaign end date.
 * @param {number} props.daysLeft  Optional server-computed days left
 *                                 (overrides the endDate computation).
 * @param {string} props.goalLabel Optional label ("goal" / "team goal").
 * @return {JSX.Element|null} The meter.
 */
export default function GoalMeter( {
  raised,
  goal,
  endDate,
  daysLeft,
  goalLabel,
} ) {
  if ( ! goal || goal <= 0 ) {
    return null;
  }

  const percent = Math.min( 100, Math.round( ( raised / goal ) * 100 ) );
  const remaining = daysLeft ?? daysUntil( endDate );

  return (
    <div className="mission-goal-meter">
      <div className="mission-goal-meter__top">
        <div className="mission-goal-meter__raised">
          { formatAmount( raised ) }{ ' ' }
          <span>
            { sprintf(
              /* translators: 1: formatted goal amount, 2: goal label ("goal" or "team goal") */
              __( 'of %1$s %2$s', 'mission-donation-platform' ),
              formatAmount( goal ),
              goalLabel || __( 'goal', 'mission-donation-platform' )
            ) }
          </span>
        </div>
        <div className="mission-goal-meter__meta">
          <span>
            { sprintf(
              /* translators: %d: percentage of goal reached */
              __( '%d%% of goal', 'mission-donation-platform' ),
              percent
            ) }
          </span>
          { remaining !== null && (
            <span>
              { sprintf(
                /* translators: %d: number of days remaining */
                _n(
                  '%d day left',
                  '%d days left',
                  remaining,
                  'mission-donation-platform'
                ),
                remaining
              ) }
            </span>
          ) }
          { endDate && (
            <span>
              { sprintf(
                /* translators: %s: formatted end date */
                __( 'Ends %s', 'mission-donation-platform' ),
                formatDate( endDate )
              ) }
            </span>
          ) }
        </div>
      </div>
      <div className="mission-goal-meter__track">
        <span
          className="mission-goal-meter__fill"
          style={ { '--bar-width': `${ percent }%` } }
        />
      </div>
    </div>
  );
}
