import { __, sprintf } from '@wordpress/i18n';

import { ArrowRightIcon, CheckIcon, InfoIcon, WarningIcon } from './icons';

const COUNT_BOXES = [
  { key: 'donors', label: __( 'Donors', 'mission-donation-platform' ) },
  {
    key: 'transactions',
    label: __( 'Transactions', 'mission-donation-platform' ),
  },
  { key: 'campaigns', label: __( 'Campaigns', 'mission-donation-platform' ) },
  {
    key: 'subscriptions',
    label: __( 'Subscriptions', 'mission-donation-platform' ),
  },
];

function StatusIcon( { status } ) {
  if ( 'ok' === status ) {
    return (
      <span className="mission-migration-check-icon is-ok">
        <CheckIcon />
      </span>
    );
  }
  if ( 'warn' === status ) {
    return (
      <span className="mission-migration-check-icon is-warn">
        <WarningIcon size={ 11 } />
      </span>
    );
  }
  return (
    <span className="mission-migration-check-icon is-info">
      <InfoIcon size={ 11 } />
    </span>
  );
}

export default function ScanResults( {
  source,
  scan,
  includeTest,
  onIncludeTestChange,
  isRescanning,
  onRescan,
  startError,
  onBack,
  onStart,
} ) {
  const counts = scan.counts || {};
  const testTransactions = counts.test_transactions || 0;
  const testSubscriptions = counts.test_subscriptions || 0;
  const hasTestData = testTransactions > 0 || testSubscriptions > 0;

  const displayCount = ( key ) => {
    let value = counts[ key ] || 0;
    if ( includeTest && 'transactions' === key ) {
      value += testTransactions;
    }
    if ( includeTest && 'subscriptions' === key ) {
      value += testSubscriptions;
    }
    return value.toLocaleString();
  };

  const totalRecords =
    ( counts.donors || 0 ) +
    ( counts.transactions || 0 ) +
    ( counts.campaigns || 0 ) +
    ( counts.subscriptions || 0 );

  return (
    <>
      <div className="mission-settings-card">
        <div className="mission-settings-card__header mission-migration-results-head">
          <div>
            <h2 className="mission-settings-card__title">
              { __( 'Scan results', 'mission-donation-platform' ) }
            </h2>
            <p className="mission-settings-card__desc">
              { __(
                'Your site is ready. Review what will be migrated below.',
                'mission-donation-platform'
              ) }
            </p>
          </div>
          <button
            type="button"
            className="mission-settings-secondary-btn"
            onClick={ onRescan }
            disabled={ isRescanning }
          >
            { isRescanning
              ? __( 'Scanning…', 'mission-donation-platform' )
              : __( 'Run again', 'mission-donation-platform' ) }
          </button>
        </div>

        <div className="mission-migration-result-section">
          <div className="mission-migration-section-label">
            { __( 'System status', 'mission-donation-platform' ) }
          </div>
          <div className="mission-migration-status-list">
            { ( scan.checks || [] ).map( ( check ) => (
              <div
                key={ check.id }
                className={ `mission-migration-status-item${
                  'info' === check.status ? ' is-muted' : ''
                }` }
              >
                <StatusIcon status={ check.status } />
                <span>{ check.label }</span>
              </div>
            ) ) }
          </div>
        </div>

        { ( scan.warnings || [] ).length > 0 && (
          <div className="mission-migration-result-section">
            { scan.warnings.map( ( warning, index ) => (
              <div
                key={ index }
                className="mission-import-warning-item"
                style={ { marginBottom: '8px' } }
              >
                <WarningIcon size={ 14 } />
                <span>{ warning }</span>
              </div>
            ) ) }
          </div>
        ) }

        <div className="mission-migration-result-section">
          <div className="mission-migration-section-label">
            { __( 'What will be migrated', 'mission-donation-platform' ) }
          </div>
          <div className="mission-migration-preview-grid">
            { COUNT_BOXES.map( ( box ) => (
              <div key={ box.key } className="mission-migration-preview-box">
                <div className="mission-migration-preview-box__num">
                  { displayCount( box.key ) }
                </div>
                <div className="mission-migration-preview-box__label">
                  { box.label }
                </div>
              </div>
            ) ) }
          </div>
        </div>

        { hasTestData && (
          <div className="mission-migration-result-section">
            <div className="mission-migration-section-label">
              { __( 'Options', 'mission-donation-platform' ) }
            </div>
            <label
              className="mission-migration-option"
              htmlFor="mission-migration-include-test"
            >
              <input
                id="mission-migration-include-test"
                type="checkbox"
                checked={ includeTest }
                onChange={ ( e ) => onIncludeTestChange( e.target.checked ) }
              />
              <span className="mission-migration-option__check">
                <CheckIcon size={ 10 } />
              </span>
              <span>
                <span className="mission-migration-option__name">
                  { __(
                    'Include test-mode donations',
                    'mission-donation-platform'
                  ) }
                </span>
                <span className="mission-migration-option__hint">
                  { sprintf(
                    /* translators: 1: test transaction count, 2: test subscription count, 3: source plugin name. */
                    __(
                      '%3$s has %1$s test-mode donations and %2$s test-mode subscriptions made in test/sandbox mode.',
                      'mission-donation-platform'
                    ),
                    testTransactions.toLocaleString(),
                    testSubscriptions.toLocaleString(),
                    source.name
                  ) }
                </span>
              </span>
            </label>
          </div>
        ) }
      </div>

      <div className="mission-import-actions">
        { startError && (
          <div className="mission-import-error" role="alert">
            <WarningIcon size={ 14 } />
            <span>{ startError }</span>
          </div>
        ) }
        <div className="mission-import-actions__warning">
          <WarningIcon size={ 14 } />
          <span>
            { sprintf(
              /* translators: %s: source plugin name. */
              __(
                'Mission copies your data. Nothing is deleted from %s, and existing Mission data is not affected.',
                'mission-donation-platform'
              ),
              source.name
            ) }
          </span>
        </div>
        <div className="mission-import-actions__buttons">
          <button
            type="button"
            className="mission-settings-secondary-btn"
            onClick={ onBack }
          >
            { __( 'Back', 'mission-donation-platform' ) }
          </button>
          <button
            type="button"
            className="mission-settings-save-bar__btn"
            onClick={ onStart }
            disabled={ totalRecords === 0 && ! includeTest }
          >
            { __( 'Start Migration', 'mission-donation-platform' ) }
            <ArrowRightIcon />
          </button>
        </div>
      </div>
    </>
  );
}
