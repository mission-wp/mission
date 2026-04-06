import { useState, useCallback, useRef, useEffect } from '@wordpress/element';
import { ComboboxControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import SaveBar from './SaveBar';

const PORTAL_FEATURES = [
  {
    key: 'donation_history',
    label: __( 'Donation history', 'mission' ),
    desc: __(
      'Let donors view past donations and download receipts.',
      'mission'
    ),
  },
  {
    key: 'manage_recurring',
    label: __( 'Manage recurring gifts', 'mission' ),
    desc: __(
      'Allow donors to pause, resume, or cancel their recurring donations.',
      'mission'
    ),
  },
  {
    key: 'update_payment',
    label: __( 'Update payment method', 'mission' ),
    desc: __(
      'Let donors update the card on file for recurring donations.',
      'mission'
    ),
  },
  {
    key: 'profile_editing',
    label: __( 'Profile editing', 'mission' ),
    desc: __(
      'Allow donors to update their name, email, and mailing address.',
      'mission'
    ),
  },
  {
    key: 'annual_tax_summary',
    label: __( 'Annual tax summary', 'mission' ),
    desc: __(
      'Provide a downloadable year-end giving summary for tax purposes.',
      'mission'
    ),
  },
];

export default function DonorPortalPanel( {
  settings,
  updateField,
  saving,
  isDirty,
  handleSave,
} ) {
  const [ pageOptions, setPageOptions ] = useState( () => {
    if ( settings.donor_portal_page_id && settings.donor_portal_page_title ) {
      return [
        {
          label: settings.donor_portal_page_title,
          value: String( settings.donor_portal_page_id ),
        },
      ];
    }
    return [];
  } );
  const searchTimer = useRef( null );

  const fetchPages = useCallback( ( query ) => {
    if ( searchTimer.current ) {
      clearTimeout( searchTimer.current );
    }

    if ( ! query || query.length < 2 ) {
      setPageOptions( [] );
      return;
    }

    searchTimer.current = setTimeout( async () => {
      try {
        const pages = await apiFetch( {
          path: `/wp/v2/pages?search=${ encodeURIComponent(
            query
          ) }&per_page=10&status=publish`,
        } );

        setPageOptions(
          pages.map( ( p ) => ( {
            label: p.title.rendered,
            value: String( p.id ),
          } ) )
        );
      } catch {
        // Silently ignore search errors.
      }
    }, 300 );
  }, [] );

  // Keep pageOptions in sync when the server returns a new page after save.
  useEffect( () => {
    if ( settings.donor_portal_page_id && settings.donor_portal_page_title ) {
      setPageOptions( ( prev ) => {
        const id = String( settings.donor_portal_page_id );
        if ( prev.some( ( o ) => o.value === id ) ) {
          return prev;
        }
        return [
          {
            label: settings.donor_portal_page_title,
            value: id,
          },
        ];
      } );
    }
  }, [ settings.donor_portal_page_id, settings.donor_portal_page_title ] );

  useEffect( () => {
    return () => {
      if ( searchTimer.current ) {
        clearTimeout( searchTimer.current );
      }
    };
  }, [] );

  const portalEnabled = !! settings.donor_portal_enabled;

  return (
    <div className="mission-settings-panel" key="portal">
      { /* Enable Portal */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Donor Portal', 'mission' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __( 'Let donors manage their account on your site.', 'mission' ) }
          </p>
        </div>
        <div
          className="mission-settings-toggle-row"
          style={
            portalEnabled
              ? undefined
              : { borderBottom: 'none', paddingBottom: 0 }
          }
        >
          <div className="mission-settings-toggle-row__text">
            <div className="mission-settings-toggle-row__label">
              { __( 'Enable donor portal', 'mission' ) }
            </div>
            <div className="mission-settings-toggle-row__desc">
              { __(
                'Allow donors to create accounts, view donation history, and manage recurring gifts.',
                'mission'
              ) }
            </div>
          </div>
          { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
          <label
            className="mission-toggle-sm"
            aria-label={ __( 'Enable donor portal', 'mission' ) }
          >
            <input
              type="checkbox"
              checked={ portalEnabled }
              onChange={ ( e ) =>
                updateField( 'donor_portal_enabled', e.target.checked )
              }
            />
            <span className="mission-toggle-sm__slider" />
          </label>
        </div>

        { portalEnabled && !! settings.donor_portal_page_id && (
          <div style={ { marginTop: '18px' } }>
            <div className="mission-settings-field">
              <ComboboxControl
                label={ __( 'Dashboard page', 'mission' ) }
                value={
                  settings.donor_portal_page_id
                    ? String( settings.donor_portal_page_id )
                    : null
                }
                options={ pageOptions }
                onChange={ ( value ) =>
                  updateField(
                    'donor_portal_page_id',
                    value ? parseInt( value, 10 ) : 0
                  )
                }
                onFilterValueChange={ fetchPages }
                __next40pxDefaultSize
                __nextHasNoMarginBottom
              />
              <span className="mission-settings-field__hint">
                { __(
                  'The page where the donor dashboard block is placed.',
                  'mission'
                ) }
              </span>
            </div>
          </div>
        ) }
      </div>

      { /* Portal Features */ }
      { portalEnabled && (
        <div className="mission-settings-card">
          <div className="mission-settings-card__header">
            <h2 className="mission-settings-card__title">
              { __( 'Portal Features', 'mission' ) }
            </h2>
            <p className="mission-settings-card__desc">
              { __(
                'Control what donors can do from their dashboard.',
                'mission'
              ) }
            </p>
          </div>
          { PORTAL_FEATURES.map( ( feature ) => (
            <div key={ feature.key } className="mission-settings-toggle-row">
              <div className="mission-settings-toggle-row__text">
                <div className="mission-settings-toggle-row__label">
                  { feature.label }
                </div>
                <div className="mission-settings-toggle-row__desc">
                  { feature.desc }
                </div>
              </div>
              { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
              <label className="mission-toggle-sm" aria-label={ feature.label }>
                <input
                  type="checkbox"
                  checked={ !! settings.portal_features?.[ feature.key ] }
                  onChange={ ( e ) =>
                    updateField( 'portal_features', {
                      ...settings.portal_features,
                      [ feature.key ]: e.target.checked,
                    } )
                  }
                />
                <span className="mission-toggle-sm__slider" />
              </label>
            </div>
          ) ) }
        </div>
      ) }

      <SaveBar saving={ saving } isDirty={ isDirty } onSave={ handleSave } />
    </div>
  );
}
