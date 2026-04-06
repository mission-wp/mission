import { useMemo } from '@wordpress/element';
import { TextControl, TextareaControl } from '@wordpress/components';
import { sprintf, __ } from '@wordpress/i18n';

const SEASONS = [
  __( 'Winter', 'mission' ),
  __( 'Winter', 'mission' ),
  __( 'Spring', 'mission' ),
  __( 'Spring', 'mission' ),
  __( 'Spring', 'mission' ),
  __( 'Summer', 'mission' ),
  __( 'Summer', 'mission' ),
  __( 'Summer', 'mission' ),
  __( 'Fall', 'mission' ),
  __( 'Fall', 'mission' ),
  __( 'Fall', 'mission' ),
  __( 'Winter', 'mission' ),
];

export default function CampaignCreateStepBasics( { data, onChange } ) {
  const placeholder = useMemo( () => {
    const now = new Date();
    const season = SEASONS[ now.getMonth() ];
    return sprintf(
      /* translators: 1: season name, 2: four-digit year */
      __( 'e.g. %1$s Fundraiser %2$s', 'mission' ),
      season,
      now.getFullYear()
    );
  }, [] );

  return (
    <>
      <TextControl
        label={ __( 'Campaign Name', 'mission' ) }
        value={ data.title }
        onChange={ ( value ) => onChange( { title: value } ) }
        placeholder={ placeholder }
        required
        __nextHasNoMarginBottom
        __next40pxDefaultSize
      />
      <TextareaControl
        label={ __( 'Short Description', 'mission' ) }
        value={ data.excerpt }
        onChange={ ( value ) => onChange( { excerpt: value } ) }
        placeholder={ __(
          'Briefly describe the purpose of this campaign. This will be used as a starting point for your campaign page.',
          'mission'
        ) }
        rows={ 4 }
        __nextHasNoMarginBottom
      />
    </>
  );
}
