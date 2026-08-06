import { __ } from '@wordpress/i18n';
import { getCurrencySymbol } from '@shared/currency';
import ToggleRow from '@shared/components/ToggleRow';

/**
 * The Peer-to-Peer settings card on a P2P campaign's Settings tab.
 *
 * @param {Object}   props
 * @param {Object}   props.formState     Settings form state.
 * @param {Function} props.updateField   ( field, value ) => void updater.
 * @param {Function} props.handleKeyDown Enter-to-save handler for text inputs.
 * @return {JSX.Element} The settings card.
 */
export default function P2PSettings( {
  formState,
  updateField,
  handleKeyDown,
} ) {
  const symbol = getCurrencySymbol();

  return (
    <div
      className="mission-card mission-settings-section"
      style={ { marginBottom: 32 } }
    >
      <h3 className="mission-settings-section__title">
        { __( 'Peer-to-Peer', 'mission-donation-platform' ) }
      </h3>

      <ToggleRow
        checked={ formState.registration_open }
        onChange={ ( val ) => updateField( 'registration_open', val ) }
        label={ __( 'Registration open', 'mission-donation-platform' ) }
        hint={ __(
          'Allow new supporters to sign up as fundraisers',
          'mission-donation-platform'
        ) }
        style={ { marginBottom: 16 } }
      />

      <ToggleRow
        checked={ formState.approval_required }
        onChange={ ( val ) => updateField( 'approval_required', val ) }
        label={ __(
          'Require approval for fundraisers',
          'mission-donation-platform'
        ) }
        hint={ __(
          'New fundraiser pages stay pending until you approve them',
          'mission-donation-platform'
        ) }
        style={ { marginBottom: 20 } }
      />

      <div
        className="mission-field-group"
        style={ { marginBottom: 20, maxWidth: '50%' } }
      >
        <label className="mission-field-label" htmlFor="p2p-fundraiser-goal">
          { __( 'Default fundraiser goal', 'mission-donation-platform' ) }
        </label>
        <div className="mission-field-currency">
          <span className="mission-field-currency__symbol">{ symbol }</span>
          <input
            id="p2p-fundraiser-goal"
            type="text"
            className="mission-field-input"
            value={ formState.default_fundraiser_goal }
            onChange={ ( e ) =>
              updateField( 'default_fundraiser_goal', e.target.value )
            }
            onKeyDown={ handleKeyDown }
          />
        </div>
        <span className="mission-field-hint">
          { __(
            'Suggested goal for a new fundraiser',
            'mission-donation-platform'
          ) }
        </span>
      </div>

      <div className="mission-field-group" style={ { marginBottom: 20 } }>
        <label className="mission-field-label" htmlFor="p2p-story">
          { __( 'Story placeholder', 'mission-donation-platform' ) }
        </label>
        <textarea
          id="p2p-story"
          className="mission-field-input"
          rows={ 3 }
          value={ formState.story_placeholder }
          onChange={ ( e ) =>
            updateField( 'story_placeholder', e.target.value )
          }
        />
        <span className="mission-field-hint">
          { __(
            'Suggested text shown in the fundraiser story field',
            'mission-donation-platform'
          ) }
        </span>
      </div>

      <ToggleRow
        checked={ formState.teams_enabled }
        onChange={ ( val ) => updateField( 'teams_enabled', val ) }
        label={ __( 'Enable teams', 'mission-donation-platform' ) }
        hint={ __(
          'Let fundraisers group together into teams',
          'mission-donation-platform'
        ) }
        style={ { marginBottom: 16 } }
      />

      { formState.teams_enabled && (
        <>
          <ToggleRow
            checked={ formState.team_creation_enabled }
            onChange={ ( val ) => updateField( 'team_creation_enabled', val ) }
            label={ __(
              'Let supporters create teams',
              'mission-donation-platform'
            ) }
            hint={ __(
              'Otherwise only admins create teams',
              'mission-donation-platform'
            ) }
            style={ { marginBottom: 16 } }
          />

          <ToggleRow
            checked={ formState.team_approval_required }
            onChange={ ( val ) => updateField( 'team_approval_required', val ) }
            label={ __(
              'Require approval for teams',
              'mission-donation-platform'
            ) }
            hint={ __(
              'New teams stay pending until you approve them',
              'mission-donation-platform'
            ) }
            style={ { marginBottom: 20 } }
          />

          <div
            className="mission-field-group"
            style={ { marginBottom: 20, maxWidth: '50%' } }
          >
            <label className="mission-field-label" htmlFor="p2p-team-goal">
              { __( 'Default team goal', 'mission-donation-platform' ) }
            </label>
            <div className="mission-field-currency">
              <span className="mission-field-currency__symbol">{ symbol }</span>
              <input
                id="p2p-team-goal"
                type="text"
                className="mission-field-input"
                value={ formState.default_team_goal }
                onChange={ ( e ) =>
                  updateField( 'default_team_goal', e.target.value )
                }
                onKeyDown={ handleKeyDown }
              />
            </div>
          </div>

          <div className="mission-field-group">
            <label className="mission-field-label" htmlFor="p2p-team-story">
              { __( 'Team story placeholder', 'mission-donation-platform' ) }
            </label>
            <textarea
              id="p2p-team-story"
              className="mission-field-input"
              rows={ 3 }
              value={ formState.team_story_placeholder }
              onChange={ ( e ) =>
                updateField( 'team_story_placeholder', e.target.value )
              }
            />
          </div>
        </>
      ) }
    </div>
  );
}
