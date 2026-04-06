/**
 * Shared helpers for campaign card display in the block editor.
 *
 * Used by both the Campaign Card and Campaign Grid blocks.
 */
import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';

/**
 * Compute days remaining from an end date string.
 *
 * @param {string|null} dateEnd ISO date string (Y-m-d) or null.
 * @return {number|null} Days remaining, or null if no end date.
 */
export function getDaysRemaining( dateEnd ) {
  if ( ! dateEnd ) {
    return null;
  }
  const datePart = dateEnd.split( ' ' )[ 0 ];
  const [ year, month, day ] = datePart.split( '-' ).map( Number );
  const end = new Date( year, month - 1, day, 23, 59, 59 );
  const now = new Date();
  const diff = Math.ceil( ( end - now ) / ( 1000 * 60 * 60 * 24 ) );
  return Math.max( 0, diff );
}

/**
 * Determine the status tag for a campaign.
 *
 * @param {Object}      campaign      Campaign data from REST API.
 * @param {number|null} daysRemaining Days remaining until end.
 * @return {{ text: string, className: string }|null} Tag info or null.
 */
export function getTag( campaign, daysRemaining ) {
  const {
    status,
    goal_amount: goalAmount,
    goal_progress: goalProgress,
  } = campaign;
  const hasGoal = goalAmount > 0;

  if ( status === 'ended' || daysRemaining === 0 ) {
    return {
      text: __( 'Ended', 'mission' ),
      className: 'mission-cc-tag--ended',
    };
  }

  if ( hasGoal && goalProgress >= goalAmount ) {
    return {
      text: __( 'Goal Reached', 'mission' ),
      className: 'mission-cc-tag--goal-reached',
    };
  }

  if ( daysRemaining !== null && daysRemaining <= 30 ) {
    return {
      text:
        daysRemaining === 1
          ? __( '1 Day Left', 'mission' )
          : `${ daysRemaining } ${ __( 'Days Left', 'mission' ) }`,
      className: 'mission-cc-tag--ending-soon',
    };
  }

  return null;
}

/**
 * Format the progress display based on goal type.
 *
 * @param {Object} campaign Campaign data from REST API.
 * @return {{ raisedText: string, goalText: string, percentage: number|null }} Progress display data.
 */
export function getProgressDisplay( campaign ) {
  const {
    goal_amount: goalAmount,
    goal_type: goalType,
    goal_progress: goalProgress,
  } = campaign;
  const hasGoal = goalAmount > 0;
  const percentage = hasGoal
    ? Math.round( ( goalProgress / goalAmount ) * 100 )
    : null;

  if ( goalType === 'amount' ) {
    return {
      raisedText: formatAmount( goalProgress ),
      goalText: hasGoal ? `of ${ formatAmount( goalAmount ) }` : '',
      percentage,
    };
  }

  const count = goalProgress.toLocaleString();
  return {
    raisedText: count,
    goalText: hasGoal ? `of ${ goalAmount.toLocaleString() }` : '',
    percentage,
  };
}

/**
 * Get the time display text for the meta row.
 *
 * @param {Object}      campaign      Campaign data from REST API.
 * @param {number|null} daysRemaining Days remaining until end.
 * @return {string} Formatted time text.
 */
export function getTimeText( campaign, daysRemaining ) {
  const { status, date_end: dateEnd } = campaign;

  if ( ! dateEnd ) {
    return __( 'Ongoing', 'mission' );
  }

  const datePart = dateEnd.split( ' ' )[ 0 ];
  const [ year, month, day ] = datePart.split( '-' ).map( Number );
  const date = new Date( year, month - 1, day );
  const formatted = date.toLocaleDateString( undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  } );

  if ( status === 'ended' || daysRemaining === 0 ) {
    return `Ended ${ formatted }`;
  }

  return `Ends ${ formatted }`;
}
