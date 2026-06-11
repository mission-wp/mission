import { __, sprintf } from '@wordpress/i18n';

import { ArrowRightIcon, SourceIcon, WarningIcon } from './icons';

export default function IntroStage( {
  source,
  isScanning,
  scanError,
  onScan,
  onChooseOther,
} ) {
  return (
    <div className="mission-settings-card">
      <div className="mission-migration-intro">
        <div className="mission-migration-intro__logos">
          <SourceIcon source={ source.id } size={ 56 } />
          <span className="mission-migration-intro__track">
            <span className="mission-migration-intro__dot" />
            <span className="mission-migration-intro__dot" />
            <span className="mission-migration-intro__dot" />
          </span>
          <SourceIcon source="mission" size={ 56 } />
        </div>

        <h2 className="mission-migration-intro__title">
          { sprintf(
            /* translators: %s: source plugin name. */
            __( 'Migrate from %s to Mission', 'mission-donation-platform' ),
            source.name
          ) }
        </h2>
        <p className="mission-migration-intro__desc">
          { __(
            'Bring your donors, transactions, campaigns, and subscriptions with you. Mission scans your site first and flags anything that needs attention before a single record is written.',
            'mission-donation-platform'
          ) }
        </p>

        <div className="mission-migration-backup-callout">
          <WarningIcon />
          <span>
            <strong>
              { __( 'Back up before you begin.', 'mission-donation-platform' ) }
            </strong>{ ' ' }
            { __(
              'The migration writes new records to your database, and a quick backup means you can always rewind.',
              'mission-donation-platform'
            ) }
          </span>
        </div>

        { scanError && (
          <div className="mission-import-error" role="alert">
            <WarningIcon size={ 14 } />
            <span>{ scanError }</span>
          </div>
        ) }

        <div>
          <button
            type="button"
            className="mission-settings-save-bar__btn"
            onClick={ onScan }
            disabled={ isScanning }
          >
            { isScanning
              ? __( 'Scanning your site…', 'mission-donation-platform' )
              : __( 'Scan My Site', 'mission-donation-platform' ) }
            <ArrowRightIcon />
          </button>
        </div>

        <button
          type="button"
          className="mission-migration-link mission-migration-intro__back"
          onClick={ onChooseOther }
        >
          { __( 'Choose a different plugin', 'mission-donation-platform' ) }
        </button>
      </div>
    </div>
  );
}
