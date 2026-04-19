import {
  Button,
  __experimentalVStack as VStack,
  __experimentalHStack as HStack,
  __experimentalText as Text,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function CampaignCreateStepImage( { data, onChange } ) {
  const openMediaLibrary = () => {
    const frame = wp.media( {
      title: __( 'Select Campaign Image', 'missionwp-donation-platform' ),
      library: { type: 'image' },
      multiple: false,
      button: { text: __( 'Use this image', 'missionwp-donation-platform' ) },
    } );

    frame.on( 'select', () => {
      const attachment = frame.state().get( 'selection' ).first().toJSON();
      onChange( {
        image: attachment.id,
        image_url: attachment.sizes?.medium?.url || attachment.url,
      } );
    } );

    frame.open();
  };

  const removeImage = () => {
    onChange( {
      image: null,
      image_url: '',
    } );
  };

  return (
    <VStack spacing={ 3 }>
      <Text as="label" weight="600" size="small" upperCase>
        { __( 'Campaign Image', 'missionwp-donation-platform' ) }
      </Text>

      { data.image_url ? (
        <div className="mission-image-preview">
          <img
            src={ data.image_url }
            alt={ __(
              'Campaign image preview',
              'missionwp-donation-platform'
            ) }
          />
          <HStack
            spacing={ 2 }
            justify="center"
            className="mission-image-preview__actions"
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
          </HStack>
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

      <Text variant="muted" size="small">
        { __(
          'This image will appear on your campaign page and in listings',
          'missionwp-donation-platform'
        ) }
      </Text>
    </VStack>
  );
}
