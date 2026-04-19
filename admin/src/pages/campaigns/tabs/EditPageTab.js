import { __ } from '@wordpress/i18n';
import { Button, Icon } from '@wordpress/components';
import { check } from '@wordpress/icons';
import CampaignBlockEditor from '../components/CampaignBlockEditor';

export default function EditPageTab( {
  campaign,
  formState,
  setFormState,
  onSave,
  isSaving,
  saveSuccess,
  saveError,
} ) {
  const updateField = ( field, value ) => {
    setFormState( ( prev ) => ( { ...prev, [ field ]: value } ) );
  };

  const handleKeyDown = ( e ) => {
    if ( e.key === 'Enter' ) {
      e.preventDefault();
      onSave();
    }
  };

  const siteUrl = campaign.url
    ? campaign.url.replace( /\/[^/]*\/?$/, '/' )
    : '';
  const currentSlug = formState.slug || '';

  const openMediaLibrary = () => {
    const frame = wp.media( {
      title: __( 'Select Campaign Image', 'missionwp-donation-platform' ),
      library: { type: 'image' },
      multiple: false,
      button: { text: __( 'Use this image', 'missionwp-donation-platform' ) },
    } );

    frame.on( 'select', () => {
      const attachment = frame.state().get( 'selection' ).first().toJSON();
      updateField( 'image', attachment.id );
      updateField(
        'image_url',
        attachment.sizes?.medium?.url || attachment.url
      );
    } );

    frame.open();
  };

  const removeImage = () => {
    updateField( 'image', null );
    updateField( 'image_url', '' );
  };

  return (
    <div className="mission-tab-panel">
      { /* Visibility */ }
      <div className="mission-card" style={ { marginBottom: 24 } }>
        <h3 className="mission-settings-section__title">
          { __( 'Visibility', 'missionwp-donation-platform' ) }
        </h3>
        <div className="mission-toggle-row">
          { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
          <label
            className="mission-toggle-switch"
            aria-label={ __( 'Campaign page', 'missionwp-donation-platform' ) }
          >
            <input
              type="checkbox"
              checked={ formState.has_campaign_page }
              onChange={ ( e ) =>
                updateField( 'has_campaign_page', e.target.checked )
              }
            />
            <span className="mission-toggle-switch__slider" />
          </label>
          { /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
          <div
            onClick={ () =>
              updateField( 'has_campaign_page', ! formState.has_campaign_page )
            }
            style={ { cursor: 'pointer' } }
          >
            <div className="mission-toggle-row__label">
              { __( 'Campaign page', 'missionwp-donation-platform' ) }
            </div>
            <div className="mission-toggle-row__hint">
              { __(
                'Give this campaign its own dedicated page where donors can learn more and give',
                'missionwp-donation-platform'
              ) }
            </div>
          </div>
        </div>
        <div className="mission-toggle-row" style={ { marginTop: 16 } }>
          { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
          <label
            className="mission-toggle-switch"
            aria-label={ __(
              'Show in campaign listings',
              'missionwp-donation-platform'
            ) }
          >
            <input
              type="checkbox"
              checked={ formState.show_in_listings }
              onChange={ ( e ) =>
                updateField( 'show_in_listings', e.target.checked )
              }
            />
            <span className="mission-toggle-switch__slider" />
          </label>
          { /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
          <div
            onClick={ () =>
              updateField( 'show_in_listings', ! formState.show_in_listings )
            }
            style={ { cursor: 'pointer' } }
          >
            <div className="mission-toggle-row__label">
              { __(
                'Show in campaign listings',
                'missionwp-donation-platform'
              ) }
            </div>
            <div className="mission-toggle-row__hint">
              { __(
                'Display this campaign on your public campaigns page',
                'missionwp-donation-platform'
              ) }
            </div>
          </div>
        </div>
      </div>

      { /* Page editor section — conditional on has_campaign_page */ }
      { formState.has_campaign_page && (
        <div>
          { /* Slug */ }
          <div className="mission-field-group" style={ { marginBottom: 24 } }>
            <label className="mission-field-label" htmlFor="campaign-slug">
              { __( 'Campaign URL', 'missionwp-donation-platform' ) }
            </label>
            <div className="mission-field-slug">
              <span className="mission-field-slug__prefix">{ siteUrl }</span>
              <input
                id="campaign-slug"
                type="text"
                className="mission-field-input"
                value={ currentSlug }
                onChange={ ( e ) => updateField( 'slug', e.target.value ) }
                onKeyDown={ handleKeyDown }
                style={ {
                  width: currentSlug
                    ? `${ currentSlug.length + 3 }ch`
                    : '165px',
                } }
              />
            </div>
          </div>

          { /* Block editor */ }
          <CampaignBlockEditor
            postId={ campaign.post_id }
            editUrl={ campaign.edit_url }
          />

          { /* Page fields */ }
          <div className="mission-card" style={ { marginBottom: 24 } }>
            <div className="mission-field-group" style={ { marginBottom: 24 } }>
              <span className="mission-field-label">
                { __( 'Campaign Image', 'missionwp-donation-platform' ) }
              </span>
              { formState.image_url ? (
                <div className="mission-image-preview">
                  <img
                    src={ formState.image_url }
                    alt={ __(
                      'Campaign image preview',
                      'missionwp-donation-platform'
                    ) }
                  />
                  <div
                    className="mission-image-preview__actions"
                    style={ {
                      display: 'flex',
                      gap: 8,
                      justifyContent: 'center',
                      marginTop: 12,
                    } }
                  >
                    <Button
                      variant="secondary"
                      size="compact"
                      onClick={ openMediaLibrary }
                    >
                      { __( 'Replace', 'missionwp-donation-platform' ) }
                    </Button>
                    <Button
                      variant="tertiary"
                      size="compact"
                      isDestructive
                      onClick={ removeImage }
                    >
                      { __( 'Remove', 'missionwp-donation-platform' ) }
                    </Button>
                  </div>
                </div>
              ) : (
                <div
                  className="mission-image-upload-zone"
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
                  <div className="mission-image-upload-zone__icon">
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
                  <span className="mission-image-upload-zone__text">
                    { __(
                      'Click to upload or drag and drop',
                      'missionwp-donation-platform'
                    ) }
                  </span>
                  <span className="mission-image-upload-zone__hint">
                    { __(
                      'PNG, JPG, or WebP up to 5MB',
                      'missionwp-donation-platform'
                    ) }
                  </span>
                </div>
              ) }
            </div>

            <div className="mission-field-group">
              <label className="mission-field-label" htmlFor="campaign-excerpt">
                { __( 'Short Description', 'missionwp-donation-platform' ) }
              </label>
              <textarea
                id="campaign-excerpt"
                className="mission-field-textarea"
                placeholder={ __(
                  'A brief summary shown in campaign listings and search results…',
                  'missionwp-donation-platform'
                ) }
                value={ formState.excerpt }
                onChange={ ( e ) => updateField( 'excerpt', e.target.value ) }
              />
            </div>
          </div>
        </div>
      ) }

      { /* Save button */ }
      <div className="mission-form-actions">
        <Button
          variant="primary"
          className={ saveError ? 'mission-btn-shake' : undefined }
          onClick={ onSave }
          isBusy={ isSaving }
          disabled={ isSaving }
          __next40pxDefaultSize
        >
          { saveSuccess && <Icon icon={ check } size={ 20 } /> }
          { saveSuccess
            ? __( 'Changes Saved', 'missionwp-donation-platform' )
            : __( 'Save Changes', 'missionwp-donation-platform' ) }
        </Button>
      </div>
    </div>
  );
}
