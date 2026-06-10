import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
  CURRENCIES,
  getCurrencyDecimals,
  minorToMajor,
  majorToMinor,
} from '@shared/currencies';
import { getCurrencyCode } from '@shared/currency';
import { defaultFixedFee, maxFixedFee } from '@shared/fees';
import { COUNTRIES, getRegionConfig } from '@shared/address';
import ColorPicker from '@shared/components/ColorPicker';

import SaveBar from './SaveBar';

function StripeMark() {
  return (
    <svg viewBox="0 0 32 32" fill="currentColor" aria-hidden="true">
      <path d="M16.5 12.6c0-.8.66-1.1 1.74-1.1 1.55 0 3.52.47 5.07 1.31V8.04C21.61 7.37 19.9 7.1 18.24 7.1c-4.07 0-6.78 2.13-6.78 5.68 0 5.55 7.62 4.66 7.62 7.05 0 .95-.82 1.26-1.96 1.26-1.7 0-3.86-.69-5.58-1.63v4.83c1.9.82 3.83 1.17 5.58 1.17 4.17 0 7.04-2.06 7.04-5.66 0-5.99-7.66-4.92-7.66-7.2z" />
    </svg>
  );
}

function StarFilled() {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
    </svg>
  );
}

function StarOutline() {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
    </svg>
  );
}

function StripeAccountRow( {
  account,
  showMakeDefault,
  onMakeDefault,
  onDisconnect,
  disabled,
} ) {
  const showDefaultIndicator = showMakeDefault && account.is_default;

  return (
    <div
      className={ `mission-stripe-account${
        showDefaultIndicator ? ' is-default' : ''
      }` }
    >
      <div className="mission-stripe-account__icon">
        <StripeMark />
      </div>
      <div className="mission-stripe-account__info">
        <span className="mission-stripe-account__name">
          <span className="mission-stripe-account__name-text">
            { account.display_name ||
              __( 'Stripe account', 'mission-donation-platform' ) }
          </span>
          { showDefaultIndicator && (
            <span className="mission-stripe-default-badge">
              <StarFilled />
              { __( 'Default', 'mission-donation-platform' ) }
            </span>
          ) }
        </span>
        { account.account_id && (
          <span className="mission-stripe-account__id">
            { account.account_id }
          </span>
        ) }
      </div>
      <div className="mission-stripe-account__right">
        <span className="mission-stripe-account__status">
          <span className="mission-stripe-account__dot" />
          { __( 'Connected', 'mission-donation-platform' ) }
        </span>
        <div className="mission-stripe-account__actions">
          { showMakeDefault && ! account.is_default && (
            <button
              className="mission-stripe-action"
              onClick={ () => onMakeDefault( account.account_id ) }
              disabled={ disabled }
              type="button"
            >
              <StarOutline />
              { __( 'Make default', 'mission-donation-platform' ) }
            </button>
          ) }
          <button
            className="mission-stripe-action is-danger"
            onClick={ () => onDisconnect( account ) }
            disabled={ disabled }
            type="button"
          >
            { __( 'Disconnect', 'mission-donation-platform' ) }
          </button>
        </div>
      </div>
    </div>
  );
}

export default function GeneralPanel( {
  settings,
  updateField,
  saving,
  isDirty,
  handleSave,
  setDisconnectTarget,
  handleMakeDefault,
  pendingCurrency, // eslint-disable-line no-unused-vars -- used by parent modal
  setPendingCurrency,
} ) {
  const accounts = Array.isArray( settings.stripe_accounts )
    ? settings.stripe_accounts
    : [];
  const hasAccounts = accounts.length > 0;
  // Use the (possibly unsaved) currency from form state so the fixed-fee
  // input converts and constrains against what will actually be saved.
  const feeCurrency = settings.currency || getCurrencyCode();
  const feeDecimals = getCurrencyDecimals( feeCurrency );
  const stripeConnectUrl = window.missiondpAdmin?.stripeConnectUrl;
  const defaultChargesEnabled = accounts.some(
    ( a ) => a.is_default && a.charges_enabled
  );
  const anyChargesDisabled = accounts.some( ( a ) => ! a.charges_enabled );

  return (
    <div className="mission-settings-panel" key="general">
      { /* Payment Gateway */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Payment Gateway', 'mission-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { hasAccounts
              ? __(
                  'Connect one or more Stripe accounts to process donations. New donations are routed through your default account.',
                  'mission-donation-platform'
                )
              : __(
                  'Connect your Stripe account to process donations.',
                  'mission-donation-platform'
                ) }
          </p>
        </div>

        { settings.stripe_connection_status === 'error' && (
          <p
            style={ {
              color: '#b85c5c',
              fontSize: '13px',
              marginBottom: '12px',
            } }
          >
            { __(
              'Connection error — please try again.',
              'mission-donation-platform'
            ) }
          </p>
        ) }

        { stripeConnectUrl && (
          <a href={ stripeConnectUrl } className="mission-stripe-connect-btn">
            <StripeMark />
            { hasAccounts
              ? __(
                  'Connect another Stripe account',
                  'mission-donation-platform'
                )
              : __( 'Connect with Stripe', 'mission-donation-platform' ) }
          </a>
        ) }

        { hasAccounts && (
          <>
            <p className="mission-stripe-accounts-label">
              { __( 'Connected accounts', 'mission-donation-platform' ) }
            </p>
            <div className="mission-stripe-accounts">
              { accounts.map( ( account ) => (
                <StripeAccountRow
                  key={ account.account_id }
                  account={ account }
                  showMakeDefault={ accounts.length > 1 }
                  onMakeDefault={ handleMakeDefault }
                  onDisconnect={ setDisconnectTarget }
                  disabled={ saving }
                />
              ) ) }
            </div>

            { anyChargesDisabled && (
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
                    { defaultChargesEnabled
                      ? __(
                          'One or more connected accounts can’t accept charges yet. Finish setting them up on the Stripe Dashboard.',
                          'mission-donation-platform'
                        )
                      : __(
                          'Your default Stripe account isn’t ready to accept charges. Finish setting it up before donations can complete.',
                          'mission-donation-platform'
                        ) }
                  </p>
                  <a
                    href="https://dashboard.stripe.com"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    { __(
                      'Go to Stripe Dashboard →',
                      'mission-donation-platform'
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
                    { __( 'Test mode', 'mission-donation-platform' ) }
                  </div>
                  <div className="mission-settings-toggle-row__desc">
                    { settings.test_mode
                      ? __(
                          'Using Stripe test keys — no real charges.',
                          'mission-donation-platform'
                        )
                      : __(
                          'Using Stripe live keys — real charges.',
                          'mission-donation-platform'
                        ) }
                  </div>
                </div>
                { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
                <label
                  className="mission-toggle-sm"
                  aria-label={ __( 'Test mode', 'mission-donation-platform' ) }
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
        ) }
      </div>

      { /* Organization */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Organization', 'mission-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'Used on donation receipts and tax documents.',
              'mission-donation-platform'
            ) }
          </p>
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-org-name"
          >
            { __( 'Organization name', 'mission-donation-platform' ) }
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
              'mission-donation-platform'
            ) }
          </span>
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-org-country"
          >
            { __( 'Country', 'mission-donation-platform' ) }
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
            { __( 'Street address', 'mission-donation-platform' ) }
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
              { __( 'City', 'mission-donation-platform' ) }
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
            { __( 'EIN', 'mission-donation-platform' ) }
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
              'mission-donation-platform'
            ) }
          </span>
        </div>
      </div>

      { /* Currency */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Currency', 'mission-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'The currency used for all donations.',
              'mission-donation-platform'
            ) }
          </p>
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-currency"
          >
            { __( 'Currency', 'mission-donation-platform' ) }
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

        { hasAccounts && (
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
                  { __( 'Percentage (%)', 'mission-donation-platform' ) }
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
                  { __( 'Fixed fee', 'mission-donation-platform' ) }
                </label>
                <input
                  type="number"
                  id="mission-fee-fixed"
                  className="mission-settings-field__input"
                  step={ String( 10 ** -feeDecimals ) }
                  min="0"
                  max={ String(
                    minorToMajor( maxFixedFee( feeCurrency ), feeCurrency )
                  ) }
                  value={
                    settings.stripe_fee_fixed !== null &&
                    settings.stripe_fee_fixed !== undefined
                      ? String(
                          minorToMajor( settings.stripe_fee_fixed, feeCurrency )
                        )
                      : String(
                          minorToMajor(
                            defaultFixedFee( feeCurrency ),
                            feeCurrency
                          )
                        )
                  }
                  onChange={ ( e ) =>
                    updateField(
                      'stripe_fee_fixed',
                      majorToMinor(
                        parseFloat( e.target.value ) || 0,
                        feeCurrency
                      )
                    )
                  }
                />
              </div>
            </div>
            <span className="mission-settings-field__hint">
              { __(
                'Used when calculating fee recovery. Nonprofits may qualify for discounted Stripe rates.',
                'mission-donation-platform'
              ) }
            </span>
          </div>
        ) }
      </div>

      { /* Appearance */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Appearance', 'mission-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'Customize how Mission looks on your site.',
              'mission-donation-platform'
            ) }
          </p>
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-primary-color"
          >
            { __( 'Primary color', 'mission-donation-platform' ) }
          </label>
          <ColorPicker
            value={ settings.primary_color }
            onChange={ ( val ) => updateField( 'primary_color', val ) }
            id="mission-primary-color"
          />
          <span className="mission-settings-field__hint">
            { __(
              'Used as the primary accent color throughout Mission.',
              'mission-donation-platform'
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
                'Show Mission credit on donation forms',
                'mission-donation-platform'
              ) }
            </div>
            <div className="mission-settings-toggle-row__desc">
              { __(
                'Displays a "Secure donation powered by Mission" badge below donation forms that links to missionwp.com.',
                'mission-donation-platform'
              ) }
            </div>
          </div>
          { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
          <label
            className="mission-toggle-sm"
            aria-label={ __(
              'Show Mission credit on donation forms',
              'mission-donation-platform'
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
            { __( 'Setup Wizard', 'mission-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'Re-run the guided setup to update your organization details, reconnect Stripe, or create a campaign.',
              'mission-donation-platform'
            ) }
          </p>
        </div>
        <button
          type="button"
          className="mission-settings-secondary-btn"
          onClick={ () => {
            apiFetch( {
              path: '/mission-donation-platform/v1/settings',
              method: 'POST',
              headers: { 'X-WP-Nonce': window.missiondpAdmin.restNonce },
              data: { onboarding_completed: false },
            } ).then( () => {
              window.location.href =
                window.missiondpAdmin.adminUrl +
                'admin.php?page=mission-donation-platform';
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
          { __( 'Run setup wizard', 'mission-donation-platform' ) }
        </button>
      </div>

      { /* Data */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Data', 'mission-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'Control what happens to your donation data when the plugin is removed.',
              'mission-donation-platform'
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
                'mission-donation-platform'
              ) }
            </span>
            <span className="mission-settings-checkbox-field__desc">
              { __(
                'When enabled, all donation records, donor information, campaigns, and settings will be permanently deleted if the plugin is uninstalled. This cannot be undone.',
                'mission-donation-platform'
              ) }
            </span>
          </div>
        </label>
      </div>

      <SaveBar saving={ saving } isDirty={ isDirty } onSave={ handleSave } />
    </div>
  );
}
