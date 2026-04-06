import { __ } from '@wordpress/i18n';
import ToggleRow from '@shared/components/ToggleRow';

export default function DonorInfoTab( { localState, updateField } ) {
  return (
    <div className="mission-form-settings-tab">
      <ToggleRow
        label={ __( 'Allow anonymous donations', 'mission' ) }
        hint={ __(
          'Let donors hide their name from public displays',
          'mission'
        ) }
        checked={ localState.anonymousEnabled }
        onChange={ ( val ) => updateField( 'anonymousEnabled', val ) }
      />
      <ToggleRow
        label={ __( 'Allow donor comments', 'mission' ) }
        hint={ __(
          'Show a comment field where donors can leave a message',
          'mission'
        ) }
        checked={ localState.commentsEnabled }
        onChange={ ( val ) => updateField( 'commentsEnabled', val ) }
      />
      <ToggleRow
        label={ __( 'Require phone number', 'mission' ) }
        hint={ __( 'Show and require a phone number field', 'mission' ) }
        checked={ localState.phoneRequired }
        onChange={ ( val ) => updateField( 'phoneRequired', val ) }
      />
      <ToggleRow
        label={ __( 'Require billing address', 'mission' ) }
        hint={ __( "Collect the donor's full billing address", 'mission' ) }
        checked={ localState.collectAddress }
        onChange={ ( val ) => updateField( 'collectAddress', val ) }
      />
      <ToggleRow
        label={ __( 'Allow dedications', 'mission' ) }
        hint={ __(
          'Let donors dedicate their gift in honor or memory of someone',
          'mission'
        ) }
        checked={ localState.tributeEnabled }
        onChange={ ( val ) => updateField( 'tributeEnabled', val ) }
      />
    </div>
  );
}
