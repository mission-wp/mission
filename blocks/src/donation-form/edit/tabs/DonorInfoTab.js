import { __ } from '@wordpress/i18n';
import ToggleRow from '@shared/components/ToggleRow';

export default function DonorInfoTab( { localState, updateField } ) {
  return (
    <div className="mission-form-settings-tab">
      <ToggleRow
        label={ __(
          'Allow anonymous donations',
          'missionwp-donation-platform'
        ) }
        hint={ __(
          'Let donors hide their name from public displays',
          'missionwp-donation-platform'
        ) }
        checked={ localState.anonymousEnabled }
        onChange={ ( val ) => updateField( 'anonymousEnabled', val ) }
      />
      <ToggleRow
        label={ __( 'Allow donor comments', 'missionwp-donation-platform' ) }
        hint={ __(
          'Show a comment field where donors can leave a message',
          'missionwp-donation-platform'
        ) }
        checked={ localState.commentsEnabled }
        onChange={ ( val ) => updateField( 'commentsEnabled', val ) }
      />
      <ToggleRow
        label={ __( 'Require phone number', 'missionwp-donation-platform' ) }
        hint={ __(
          'Show and require a phone number field',
          'missionwp-donation-platform'
        ) }
        checked={ localState.phoneRequired }
        onChange={ ( val ) => updateField( 'phoneRequired', val ) }
      />
      <ToggleRow
        label={ __( 'Require billing address', 'missionwp-donation-platform' ) }
        hint={ __(
          "Collect the donor's full billing address",
          'missionwp-donation-platform'
        ) }
        checked={ localState.collectAddress }
        onChange={ ( val ) => updateField( 'collectAddress', val ) }
      />
      <ToggleRow
        label={ __( 'Allow dedications', 'missionwp-donation-platform' ) }
        hint={ __(
          'Let donors dedicate their gift in honor or memory of someone',
          'missionwp-donation-platform'
        ) }
        checked={ localState.tributeEnabled }
        onChange={ ( val ) => updateField( 'tributeEnabled', val ) }
      />
    </div>
  );
}
