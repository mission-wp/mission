import { __ } from '@wordpress/i18n';

const HEX_REGEX = /^#[0-9a-fA-F]{6}$/;

export default function AppearanceTab( {
  localState,
  updateField,
  globalPrimaryColor,
} ) {
  const hasCustomFields = localState.customFields?.length > 0;
  const effectiveColor =
    localState.primaryColor || globalPrimaryColor || '#2fa36b';
  const hasCustomColor = !! localState.primaryColor;

  const handleColorInput = ( value ) => {
    if ( value === '' ) {
      updateField( 'primaryColor', '' );
    } else if ( HEX_REGEX.test( value ) ) {
      updateField( 'primaryColor', value );
    }
  };

  return (
    <div className="mission-form-settings-tab">
      <div className="mission-field-group">
        <label
          className="mission-field-label"
          htmlFor="mission-appearance-color"
        >
          { __( 'Primary Color', 'missionwp-donation-platform' ) }
        </label>
        <div className="mission-appearance-tab__color-row">
          <input
            type="color"
            value={ effectiveColor }
            onChange={ ( e ) => updateField( 'primaryColor', e.target.value ) }
            className="mission-appearance-tab__color-swatch"
          />
          <input
            type="text"
            id="mission-appearance-color"
            className="mission-field-input"
            value={ hasCustomColor ? localState.primaryColor : effectiveColor }
            onChange={ ( e ) => handleColorInput( e.target.value ) }
            placeholder={ globalPrimaryColor }
            style={ { maxWidth: '120px' } }
          />
          { hasCustomColor && (
            <button
              type="button"
              className="mission-appearance-tab__color-reset"
              onClick={ () => updateField( 'primaryColor', '' ) }
            >
              { __( 'Reset to global', 'missionwp-donation-platform' ) }
            </button>
          ) }
        </div>
        <span className="mission-field-hint">
          { hasCustomColor
            ? __(
                'Using a custom color for this form',
                'missionwp-donation-platform'
              )
            : __( 'Using global setting', 'missionwp-donation-platform' ) }
        </span>
      </div>

      <div className="mission-appearance-tab__labels">
        <div className="mission-field-group">
          <label
            className="mission-field-label"
            htmlFor="mission-appearance-gift-heading"
          >
            { __( 'Choose Gift Heading', 'missionwp-donation-platform' ) }
          </label>
          <input
            type="text"
            id="mission-appearance-gift-heading"
            className="mission-field-input"
            value={ localState.chooseGiftHeading || '' }
            onChange={ ( e ) =>
              updateField( 'chooseGiftHeading', e.target.value )
            }
            placeholder={ __(
              'Choose Your Gift',
              'missionwp-donation-platform'
            ) }
          />
          <span className="mission-field-hint">
            { __(
              'The heading shown on the first step of the form',
              'missionwp-donation-platform'
            ) }
          </span>
        </div>

        <div className="mission-field-group">
          <label
            className="mission-field-label"
            htmlFor="mission-appearance-continue"
          >
            { __( 'Continue Button', 'missionwp-donation-platform' ) }
          </label>
          <input
            type="text"
            id="mission-appearance-continue"
            className="mission-field-input"
            value={ localState.continueButtonText || '' }
            onChange={ ( e ) =>
              updateField( 'continueButtonText', e.target.value )
            }
            placeholder={ __( 'Continue', 'missionwp-donation-platform' ) }
          />
          <span className="mission-field-hint">
            { __(
              'Text on the button that advances each step',
              'missionwp-donation-platform'
            ) }
          </span>
        </div>

        { hasCustomFields && (
          <div className="mission-field-group">
            <label
              className="mission-field-label"
              htmlFor="mission-appearance-custom-fields-heading"
            >
              { __( 'Additional Info Heading', 'missionwp-donation-platform' ) }
            </label>
            <input
              type="text"
              id="mission-appearance-additional-info-heading"
              className="mission-field-input"
              value={ localState.additionalInfoHeading || '' }
              onChange={ ( e ) =>
                updateField( 'additionalInfoHeading', e.target.value )
              }
              placeholder={ __(
                'Additional Information',
                'missionwp-donation-platform'
              ) }
            />
            <span className="mission-field-hint">
              { __(
                'The heading shown on the custom fields step',
                'missionwp-donation-platform'
              ) }
            </span>
          </div>
        ) }

        <div className="mission-field-group">
          <label
            className="mission-field-label"
            htmlFor="mission-appearance-summary-heading"
          >
            { __( 'Summary Heading', 'missionwp-donation-platform' ) }
          </label>
          <input
            type="text"
            id="mission-appearance-summary-heading"
            className="mission-field-input"
            value={ localState.summaryHeading || '' }
            onChange={ ( e ) =>
              updateField( 'summaryHeading', e.target.value )
            }
            placeholder={ __( 'Summary', 'missionwp-donation-platform' ) }
          />
          <span className="mission-field-hint">
            { __(
              'The heading shown on the payment/summary step',
              'missionwp-donation-platform'
            ) }
          </span>
        </div>

        <div className="mission-field-group">
          <label
            className="mission-field-label"
            htmlFor="mission-appearance-donate"
          >
            { __( 'Donate Button', 'missionwp-donation-platform' ) }
          </label>
          <input
            type="text"
            id="mission-appearance-donate"
            className="mission-field-input"
            value={ localState.donateButtonText || '' }
            onChange={ ( e ) =>
              updateField( 'donateButtonText', e.target.value )
            }
            placeholder={ __( 'Donate', 'missionwp-donation-platform' ) }
          />
          <span className="mission-field-hint">
            { __(
              'Text on the final submit button',
              'missionwp-donation-platform'
            ) }
          </span>
        </div>
      </div>
    </div>
  );
}
