import { useState, useEffect, useCallback } from '@wordpress/element';
import { Button, Icon } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { check } from '@wordpress/icons';
import { getCurrencySymbol } from '@shared/currency';
import GoalTypePicker from '../../../components/GoalTypePicker';
import ToggleRow from '@shared/components/ToggleRow';
import DangerZone from './settings/DangerZone';

const GOAL_LABELS = {
  amount: __( 'Fundraising Goal', 'missionwp-donation-platform' ),
  donations: __( 'Donation Goal', 'missionwp-donation-platform' ),
  donors: __( 'Donor Goal', 'missionwp-donation-platform' ),
};

export default function SettingsTab( {
  campaign,
  formState,
  setFormState,
  onSave,
  isSaving,
  saveSuccess,
  saveError,
} ) {
  const [ campaigns, setCampaigns ] = useState( [] );
  const symbol = getCurrencySymbol();

  const updateField = ( field, value ) => {
    setFormState( ( prev ) => ( { ...prev, [ field ]: value } ) );
  };

  const handleKeyDown = ( e ) => {
    if ( e.key === 'Enter' ) {
      e.preventDefault();
      onSave();
    }
  };

  // Fetch other campaigns for the redirect dropdown.
  const fetchCampaigns = useCallback( async () => {
    try {
      const items = await apiFetch( {
        path: '/mission/v1/campaigns?per_page=100&status=active',
      } );
      setCampaigns( items.filter( ( c ) => c.id !== campaign.id ) );
    } catch {
      // Silently fail.
    }
  }, [ campaign.id ] );

  useEffect( () => {
    fetchCampaigns();
  }, [ fetchCampaigns ] );

  const showEndSection =
    formState.close_on_goal || Boolean( formState.end_date );

  return (
    <div className="mission-tab-panel">
      { /* Campaign Details */ }
      <div
        className="mission-card mission-settings-section"
        style={ { marginBottom: 32 } }
      >
        <h3 className="mission-settings-section__title">
          { __( 'Campaign Details', 'missionwp-donation-platform' ) }
        </h3>

        { /* Goal Type */ }
        <div className="mission-field-group" style={ { marginBottom: 20 } }>
          <span className="mission-field-label">
            { __( 'Goal Type', 'missionwp-donation-platform' ) }
          </span>
          <GoalTypePicker
            value={ formState.goal_type || 'amount' }
            onChange={ ( val ) => updateField( 'goal_type', val ) }
          />
        </div>

        { /* Goal */ }
        <div
          className="mission-field-group"
          style={ { marginBottom: 20, maxWidth: '50%' } }
        >
          <label className="mission-field-label" htmlFor="campaign-goal">
            { GOAL_LABELS[ formState.goal_type || 'amount' ] }
          </label>
          { ( formState.goal_type || 'amount' ) === 'amount' ? (
            <div className="mission-field-currency">
              <span className="mission-field-currency__symbol">{ symbol }</span>
              <input
                id="campaign-goal"
                type="text"
                className="mission-field-input"
                value={ formState.goal_amount }
                onChange={ ( e ) =>
                  updateField( 'goal_amount', e.target.value )
                }
                onKeyDown={ handleKeyDown }
              />
            </div>
          ) : (
            <input
              id="campaign-goal"
              type="number"
              min="0"
              step="1"
              className="mission-field-input"
              value={ formState.goal_amount }
              onChange={ ( e ) => updateField( 'goal_amount', e.target.value ) }
              onKeyDown={ handleKeyDown }
            />
          ) }
          <span className="mission-field-hint">
            { __( 'Leave blank for no goal', 'missionwp-donation-platform' ) }
          </span>
        </div>

        { /* Close on goal */ }
        <ToggleRow
          checked={ formState.close_on_goal }
          onChange={ ( val ) => updateField( 'close_on_goal', val ) }
          label={ __(
            'End campaign early when goal is reached',
            'missionwp-donation-platform'
          ) }
          style={ { marginBottom: 20 } }
        />

        { /* Dates */ }
        <div className="mission-settings-fields">
          <div className="mission-field-group">
            <label className="mission-field-label" htmlFor="campaign-start">
              { __( 'Start Date', 'missionwp-donation-platform' ) }
            </label>
            <input
              id="campaign-start"
              type="date"
              className="mission-field-input"
              value={ formState.start_date }
              onChange={ ( e ) => updateField( 'start_date', e.target.value ) }
            />
          </div>
          <div className="mission-field-group">
            <label className="mission-field-label" htmlFor="campaign-end">
              { __( 'End Date', 'missionwp-donation-platform' ) }
            </label>
            <input
              id="campaign-end"
              type="date"
              className="mission-field-input"
              value={ formState.end_date }
              onChange={ ( e ) => updateField( 'end_date', e.target.value ) }
            />
            <span className="mission-field-hint">
              { formState.close_on_goal
                ? __(
                    'Campaign will also end when the goal is reached',
                    'missionwp-donation-platform'
                  )
                : __(
                    'Leave blank for an ongoing campaign',
                    'missionwp-donation-platform'
                  ) }
            </span>
          </div>
        </div>

        { /* When Campaign Ends */ }
        { showEndSection && (
          <>
            <h3
              className="mission-settings-section__title"
              style={ { marginTop: 24 } }
            >
              { __( 'When Campaign Ends', 'missionwp-donation-platform' ) }
            </h3>

            <ToggleRow
              checked={ formState.stop_donations_on_end }
              onChange={ ( val ) =>
                updateField( 'stop_donations_on_end', val )
              }
              label={ __(
                'Stop accepting donations',
                'missionwp-donation-platform'
              ) }
              hint={ __(
                'Donation forms for this campaign will be disabled',
                'missionwp-donation-platform'
              ) }
              style={ { marginBottom: 16 } }
            />

            { formState.has_campaign_page && (
              <ToggleRow
                checked={ formState.show_ended_message }
                onChange={ ( val ) => updateField( 'show_ended_message', val ) }
                label={ __(
                  'Show "campaign ended" message',
                  'missionwp-donation-platform'
                ) }
                hint={ __(
                  'Replace the campaign page with a message and link to other active campaigns',
                  'missionwp-donation-platform'
                ) }
                style={ { marginBottom: 16 } }
              />
            ) }

            { formState.show_in_listings && (
              <ToggleRow
                checked={ formState.remove_from_listings_on_end }
                onChange={ ( val ) =>
                  updateField( 'remove_from_listings_on_end', val )
                }
                label={ __(
                  'Remove from campaign listings',
                  'missionwp-donation-platform'
                ) }
                hint={ __(
                  'Hide this campaign from the public campaigns page',
                  'missionwp-donation-platform'
                ) }
              />
            ) }

            { /* Recurring Donations */ }
            <div style={ { marginTop: 24 } }>
              <h3 className="mission-settings-section__title">
                { __( 'Recurring Donations', 'missionwp-donation-platform' ) }
              </h3>
              <div className="mission-radio-group">
                { [
                  {
                    value: 'keep',
                    label: __(
                      'Keep recurring donations active',
                      'missionwp-donation-platform'
                    ),
                    hint: __(
                      'Existing recurring donations will continue unchanged',
                      'missionwp-donation-platform'
                    ),
                  },
                  {
                    value: 'cancel',
                    label: __(
                      'Cancel recurring donations',
                      'missionwp-donation-platform'
                    ),
                    hint: __(
                      'Active subscriptions will be canceled and donors notified',
                      'missionwp-donation-platform'
                    ),
                  },
                  {
                    value: 'redirect',
                    label: __(
                      'Redirect funds to another campaign',
                      'missionwp-donation-platform'
                    ),
                    hint: __(
                      'Future recurring donations will be designated to another campaign',
                      'missionwp-donation-platform'
                    ),
                  },
                ].map( ( option ) => (
                  // eslint-disable-next-line jsx-a11y/label-has-associated-control
                  <label
                    key={ option.value }
                    className={ `mission-radio-option${
                      formState.recurring_end_behavior === option.value
                        ? ' is-selected'
                        : ''
                    }` }
                  >
                    <input
                      type="radio"
                      name="recurring-end"
                      value={ option.value }
                      checked={
                        formState.recurring_end_behavior === option.value
                      }
                      onChange={ () =>
                        updateField( 'recurring_end_behavior', option.value )
                      }
                    />
                    <span className="mission-radio-option__dot" />
                    <div>
                      <div className="mission-radio-option__label">
                        { option.label }
                      </div>
                      <div className="mission-radio-option__hint">
                        { option.hint }
                      </div>
                    </div>
                  </label>
                ) ) }
              </div>

              { formState.recurring_end_behavior === 'redirect' && (
                <div
                  className="mission-field-group"
                  style={ { marginTop: 12, maxWidth: '50%' } }
                >
                  <label
                    className="mission-field-label"
                    htmlFor="recurring-redirect"
                  >
                    { __( 'Redirect funds to', 'missionwp-donation-platform' ) }
                  </label>
                  <select
                    id="recurring-redirect"
                    className="mission-field-select"
                    value={ formState.recurring_redirect_campaign || '' }
                    onChange={ ( e ) =>
                      updateField(
                        'recurring_redirect_campaign',
                        e.target.value
                      )
                    }
                  >
                    <option value="" disabled>
                      { __(
                        'Select a campaign…',
                        'missionwp-donation-platform'
                      ) }
                    </option>
                    { campaigns.map( ( c ) => (
                      <option key={ c.id } value={ c.id }>
                        { c.title }
                      </option>
                    ) ) }
                  </select>
                </div>
              ) }
            </div>
          </>
        ) }
      </div>

      { /* Danger Zone */ }
      <DangerZone campaignId={ campaign.id } campaignTitle={ campaign.title } />

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
            ? __( 'Settings Saved', 'missionwp-donation-platform' )
            : __( 'Save Settings', 'missionwp-donation-platform' ) }
        </Button>
      </div>
    </div>
  );
}
