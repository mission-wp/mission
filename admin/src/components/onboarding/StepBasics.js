import { __ } from '@wordpress/i18n';
import { COUNTRIES } from '@shared/address';
import { CURRENCIES } from '@shared/currencies';

export default function StepBasics( { data, updateData, errors } ) {
  return (
    <>
      <h1 className="mission-onboarding-step__heading">
        { __(
          'Welcome to MissionWP Donation Platform',
          'missionwp-donation-platform'
        ) }
      </h1>
      <p className="mission-onboarding-step__subheading">
        { __(
          'Let\u2019s get your nonprofit set up to start accepting donations. This will only take a few minutes.',
          'missionwp-donation-platform'
        ) }
      </p>

      <div
        className={ `mission-onboarding-field${
          errors.org_name ? ' has-error' : ''
        }` }
      >
        <label
          className="mission-onboarding-field__label"
          htmlFor="ob-org-name"
        >
          { __( 'Organization Name', 'missionwp-donation-platform' ) }
        </label>
        <input
          type="text"
          className="mission-onboarding-field__input"
          id="ob-org-name"
          value={ data.org_name }
          onChange={ ( e ) => updateData( { org_name: e.target.value } ) }
        />
        { errors.org_name && (
          <span className="mission-onboarding-field__error">
            { errors.org_name }
          </span>
        ) }
      </div>

      <div className="mission-onboarding-field-row">
        <div className="mission-onboarding-field">
          <label
            className="mission-onboarding-field__label"
            htmlFor="ob-country"
          >
            { __( 'Country', 'missionwp-donation-platform' ) }
          </label>
          <select
            className="mission-onboarding-field__select"
            id="ob-country"
            value={ data.org_country }
            onChange={ ( e ) => updateData( { org_country: e.target.value } ) }
          >
            { COUNTRIES.map( ( c ) => (
              <option key={ c.value } value={ c.value }>
                { c.label }
              </option>
            ) ) }
          </select>
        </div>
        <div className="mission-onboarding-field">
          <label
            className="mission-onboarding-field__label"
            htmlFor="ob-currency"
          >
            { __( 'Currency', 'missionwp-donation-platform' ) }
          </label>
          <select
            className="mission-onboarding-field__select"
            id="ob-currency"
            value={ data.currency }
            onChange={ ( e ) => updateData( { currency: e.target.value } ) }
          >
            { CURRENCIES.map( ( c ) => (
              <option key={ c.value } value={ c.value }>
                { c.label }
              </option>
            ) ) }
          </select>
        </div>
      </div>

      <div className="mission-onboarding-field">
        <label className="mission-onboarding-field__label" htmlFor="ob-ein">
          { __( 'Tax ID / EIN', 'missionwp-donation-platform' ) }
          <span className="mission-onboarding-field__optional">
            { __( '\u2014 optional', 'missionwp-donation-platform' ) }
          </span>
        </label>
        <input
          type="text"
          className="mission-onboarding-field__input"
          id="ob-ein"
          value={ data.org_ein }
          onChange={ ( e ) => updateData( { org_ein: e.target.value } ) }
          placeholder="e.g. 12-3456789"
        />
        <span className="mission-onboarding-field__hint">
          { __(
            'Used on tax receipts. You can add this later.',
            'missionwp-donation-platform'
          ) }
        </span>
      </div>
    </>
  );
}
