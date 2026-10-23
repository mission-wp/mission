import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { computePrimaryColorVars } from '@shared/color';
import { formatAmount } from '@shared/currency';
import { FREQUENCIES } from '@shared/components/FrequencyChips';

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

/** Default heading when a recurring-only form offers a single frequency. */
const RECURRING_HEADINGS = {
  weekly: __( 'Choose Your Weekly Gift', 'mission-donation-platform' ),
  monthly: __( 'Choose Your Monthly Gift', 'mission-donation-platform' ),
  quarterly: __( 'Choose Your Quarterly Gift', 'mission-donation-platform' ),
  annually: __( 'Choose Your Annual Gift', 'mission-donation-platform' ),
};

export default function FormPreview( { attributes = {} } ) {
  const [ settings, setSettings ] = useState( null );

  useEffect( () => {
    apiFetch( { path: '/mission-donation-platform/v1/donation-form-settings' } )
      .then( ( response ) => setSettings( response ) )
      .catch( () => setSettings( null ) );
  }, [] );

  const isLoading = ! settings;

  const recurringEnabled =
    attributes.recurringEnabled ?? settings?.recurringEnabled ?? true;
  const oneTimeEnabled =
    attributes.oneTimeEnabled ?? settings?.oneTimeEnabled ?? true;
  const recurringFrequencies =
    attributes.recurringFrequencies ?? settings?.recurringFrequencies ?? [];
  const recurringDefault =
    attributes.recurringDefault ?? settings?.recurringDefault;
  const recurringOnly =
    recurringEnabled && ! oneTimeEnabled && recurringFrequencies.length > 0;
  const previewFrequency = recurringOnly
    ? [ recurringDefault, 'monthly' ].find( ( f ) =>
        recurringFrequencies.includes( f )
      ) || recurringFrequencies[ 0 ]
    : 'one_time';
  const previewFrequencyLabel = FREQUENCIES.find(
    ( f ) => f.id === previewFrequency
  )?.label;
  const defaultHeading =
    ( recurringOnly &&
      recurringFrequencies.length === 1 &&
      RECURRING_HEADINGS[ previewFrequency ] ) ||
    __( 'Choose Your Gift', 'mission-donation-platform' );
  const customAmount =
    attributes.customAmount ?? settings?.customAmount ?? true;
  const currency = settings?.currency ?? 'USD';
  const amountsByFrequency =
    attributes.amountsByFrequency ?? settings?.amountsByFrequency ?? {};
  const amountDescriptions =
    attributes.amountDescriptions ?? settings?.amountDescriptions ?? {};

  const primaryColor =
    attributes.primaryColor || settings?.globalPrimaryColor || '#2fa36b';
  const formCssVars = {
    ...BASE_CSS_VARS,
    ...computePrimaryColorVars( primaryColor ),
  };

  const amounts = isLoading
    ? SKELETON_AMOUNTS
    : amountsByFrequency[ previewFrequency ] ||
      amountsByFrequency.one_time ||
      amountsByFrequency.monthly ||
      [];

  const displayedFreqDescs = amountDescriptions[ previewFrequency ] || {};
  const hasDescriptions =
    ! isLoading &&
    Object.values( displayedFreqDescs ).some( ( d ) => d && d.trim() );

  return (
    <div className="mission-df-editor-preview" style={ formCssVars }>
      <div className="mission-donation-form">
        <div className="mission-df-step mission-df-step-1 active">
          <h2 className="mission-df-step-title">
            { attributes.chooseGiftHeading || defaultHeading }
          </h2>

          { recurringEnabled && ! recurringOnly && (
            <div className="mission-df-frequency-toggle">
              <button type="button" className="mission-df-frequency-btn active">
                { __( 'One Time', 'mission-donation-platform' ) }
              </button>
              <button type="button" className="mission-df-frequency-btn">
                { __( 'Ongoing', 'mission-donation-platform' ) }
              </button>
            </div>
          ) }

          { recurringOnly && recurringFrequencies.length > 1 && (
            <div className="mission-df-recurring-selector visible">
              <span className="mission-df-recurring-label">
                { __( 'Give', 'mission-donation-platform' ) }
              </span>
              <button type="button" className="mission-df-recurring-trigger">
                <span>{ previewFrequencyLabel }</span>
                <span className="mission-df-recurring-arrow" />
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
                    { isLoading
                      ? ''
                      : formatAmount( amount, currency, {
                          stripZeroCents: true,
                        } ) }
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
                { __( 'Other', 'mission-donation-platform' ) }
              </button>
            ) }
          </div>

          <button
            type="button"
            className="mission-df-btn mission-df-btn--primary"
          >
            { attributes.continueButtonText ||
              __( 'Continue', 'mission-donation-platform' ) }
          </button>
        </div>
      </div>
    </div>
  );
}
