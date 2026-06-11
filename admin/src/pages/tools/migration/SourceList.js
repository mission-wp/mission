import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ArrowRightIcon, InfoIcon, SourceIcon } from './icons';

export default function SourceList( { sources, onSelect, onSwitchTab } ) {
  return (
    <>
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Migrate from another plugin', 'mission-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'Mission can bring over your donors, transactions, campaigns, and subscriptions. GiveWP migration is ready today, and support for more plugins is on the way.',
              'mission-donation-platform'
            ) }
          </p>
        </div>

        <div className="mission-migration-sources">
          { sources.map( ( source ) => (
            <button
              key={ source.id }
              type="button"
              className={ `mission-migration-source${
                source.coming_soon || ! source.available ? ' is-disabled' : ''
              }` }
              disabled={ source.coming_soon || ! source.available }
              onClick={ () => onSelect( source ) }
            >
              <SourceIcon source={ source.id } />
              <span className="mission-migration-source__info">
                <span className="mission-migration-source__name">
                  { source.name }
                </span>
                <span className="mission-migration-source__desc">
                  { source.coming_soon || source.available
                    ? source.description
                    : __(
                        'No data from this plugin was found on your site.',
                        'mission-donation-platform'
                      ) }
                </span>
              </span>
              { source.coming_soon ? (
                <span className="mission-migration-source__badge">
                  { __( 'Coming soon', 'mission-donation-platform' ) }
                </span>
              ) : (
                source.available && (
                  <span className="mission-migration-source__arrow">
                    <ArrowRightIcon size={ 16 } />
                  </span>
                )
              ) }
            </button>
          ) ) }
        </div>
      </div>

      <div className="mission-import-callout">
        <div className="mission-import-callout__icon">
          <InfoIcon />
        </div>
        <div className="mission-import-callout__text">
          { createInterpolateElement(
            __(
              "Don't see your plugin? You can use the <a>Import</a> tool to manually upload a CSV file with your data.",
              'mission-donation-platform'
            ),
            {
              a: (
                <button
                  type="button"
                  className="mission-migration-link"
                  onClick={ () => onSwitchTab?.( 'import' ) }
                />
              ),
            }
          ) }
        </div>
      </div>
    </>
  );
}
