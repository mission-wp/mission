import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getCurrencySymbol } from '@shared/currency';
import GoalTypePicker from '../GoalTypePicker';

export default function StepCampaign( { data, updateData, errors } ) {
  const symbol = useMemo(
    () => getCurrencySymbol( data.currency || 'USD' ),
    [ data.currency ]
  );

  const openMediaLibrary = () => {
    const frame = wp.media( {
      title: __( 'Select Campaign Image', 'mission-donation-platform' ),
      library: { type: 'image' },
      multiple: false,
      button: { text: __( 'Use this image', 'mission-donation-platform' ) },
    } );

    frame.on( 'select', () => {
      const attachment = frame.state().get( 'selection' ).first().toJSON();
      updateData( {
        campaign_image: attachment.id,
        campaign_image_url: attachment.sizes?.medium?.url || attachment.url,
      } );
    } );

    frame.open();
  };

  return (
    <>
      <h1 className="mission-onboarding-step__heading">
        { __( 'Create a campaign', 'mission-donation-platform' ) }
      </h1>
      <p className="mission-onboarding-step__subheading">
        { __(
          'Campaigns organize your fundraising efforts. Fill out the details below to generate a ready-to-share campaign page.',
          'mission-donation-platform'
        ) }
      </p>

      { /* Campaign Name */ }
      <div
        className={ `mission-onboarding-field${
          errors.campaign_name ? ' has-error' : ''
        }` }
      >
        <label
          className="mission-onboarding-field__label"
          htmlFor="ob-campaign-name"
        >
          { __( 'Campaign Name', 'mission-donation-platform' ) }
        </label>
        <input
          type="text"
          className="mission-onboarding-field__input"
          id="ob-campaign-name"
          value={ data.campaign_name }
          onChange={ ( e ) => updateData( { campaign_name: e.target.value } ) }
          placeholder="e.g. General Fund"
        />
        { errors.campaign_name && (
          <span className="mission-onboarding-field__error">
            { errors.campaign_name }
          </span>
        ) }
      </div>

      { /* Description */ }
      <div className="mission-onboarding-field">
        <label
          className="mission-onboarding-field__label"
          htmlFor="ob-campaign-desc"
        >
          { __( 'Description', 'mission-donation-platform' ) }
          <span className="mission-onboarding-field__optional">
            { __( '\u2014 optional', 'mission-donation-platform' ) }
          </span>
        </label>
        <textarea
          className="mission-onboarding-field__textarea"
          id="ob-campaign-desc"
          value={ data.campaign_description }
          onChange={ ( e ) =>
            updateData( { campaign_description: e.target.value } )
          }
          placeholder={ __(
            'A brief description of this campaign\u2026',
            'mission-donation-platform'
          ) }
        />
      </div>

      { /* Goal Type */ }
      <div className="mission-onboarding-field">
        <span className="mission-onboarding-field__label">
          { __( 'Goal Type', 'mission-donation-platform' ) }
        </span>
        <GoalTypePicker
          value={ data.campaign_goal_type || 'amount' }
          onChange={ ( value ) => updateData( { campaign_goal_type: value } ) }
        />
      </div>

      { /* Goal Amount */ }
      <div
        className={ `mission-onboarding-field${
          errors.campaign_goal ? ' has-error' : ''
        }` }
      >
        <label
          className="mission-onboarding-field__label"
          htmlFor="ob-campaign-goal"
        >
          { /* eslint-disable-next-line no-nested-ternary */ }
          { data.campaign_goal_type === 'amount'
            ? __( 'Goal Amount', 'mission-donation-platform' )
            : data.campaign_goal_type === 'donations'
            ? __( 'Number of Donations', 'mission-donation-platform' )
            : __( 'Number of Donors', 'mission-donation-platform' ) }
        </label>
        { data.campaign_goal_type === 'amount' ? (
          <div className="mission-onboarding-currency-wrap">
            <span className="mission-onboarding-currency-symbol">
              { symbol }
            </span>
            <input
              type="text"
              className="mission-onboarding-field__input"
              id="ob-campaign-goal"
              value={ data.campaign_goal }
              onChange={ ( e ) =>
                updateData( { campaign_goal: e.target.value } )
              }
              placeholder="5,000"
            />
          </div>
        ) : (
          <input
            type="text"
            className="mission-onboarding-field__input"
            id="ob-campaign-goal"
            value={ data.campaign_goal }
            onChange={ ( e ) =>
              updateData( { campaign_goal: e.target.value } )
            }
            placeholder="100"
          />
        ) }
        { errors.campaign_goal && (
          <span className="mission-onboarding-field__error">
            { errors.campaign_goal }
          </span>
        ) }
      </div>

      { /* Dates */ }
      <div className="mission-onboarding-field-row">
        <div className="mission-onboarding-field">
          <label
            className="mission-onboarding-field__label"
            htmlFor="ob-campaign-start"
          >
            { __( 'Start Date', 'mission-donation-platform' ) }
            <span className="mission-onboarding-field__optional">
              { __( '\u2014 optional', 'mission-donation-platform' ) }
            </span>
          </label>
          <input
            type="date"
            className="mission-onboarding-field__input"
            id="ob-campaign-start"
            value={ data.campaign_date_start }
            onChange={ ( e ) =>
              updateData( { campaign_date_start: e.target.value } )
            }
          />
        </div>
        <div className="mission-onboarding-field">
          <label
            className="mission-onboarding-field__label"
            htmlFor="ob-campaign-end"
          >
            { __( 'End Date', 'mission-donation-platform' ) }
            <span className="mission-onboarding-field__optional">
              { __( '\u2014 optional', 'mission-donation-platform' ) }
            </span>
          </label>
          <input
            type="date"
            className="mission-onboarding-field__input"
            id="ob-campaign-end"
            value={ data.campaign_date_end }
            onChange={ ( e ) =>
              updateData( { campaign_date_end: e.target.value } )
            }
          />
        </div>
      </div>

      { /* Image */ }
      <div className="mission-onboarding-field">
        <span className="mission-onboarding-field__label">
          { __( 'Campaign Image', 'mission-donation-platform' ) }
          <span className="mission-onboarding-field__optional">
            { __( '\u2014 optional', 'mission-donation-platform' ) }
          </span>
        </span>
        { data.campaign_image_url ? (
          <div className="mission-onboarding-image-preview">
            <img
              src={ data.campaign_image_url }
              alt={ __(
                'Campaign image preview',
                'mission-donation-platform'
              ) }
            />
            <div className="mission-onboarding-image-preview__actions">
              <button
                type="button"
                className="mission-onboarding-image-btn"
                onClick={ openMediaLibrary }
              >
                { __( 'Replace', 'mission-donation-platform' ) }
              </button>
              <button
                type="button"
                className="mission-onboarding-image-btn mission-onboarding-image-btn--danger"
                onClick={ () =>
                  updateData( {
                    campaign_image: null,
                    campaign_image_url: '',
                  } )
                }
              >
                { __( 'Remove', 'mission-donation-platform' ) }
              </button>
            </div>
          </div>
        ) : (
          <div
            className="mission-onboarding-image-upload"
            onClick={ openMediaLibrary }
            onKeyDown={ ( e ) => {
              if ( e.key === 'Enter' || e.key === ' ' ) {
                e.preventDefault();
                openMediaLibrary();
              }
            } }
            role="button"
            tabIndex={ 0 }
          >
            <div className="mission-onboarding-image-upload__icon">
              <svg
                width="28"
                height="28"
                viewBox="0 0 28 28"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <rect x="2" y="4" width="24" height="20" rx="3" />
                <circle cx="9" cy="11" r="3" />
                <path d="M26 18l-7-7L5 25" />
              </svg>
            </div>
            <span className="mission-onboarding-image-upload__text">
              { __(
                'Click to upload or select from media library',
                'mission-donation-platform'
              ) }
            </span>
            <span className="mission-onboarding-image-upload__hint">
              { __(
                'PNG, JPG, or WebP up to 5MB',
                'mission-donation-platform'
              ) }
            </span>
          </div>
        ) }
      </div>
    </>
  );
}
