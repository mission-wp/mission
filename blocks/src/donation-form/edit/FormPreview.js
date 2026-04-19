import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { computePrimaryColorVars } from '@shared/color';

/**
 * Format a minor-unit amount (e.g. 5000 = $50.00) for display.
 *
 * @param {number} minorAmount Amount in minor units (cents).
 * @param {string} currency    ISO 4217 currency code.
 * @return {string} Formatted string.
 */
function formatAmount( minorAmount, currency = 'USD' ) {
  const majorAmount = minorAmount / 100;
  try {
    return new Intl.NumberFormat( undefined, {
      style: 'currency',
      currency,
      minimumFractionDigits: 0,
      maximumFractionDigits: majorAmount % 1 === 0 ? 0 : 2,
    } ).format( majorAmount );
  } catch {
    return `$${ majorAmount }`;
  }
}

/**
 * Base CSS custom properties for the form preview.
 */
const BASE_CSS_VARS = {
  '--mission-text': '#1e1e1e',
  '--mission-text-secondary': '#6b7280',
  '--mission-border': '#e2e4e9',
  '--mission-bg': '#fff',
  '--mission-radius': '12px',
  '--mission-transition': '200ms ease',
};

/** Placeholder amounts shown during skeleton loading. */
const SKELETON_AMOUNTS = [ 0, 0, 0, 0 ];

export default function FormPreview( { attributes = {} } ) {
  const [ settings, setSettings ] = useState( null );

  useEffect( () => {
    apiFetch( { path: '/mission/v1/donation-form-settings' } )
      .then( ( response ) => setSettings( response ) )
      .catch( () => setSettings( null ) );
  }, [] );

  const isLoading = ! settings;

  // Merge block attributes over plugin defaults.
  const recurringEnabled =
    attributes.recurringEnabled ?? settings?.recurringEnabled ?? true;
  const customAmount =
    attributes.customAmount ?? settings?.customAmount ?? true;
  const currency = settings?.currency ?? 'USD';
  const amountsByFrequency =
    attributes.amountsByFrequency ?? settings?.amountsByFrequency ?? {};
  const amountDescriptions =
    attributes.amountDescriptions ?? settings?.amountDescriptions ?? {};

  // Determine primary color: per-form > global setting > fallback.
  const primaryColor =
    attributes.primaryColor || settings?.globalPrimaryColor || '#2fa36b';
  const formCssVars = {
    ...BASE_CSS_VARS,
    ...computePrimaryColorVars( primaryColor ),
  };

  const amounts = isLoading
    ? SKELETON_AMOUNTS
    : amountsByFrequency.one_time || amountsByFrequency.monthly || [];

  // Determine if the displayed frequency has any descriptions.
  const displayedFreqDescs = amountDescriptions.one_time || {};
  const hasDescriptions =
    ! isLoading &&
    Object.values( displayedFreqDescs ).some( ( d ) => d && d.trim() );

  return (
    <div className="mission-df-editor-preview" style={ formCssVars }>
      <div className="mission-donation-form">
        <div className="mission-df-step mission-df-step-1 active">
          <h2 className="mission-df-step-title">
            { attributes.chooseGiftHeading ||
              __( 'Choose Your Gift', 'missionwp-donation-platform' ) }
          </h2>

          { recurringEnabled && (
            <div className="mission-df-frequency-toggle">
              <button type="button" className="mission-df-frequency-btn active">
                { __( 'One Time', 'missionwp-donation-platform' ) }
              </button>
              <button type="button" className="mission-df-frequency-btn">
                { __( 'Ongoing', 'missionwp-donation-platform' ) }
              </button>
            </div>
          ) }

          <div
            className={ `mission-df-amount-grid${
              hasDescriptions ? ' has-descriptions' : ''
            }` }
          >
            { amounts.map( ( amount, index ) => {
              const desc = ! isLoading
                ? displayedFreqDescs[ String( amount ) ] || ''
                : '';
              return (
                <button
                  key={ isLoading ? index : amount }
                  type="button"
                  className="mission-df-amount-btn"
                >
                  <span>
                    { isLoading ? '' : formatAmount( amount, currency ) }
                  </span>
                  { desc && (
                    <span className="mission-df-amount-desc">{ desc }</span>
                  ) }
                </button>
              );
            } ) }
            { customAmount && (
              <button
                type="button"
                className="mission-df-amount-btn mission-df-amount-btn--other"
              >
                { __( 'Other', 'missionwp-donation-platform' ) }
              </button>
            ) }
          </div>

          <button
            type="button"
            className="mission-df-btn mission-df-btn--primary"
          >
            { attributes.continueButtonText ||
              __( 'Continue', 'missionwp-donation-platform' ) }
          </button>
        </div>
      </div>
    </div>
  );
}
