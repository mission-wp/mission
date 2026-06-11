import { __, sprintf } from '@wordpress/i18n';

import { CheckIcon } from './icons';

const ENTITY_LABELS = {
  campaigns: __( 'Campaigns', 'mission-donation-platform' ),
  donors: __( 'Donors', 'mission-donation-platform' ),
  subscriptions: __( 'Subscriptions', 'mission-donation-platform' ),
  transactions: __( 'Transactions', 'mission-donation-platform' ),
  finalize: __( 'Finishing up', 'mission-donation-platform' ),
};

function PhaseRow( { phase } ) {
  const isFinalize = 'finalize' === phase.entity;
  const isRunning = 'processing' === phase.status;
  const isDone = 'completed' === phase.status;
  const isFailed = [ 'failed', 'cancelled' ].includes( phase.status );

  let stateClass = ' is-pending';
  if ( isRunning ) {
    stateClass = ' is-running';
  } else if ( isDone ) {
    stateClass = ' is-done';
  } else if ( isFailed ) {
    stateClass = ' is-failed';
  }

  let countText = __( 'Queued', 'mission-donation-platform' );
  if ( isFailed ) {
    countText =
      'failed' === phase.status
        ? __( 'Failed', 'mission-donation-platform' )
        : __( 'Cancelled', 'mission-donation-platform' );
  } else if ( isFinalize ) {
    if ( isRunning ) {
      countText = __( 'Working…', 'mission-donation-platform' );
    } else if ( isDone ) {
      countText = __( 'Done', 'mission-donation-platform' );
    }
  } else if ( isDone ) {
    countText = sprintf(
      /* translators: %s: number of records processed. */
      __( '%s processed', 'mission-donation-platform' ),
      ( phase.processed_items || 0 ).toLocaleString()
    );
  } else if ( isRunning ) {
    countText = sprintf(
      /* translators: 1: processed count, 2: total count. */
      __( '%1$s / %2$s', 'mission-donation-platform' ),
      ( phase.processed_items || 0 ).toLocaleString(),
      ( phase.total_items || 0 ).toLocaleString()
    );
  }

  return (
    <div className={ `mission-migration-phase${ stateClass }` }>
      <div className="mission-migration-phase__rail">
        <span className="mission-migration-phase__marker">
          { isDone && <CheckIcon size={ 10 } /> }
        </span>
        <span className="mission-migration-phase__line" />
      </div>
      <div className="mission-migration-phase__body">
        <div className="mission-migration-phase__head">
          <div className="mission-migration-phase__name">
            { ENTITY_LABELS[ phase.entity ] ?? phase.entity }
          </div>
          <div className="mission-migration-phase__count">{ countText }</div>
        </div>
        { ! isFinalize && (
          <div className="mission-migration-phase__bar">
            <div
              className="mission-migration-phase__bar-fill"
              style={ { width: `${ phase.percentage || 0 }%` } }
            />
          </div>
        ) }
      </div>
    </div>
  );
}

export default function ProgressTimeline( {
  job,
  isRollback,
  isCancelling,
  onCancel,
} ) {
  return (
    <div className="mission-settings-card">
      <div className="mission-settings-card__header">
        <h2 className="mission-settings-card__title">
          { isRollback
            ? __( 'Removing migrated data', 'mission-donation-platform' )
            : __( 'Migration in progress', 'mission-donation-platform' ) }
        </h2>
        <p className="mission-settings-card__desc">
          { __(
            'You can safely leave this page. The migration keeps running in the background, and you can come back any time to check progress.',
            'mission-donation-platform'
          ) }
        </p>
      </div>

      <div className="mission-migration-phases">
        { ( job?.phases || [] ).map( ( phase ) => (
          <PhaseRow key={ phase.entity } phase={ phase } />
        ) ) }
      </div>

      { ! isRollback && (
        <div className="mission-migration-progress-actions">
          <button
            type="button"
            className="mission-settings-secondary-btn"
            onClick={ onCancel }
            disabled={ isCancelling }
          >
            { isCancelling
              ? __( 'Cancelling…', 'mission-donation-platform' )
              : __( 'Cancel migration', 'mission-donation-platform' ) }
          </button>
        </div>
      ) }
    </div>
  );
}
