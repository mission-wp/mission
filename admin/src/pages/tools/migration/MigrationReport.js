import { __, sprintf } from '@wordpress/i18n';

import { CheckIcon, InfoIcon, WarningIcon } from './icons';

const ENTITY_LABELS = {
  campaigns: __( 'Campaigns', 'mission-donation-platform' ),
  donors: __( 'Donors', 'mission-donation-platform' ),
  subscriptions: __( 'Subscriptions', 'mission-donation-platform' ),
  transactions: __( 'Transactions', 'mission-donation-platform' ),
};

const RECEIPT_ORDER = [
  'donors',
  'transactions',
  'campaigns',
  'subscriptions',
];

export default function MigrationReport( {
  job,
  sourceName,
  onRollback,
  onMigrateAnother,
} ) {
  const phases = job?.phases || [];
  const entityPhases = RECEIPT_ORDER.map( ( entity ) =>
    phases.find( ( phase ) => phase.entity === entity )
  ).filter( Boolean );

  const totalErrors = job?.totals?.errors || 0;
  const isCompleted = 'completed' === job?.status;
  const failedPhase = phases.find( ( phase ) => 'failed' === phase.status );
  const allNotes = phases.flatMap( ( phase ) =>
    ( phase.error_details || [] ).map( ( detail ) => ( {
      entity: phase.entity,
      ...detail,
    } ) )
  );

  return (
    <>
      <div className="mission-settings-card">
        <div className="mission-import-success mission-migration-complete-card">
          <div
            className={ `mission-import-success__icon${
              isCompleted ? '' : ' is-error'
            }` }
          >
            { isCompleted ? (
              <svg
                width="28"
                height="28"
                viewBox="0 0 32 32"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <polyline points="8,16 14,22 24,10" />
              </svg>
            ) : (
              <WarningIcon size={ 24 } />
            ) }
          </div>
          <div className="mission-import-success__title">
            { isCompleted &&
              __( 'Migration complete', 'mission-donation-platform' ) }
            { 'failed' === job?.status &&
              __( 'Migration failed', 'mission-donation-platform' ) }
            { 'cancelled' === job?.status &&
              __( 'Migration cancelled', 'mission-donation-platform' ) }
          </div>
          <div className="mission-import-success__text">
            { isCompleted
              ? sprintf(
                  /* translators: %s: source plugin name. */
                  __(
                    'All your %s data has been moved to Mission. Review the migration receipt below.',
                    'mission-donation-platform'
                  ),
                  sourceName
                )
              : failedPhase?.last_error ||
                __(
                  'The migration stopped before finishing. Anything already migrated is listed below, and you can remove it with Reset migration.',
                  'mission-donation-platform'
                ) }
          </div>
        </div>
      </div>

      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Migration receipt', 'mission-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'How many records were migrated, out of everything found in the scan.',
              'mission-donation-platform'
            ) }
          </p>
        </div>

        <div className="mission-migration-receipt">
          { entityPhases.map( ( phase ) => (
            <div
              key={ phase.entity }
              className="mission-migration-receipt__row"
            >
              <span className="mission-migration-receipt__name">
                { ENTITY_LABELS[ phase.entity ] ?? phase.entity }
              </span>
              <span className="mission-migration-receipt__dots" />
              <span className="mission-migration-receipt__count">
                <span>
                  { ( phase.imported + phase.skipped ).toLocaleString() }{ ' ' }
                  <span className="mission-migration-receipt__of">
                    / { ( phase.total_items || 0 ).toLocaleString() }
                  </span>
                </span>
                { 'completed' === phase.status && 0 === phase.errors && (
                  <span className="mission-migration-receipt__check">
                    <CheckIcon size={ 9 } />
                  </span>
                ) }
              </span>
            </div>
          ) ) }
          <div className="mission-migration-receipt__row">
            <span className="mission-migration-receipt__name">
              { __( 'Errors', 'mission-donation-platform' ) }
            </span>
            <span className="mission-migration-receipt__dots" />
            <span
              className={ `mission-migration-receipt__count${
                totalErrors === 0 ? ' is-zero' : ' is-errors'
              }` }
            >
              { totalErrors.toLocaleString() }
            </span>
          </div>
        </div>

        { allNotes.length > 0 && (
          <details className="mission-import-success__errors mission-migration-receipt__notes">
            <summary>
              { __( 'Show details', 'mission-donation-platform' ) }
            </summary>
            <ul>
              { allNotes.map( ( note, index ) => (
                <li key={ index }>
                  <strong>
                    { sprintf(
                      /* translators: 1: record type, 2: source record ID. */
                      __( '%1$s #%2$d:', 'mission-donation-platform' ),
                      ENTITY_LABELS[ note.entity ] ?? note.entity,
                      note.source_id
                    ) }
                  </strong>{ ' ' }
                  { note.message }
                </li>
              ) ) }
            </ul>
          </details>
        ) }

        { isCompleted && (
          <div
            className="mission-import-callout"
            style={ { marginTop: '16px' } }
          >
            <div className="mission-import-callout__icon">
              <InfoIcon />
            </div>
            <div className="mission-import-callout__text">
              { __(
                "Once you've verified your data in Mission, you can safely deactivate your old donation plugin. Your original data stays untouched in case you ever need it.",
                'mission-donation-platform'
              ) }
            </div>
          </div>
        ) }
      </div>

      <div className="mission-migration-report-actions">
        <button
          type="button"
          className="mission-migration-link is-danger"
          onClick={ onRollback }
        >
          { __( 'Reset migration', 'mission-donation-platform' ) }
        </button>
        <button
          type="button"
          className="mission-settings-secondary-btn"
          onClick={ onMigrateAnother }
        >
          { __( 'Migrate another plugin', 'mission-donation-platform' ) }
        </button>
      </div>
    </>
  );
}
