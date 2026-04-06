import { __ } from '@wordpress/i18n';
import { getRegionConfig } from '@shared/address';
import ColorPicker from '@shared/components/ColorPicker';

export default function StepDetails( { data, updateData } ) {
  const regionConfig = getRegionConfig( data.org_country || 'US' );

  return (
    <>
      <h1 className="mission-onboarding-step__heading">
        { __( 'Organization details', 'mission' ) }
      </h1>
      <p className="mission-onboarding-step__subheading">
        { __(
          'This information appears on donation receipts and email footers.',
          'mission'
        ) }
      </p>

      <div className="mission-onboarding-field">
        <label className="mission-onboarding-field__label" htmlFor="ob-street">
          { __( 'Street Address', 'mission' ) }
        </label>
        <input
          type="text"
          className="mission-onboarding-field__input"
          id="ob-street"
          value={ data.org_street }
          onChange={ ( e ) => updateData( { org_street: e.target.value } ) }
          placeholder="123 Main Street"
        />
      </div>

      <div className="mission-onboarding-field-row-3">
        <div className="mission-onboarding-field">
          <label className="mission-onboarding-field__label" htmlFor="ob-city">
            { __( 'City', 'mission' ) }
          </label>
          <input
            type="text"
            className="mission-onboarding-field__input"
            id="ob-city"
            value={ data.org_city }
            onChange={ ( e ) => updateData( { org_city: e.target.value } ) }
            placeholder="Springfield"
          />
        </div>
        <div className="mission-onboarding-field">
          <label className="mission-onboarding-field__label" htmlFor="ob-state">
            { regionConfig.label }
          </label>
          { regionConfig.options ? (
            <select
              className="mission-onboarding-field__select"
              id="ob-state"
              value={ data.org_state }
              onChange={ ( e ) => updateData( { org_state: e.target.value } ) }
            >
              <option value="" disabled>
                { __( 'Select', 'mission' ) }
              </option>
              { regionConfig.options.map( ( s ) => (
                <option key={ s.value } value={ s.value }>
                  { s.label }
                </option>
              ) ) }
            </select>
          ) : (
            <input
              type="text"
              className="mission-onboarding-field__input"
              id="ob-state"
              value={ data.org_state }
              onChange={ ( e ) => updateData( { org_state: e.target.value } ) }
            />
          ) }
        </div>
        <div className="mission-onboarding-field">
          <label className="mission-onboarding-field__label" htmlFor="ob-zip">
            { regionConfig.postalLabel }
          </label>
          <input
            type="text"
            className="mission-onboarding-field__input"
            id="ob-zip"
            value={ data.org_zip }
            onChange={ ( e ) => updateData( { org_zip: e.target.value } ) }
            placeholder={ data.org_country === 'US' ? '62701' : '' }
          />
        </div>
      </div>

      <div className="mission-onboarding-field">
        <label
          className="mission-onboarding-field__label"
          htmlFor="ob-color-hex"
        >
          { __( 'Brand Color', 'mission' ) }
        </label>
        <ColorPicker
          value={ data.primary_color }
          onChange={ ( val ) => updateData( { primary_color: val } ) }
          id="ob-color-hex"
        />
        <span className="mission-onboarding-field__hint">
          { __( 'Used for donation forms, emails, and receipts.', 'mission' ) }
        </span>
      </div>
    </>
  );
}
