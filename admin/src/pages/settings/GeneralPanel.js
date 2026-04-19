import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { CURRENCIES, minorToMajor, majorToMinor } from '@shared/currencies';
import { getCurrencyCode } from '@shared/currency';
import { COUNTRIES, getRegionConfig } from '@shared/address';
import ColorPicker from '@shared/components/ColorPicker';

import SaveBar from './SaveBar';

export default function GeneralPanel( {
  settings,
  updateField,
  saving,
  isDirty,
  handleSave,
  setShowDisconnectModal,
  handleDisconnect, // eslint-disable-line no-unused-vars -- used by parent modal
  pendingCurrency, // eslint-disable-line no-unused-vars -- used by parent modal
  setPendingCurrency,
} ) {
  const isConnected = settings.stripe_connection_status === 'connected';
  const stripeConnectUrl = window.missionAdmin?.stripeConnectUrl;

  return (
    <div className="mission-settings-panel" key="general">
      { /* Payment Gateway */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Payment Gateway', 'missionwp-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'Connect your Stripe account to process donations.',
              'missionwp-donation-platform'
            ) }
          </p>
        </div>

        { isConnected ? (
          <>
            <div className="mission-settings-stripe">
              <div className="mission-settings-stripe__info">
                <div className="mission-settings-stripe__icon">
                  <svg
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.8"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  >
                    <rect x="1" y="4" width="22" height="16" rx="3" />
                    <line x1="1" y1="10" x2="23" y2="10" />
                  </svg>
                </div>
                <div className="mission-settings-stripe__details">
                  <span className="mission-settings-stripe__status">
                    <span className="mission-settings-stripe__dot" />
                    { __( 'Connected', 'missionwp-donation-platform' ) }
                  </span>
                  { ( settings.stripe_display_name ||
                    settings.stripe_account_id ) && (
                    <span className="mission-settings-stripe__name">
                      { [
                        settings.stripe_display_name,
                        settings.stripe_account_id
                          ? `(${ settings.stripe_account_id })`
                          : '',
                      ]
                        .filter( Boolean )
                        .join( ' ' ) }
                    </span>
                  ) }
                </div>
              </div>
              <button
                className="mission-settings-stripe__disconnect"
                onClick={ () => setShowDisconnectModal( true ) }
                disabled={ saving }
                type="button"
              >
                { __( 'Disconnect', 'missionwp-donation-platform' ) }
              </button>
            </div>

            { ! settings.stripe_charges_enabled && (
              <div className="mission-settings-stripe-warning">
                <svg
                  width="16"
                  height="16"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d="M12 2L1 21h22L12 2z" />
                  <line x1="12" y1="10" x2="12" y2="14" />
                  <circle cx="12" cy="17.5" r="0.5" />
                </svg>
                <div>
                  <p>
                    { __(
                      'Your Stripe account is connected, but charges are not yet enabled. You\u2019ll need to finish setting up your Stripe account before you can accept donations.',
                      'missionwp-donation-platform'
                    ) }
                  </p>
                  <a
                    href="https://dashboard.stripe.com"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    { __(
                      'Go to Stripe Dashboard \u2192',
                      'missionwp-donation-platform'
                    ) }
                  </a>
                </div>
              </div>
            ) }

            <div
              style={ {
                marginTop: '18px',
                paddingTop: '18px',
                borderTop: '1px solid #f0f0f2',
              } }
            >
              <div
                className="mission-settings-toggle-row"
                style={ { borderBottom: 'none', paddingBottom: 0 } }
              >
                <div className="mission-settings-toggle-row__text">
                  <div className="mission-settings-toggle-row__label">
                    { __( 'Test mode', 'missionwp-donation-platform' ) }
                  </div>
                  <div className="mission-settings-toggle-row__desc">
                    { settings.test_mode
                      ? __(
                          'Using Stripe test keys \u2014 no real charges.',
                          'missionwp-donation-platform'
                        )
                      : __(
                          'Using Stripe live keys \u2014 real charges.',
                          'missionwp-donation-platform'
                        ) }
                  </div>
                </div>
                { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
                <label
                  className="mission-toggle-sm"
                  aria-label={ __(
                    'Test mode',
                    'missionwp-donation-platform'
                  ) }
                >
                  <input
                    type="checkbox"
                    checked={ !! settings.test_mode }
                    onChange={ ( e ) =>
                      updateField( 'test_mode', e.target.checked )
                    }
                  />
                  <span className="mission-toggle-sm__slider" />
                </label>
              </div>
            </div>
          </>
        ) : (
          <div>
            { settings.stripe_connection_status === 'error' && (
              <p
                style={ {
                  color: '#b85c5c',
                  fontSize: '13px',
                  marginBottom: '12px',
                } }
              >
                { __(
                  'Connection error \u2014 please try again.',
                  'missionwp-donation-platform'
                ) }
              </p>
            ) }
            { stripeConnectUrl && (
              <a
                href={ stripeConnectUrl }
                className="mission-settings-save-bar__btn"
                style={ { textDecoration: 'none', display: 'inline-flex' } }
              >
                { __( 'Connect to Stripe', 'missionwp-donation-platform' ) }
              </a>
            ) }
          </div>
        ) }
      </div>

      { /* Organization */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Organization', 'missionwp-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'Used on donation receipts and tax documents.',
              'missionwp-donation-platform'
            ) }
          </p>
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-org-name"
          >
            { __( 'Organization name', 'missionwp-donation-platform' ) }
          </label>
          <input
            type="text"
            id="mission-org-name"
            className="mission-settings-field__input"
            value={ settings.org_name }
            onChange={ ( e ) => updateField( 'org_name', e.target.value ) }
          />
          <span className="mission-settings-field__hint">
            { __(
              'Shown on donation receipts, emails, and the donor dashboard. Defaults to your site name.',
              'missionwp-donation-platform'
            ) }
          </span>
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-org-country"
          >
            { __( 'Country', 'missionwp-donation-platform' ) }
          </label>
          <select
            id="mission-org-country"
            className="mission-settings-field__select"
            value={ settings.org_country || 'US' }
            onChange={ ( e ) => updateField( 'org_country', e.target.value ) }
          >
            { COUNTRIES.map( ( c ) => (
              <option key={ c.value } value={ c.value }>
                { c.label }
              </option>
            ) ) }
          </select>
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-org-street"
          >
            { __( 'Street address', 'missionwp-donation-platform' ) }
          </label>
          <input
            type="text"
            id="mission-org-street"
            className="mission-settings-field__input"
            value={ settings.org_street }
            onChange={ ( e ) => updateField( 'org_street', e.target.value ) }
          />
        </div>
        <div style={ { display: 'flex', gap: '12px' } }>
          <div className="mission-settings-field" style={ { flex: 2 } }>
            <label
              className="mission-settings-field__label"
              htmlFor="mission-org-city"
            >
              { __( 'City', 'missionwp-donation-platform' ) }
            </label>
            <input
              type="text"
              id="mission-org-city"
              className="mission-settings-field__input"
              value={ settings.org_city }
              onChange={ ( e ) => updateField( 'org_city', e.target.value ) }
            />
          </div>
          <div className="mission-settings-field" style={ { flex: 1 } }>
            <label
              className="mission-settings-field__label"
              htmlFor="mission-org-state"
            >
              { getRegionConfig( settings.org_country || 'US' ).label }
            </label>
            { getRegionConfig( settings.org_country || 'US' ).options ? (
              <select
                id="mission-org-state"
                className="mission-settings-field__select"
                value={ settings.org_state }
                onChange={ ( e ) => updateField( 'org_state', e.target.value ) }
              >
                <option value="">—</option>
                { getRegionConfig( settings.org_country || 'US' ).options.map(
                  ( s ) => (
                    <option key={ s.value } value={ s.value }>
                      { s.label }
                    </option>
                  )
                ) }
              </select>
            ) : (
              <input
                type="text"
                id="mission-org-state"
                className="mission-settings-field__input"
                value={ settings.org_state }
                onChange={ ( e ) => updateField( 'org_state', e.target.value ) }
              />
            ) }
          </div>
          <div className="mission-settings-field" style={ { flex: 1 } }>
            <label
              className="mission-settings-field__label"
              htmlFor="mission-org-zip"
            >
              { getRegionConfig( settings.org_country || 'US' ).postalLabel }
            </label>
            <input
              type="text"
              id="mission-org-zip"
              className="mission-settings-field__input"
              value={ settings.org_zip }
              onChange={ ( e ) => updateField( 'org_zip', e.target.value ) }
            />
          </div>
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-org-ein"
          >
            { __( 'EIN', 'missionwp-donation-platform' ) }
          </label>
          <input
            type="text"
            id="mission-org-ein"
            className="mission-settings-field__input"
            value={ settings.org_ein }
            onChange={ ( e ) => updateField( 'org_ein', e.target.value ) }
          />
          <span className="mission-settings-field__hint">
            { __(
              'Your Employer Identification Number for tax receipts.',
              'missionwp-donation-platform'
            ) }
          </span>
        </div>
      </div>

      { /* Currency */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Currency', 'missionwp-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'The currency used for all donations.',
              'missionwp-donation-platform'
            ) }
          </p>
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-currency"
          >
            { __( 'Currency', 'missionwp-donation-platform' ) }
          </label>
          <select
            id="mission-currency"
            className="mission-settings-field__select"
            value={ settings.currency }
            onChange={ ( e ) => {
              const value = e.target.value;
              if ( value && value !== settings.currency ) {
                setPendingCurrency( value );
              }
            } }
          >
            { CURRENCIES.map( ( c ) => (
              <option key={ c.value } value={ c.value }>
                { c.label }
              </option>
            ) ) }
          </select>
        </div>

        { isConnected && (
          <div
            style={ {
              marginTop: '18px',
              paddingTop: '18px',
              borderTop: '1px solid #f0f0f2',
            } }
          >
            <div style={ { display: 'flex', gap: '16px' } }>
              <div
                className="mission-settings-field"
                style={ { flex: '0 0 160px' } }
              >
                <label
                  className="mission-settings-field__label"
                  htmlFor="mission-fee-percent"
                >
                  { __( 'Percentage (%)', 'missionwp-donation-platform' ) }
                </label>
                <input
                  type="number"
                  id="mission-fee-percent"
                  className="mission-settings-field__input"
                  step="0.1"
                  min="0.5"
                  max="5"
                  value={
                    settings.stripe_fee_percent !== null &&
                    settings.stripe_fee_percent !== undefined
                      ? String( settings.stripe_fee_percent )
                      : '2.9'
                  }
                  onChange={ ( e ) =>
                    updateField(
                      'stripe_fee_percent',
                      parseFloat( e.target.value ) || 0
                    )
                  }
                />
              </div>
              <div
                className="mission-settings-field"
                style={ { flex: '0 0 160px' } }
              >
                <label
                  className="mission-settings-field__label"
                  htmlFor="mission-fee-fixed"
                >
                  { __( 'Fixed fee', 'missionwp-donation-platform' ) }
                </label>
                <input
                  type="number"
                  id="mission-fee-fixed"
                  className="mission-settings-field__input"
                  step="0.01"
                  min="0"
                  max="1"
                  value={
                    settings.stripe_fee_fixed !== null &&
                    settings.stripe_fee_fixed !== undefined
                      ? String(
                          minorToMajor(
                            settings.stripe_fee_fixed,
                            getCurrencyCode()
                          )
                        )
                      : '0.30'
                  }
                  onChange={ ( e ) =>
                    updateField(
                      'stripe_fee_fixed',
                      majorToMinor(
                        parseFloat( e.target.value ) || 0,
                        getCurrencyCode()
                      )
                    )
                  }
                />
              </div>
            </div>
            <span className="mission-settings-field__hint">
              { __(
                'Used when calculating fee recovery. Nonprofits may qualify for discounted Stripe rates.',
                'missionwp-donation-platform'
              ) }
            </span>
          </div>
        ) }
      </div>

      { /* Appearance */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Appearance', 'missionwp-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'Customize how MissionWP looks on your site.',
              'missionwp-donation-platform'
            ) }
          </p>
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-primary-color"
          >
            { __( 'Primary color', 'missionwp-donation-platform' ) }
          </label>
          <ColorPicker
            value={ settings.primary_color }
            onChange={ ( val ) => updateField( 'primary_color', val ) }
            id="mission-primary-color"
          />
          <span className="mission-settings-field__hint">
            { __(
              'Used as the primary accent color throughout MissionWP.',
              'missionwp-donation-platform'
            ) }
          </span>
        </div>

        <div
          className="mission-settings-toggle-row"
          style={ { borderBottom: 'none' } }
        >
          <div className="mission-settings-toggle-row__text">
            <div className="mission-settings-toggle-row__label">
              { __(
                'Show MissionWP credit on donation forms',
                'missionwp-donation-platform'
              ) }
            </div>
            <div className="mission-settings-toggle-row__desc">
              { __(
                'Displays a "Secure donation powered by MissionWP" badge below donation forms that links to missionwp.com.',
                'missionwp-donation-platform'
              ) }
            </div>
          </div>
          { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
          <label
            className="mission-toggle-sm"
            aria-label={ __(
              'Show MissionWP credit on donation forms',
              'missionwp-donation-platform'
            ) }
          >
            <input
              type="checkbox"
              checked={ !! settings.show_powered_by }
              onChange={ ( e ) =>
                updateField( 'show_powered_by', e.target.checked )
              }
            />
            <span className="mission-toggle-sm__slider" />
          </label>
        </div>
      </div>

      { /* Setup Wizard */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Setup Wizard', 'missionwp-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'Re-run the guided setup to update your organization details, reconnect Stripe, or create a campaign.',
              'missionwp-donation-platform'
            ) }
          </p>
        </div>
        <button
          type="button"
          className="mission-settings-secondary-btn"
          onClick={ () => {
            apiFetch( {
              path: '/mission/v1/settings',
              method: 'POST',
              headers: { 'X-WP-Nonce': window.missionAdmin.restNonce },
              data: { onboarding_completed: false },
            } ).then( () => {
              window.location.href =
                window.missionAdmin.adminUrl + 'admin.php?page=mission';
            } );
          } }
        >
          <svg
            width="16"
            height="16"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path d="M1 8a7 7 0 0 1 13.2-3.2" />
            <polyline points="14 1 14 5 10 5" />
            <path d="M15 8a7 7 0 0 1-13.2 3.2" />
            <polyline points="2 15 2 11 6 11" />
          </svg>
          { __( 'Run setup wizard', 'missionwp-donation-platform' ) }
        </button>
      </div>

      { /* Data */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Data', 'missionwp-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'Control what happens to your donation data when the plugin is removed.',
              'missionwp-donation-platform'
            ) }
          </p>
        </div>
        { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
        <label className="mission-settings-checkbox-field">
          <input
            type="checkbox"
            checked={ !! settings.delete_data_on_uninstall }
            onChange={ ( e ) =>
              updateField( 'delete_data_on_uninstall', e.target.checked )
            }
          />
          <div className="mission-settings-checkbox-field__text">
            <span className="mission-settings-checkbox-field__label">
              { __(
                'Remove all data on uninstall',
                'missionwp-donation-platform'
              ) }
            </span>
            <span className="mission-settings-checkbox-field__desc">
              { __(
                'When enabled, all donation records, donor information, campaigns, and settings will be permanently deleted if the plugin is uninstalled. This cannot be undone.',
                'missionwp-donation-platform'
              ) }
            </span>
          </div>
        </label>
      </div>

      <SaveBar saving={ saving } isDirty={ isDirty } onSave={ handleSave } />
    </div>
  );
}
