import { useMemo } from '@wordpress/element';
import { TextControl, TextareaControl } from '@wordpress/components';
import { sprintf, __ } from '@wordpress/i18n';

const SEASONS = [
  __( 'Winter', 'missionwp-donation-platform' ),
  __( 'Winter', 'missionwp-donation-platform' ),
  __( 'Spring', 'missionwp-donation-platform' ),
  __( 'Spring', 'missionwp-donation-platform' ),
  __( 'Spring', 'missionwp-donation-platform' ),
  __( 'Summer', 'missionwp-donation-platform' ),
  __( 'Summer', 'missionwp-donation-platform' ),
  __( 'Summer', 'missionwp-donation-platform' ),
  __( 'Fall', 'missionwp-donation-platform' ),
  __( 'Fall', 'missionwp-donation-platform' ),
  __( 'Fall', 'missionwp-donation-platform' ),
  __( 'Winter', 'missionwp-donation-platform' ),
];

export default function CampaignCreateStepBasics( { data, onChange } ) {
  const placeholder = useMemo( () => {
    const now = new Date();
    const season = SEASONS[ now.getMonth() ];
    return sprintf(
      /* translators: 1: season name, 2: four-digit year */
      __( 'e.g. %1$s Fundraiser %2$s', 'missionwp-donation-platform' ),
      season,
      now.getFullYear()
    );
  }, [] );

  return (
    <>
      <TextControl
        label={ __( 'Campaign Name', 'missionwp-donation-platform' ) }
        value={ data.title }
        onChange={ ( value ) => onChange( { title: value } ) }
        placeholder={ placeholder }
        required
        __nextHasNoMarginBottom
        __next40pxDefaultSize
      />
      <TextareaControl
        label={ __( 'Short Description', 'missionwp-donation-platform' ) }
        value={ data.excerpt }
        onChange={ ( value ) => onChange( { excerpt: value } ) }
        placeholder={ __(
          'Briefly describe the purpose of this campaign. This will be used as a starting point for your campaign page.',
          'missionwp-donation-platform'
        ) }
        rows={ 4 }
        __nextHasNoMarginBottom
      />
    </>
  );
}
