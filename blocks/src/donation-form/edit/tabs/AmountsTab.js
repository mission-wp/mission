import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import FrequencyChips from '@shared/components/FrequencyChips';
import AmountChipsTabs from '@shared/components/AmountChipsTabs';
import ToggleRow from '@shared/components/ToggleRow';

/**
 * Check whether any frequency has a non-empty description.
 *
 * @param {Object} descs amountDescriptions object.
 * @return {boolean} True if any description exists.
 */
function hasAnyDescription( descs ) {
  if ( ! descs || typeof descs !== 'object' ) {
    return false;
  }
  return Object.values( descs ).some(
    ( freqDescs ) =>
      freqDescs &&
      typeof freqDescs === 'object' &&
      Object.values( freqDescs ).some( ( d ) => d && d.trim() )
  );
}

export default function AmountsTab( {
  localState,
  updateField,
  currencySymbol,
} ) {
  const showDefaultFrequency = localState.frequencies.length > 1;
  const [ showDescriptions, setShowDescriptions ] = useState( () =>
    hasAnyDescription( localState.amountDescriptions )
  );

  return (
    <div className="mission-amounts-tab">
      { /* Donation Frequencies */ }
      <div className="mission-field-group" style={ { marginBottom: 24 } }>
        <span className="mission-field-label">
          { __( 'Donation Frequencies', 'mission-donation-platform' ) }
        </span>
        <FrequencyChips
          selected={ localState.frequencies }
          onChange={ ( freqs ) => updateField( 'frequencies', freqs ) }
        />
        <span className="mission-field-hint">
          { __(
            'Select which frequencies are available on donation forms',
            'mission-donation-platform'
          ) }
        </span>
      </div>

      { /* Suggested Amounts */ }
      <div className="mission-field-group" style={ { marginBottom: 24 } }>
        <div
          style={ {
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
          } }
        >
          <span className="mission-field-label" style={ { marginBottom: 0 } }>
            { __( 'Suggested Amounts', 'mission-donation-platform' ) }
          </span>
          <div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
            <span style={ { fontSize: '11.7px', color: '#9b9ba8' } }>
              { __( 'With descriptions', 'mission-donation-platform' ) }
            </span>
            { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
            <label
              className="mission-toggle-switch mission-toggle-switch--sm"
              title={ __( 'With descriptions', 'mission-donation-platform' ) }
            >
              <input
                type="checkbox"
                checked={ showDescriptions }
                onChange={ ( e ) => setShowDescriptions( e.target.checked ) }
              />
              <span className="mission-toggle-switch__slider" />
            </label>
          </div>
        </div>
        <AmountChipsTabs
          selectedFrequencies={ localState.frequencies }
          amountsByFrequency={ localState.amountsByFrequency }
          defaultAmounts={ localState.defaultAmounts }
          currencySymbol={ currencySymbol }
          showDescriptions={ showDescriptions }
          amountDescriptions={ localState.amountDescriptions || {} }
          onAmountsChange={ ( freqId, amounts ) => {
            updateField( 'amountsByFrequency', {
              ...localState.amountsByFrequency,
              [ freqId ]: amounts,
            } );
          } }
          onDefaultChange={ ( freqId, amount ) => {
            updateField( 'defaultAmounts', {
              ...localState.defaultAmounts,
              [ freqId ]: amount,
            } );
          } }
          onDescriptionChange={ ( freqId, amount, text ) => {
            const existing = localState.amountDescriptions || {};
            const freqDescs = existing[ freqId ] || {};
            updateField( 'amountDescriptions', {
              ...existing,
              [ freqId ]: {
                ...freqDescs,
                [ String( amount ) ]: text,
              },
            } );
          } }
        />
        <span className="mission-field-hint">
          { __(
            'Press Enter to add an amount. Click an amount to set it as the pre-selected default.',
            'mission-donation-platform'
          ) }
        </span>
      </div>

      { /* Default Frequency */ }
      { showDefaultFrequency && (
        <div
          className="mission-field-group"
          style={ { marginBottom: 24, maxWidth: '50%' } }
        >
          <label className="mission-field-label" htmlFor="default-frequency">
            { __( 'Default Frequency', 'mission-donation-platform' ) }
          </label>
          <select
            id="default-frequency"
            className="mission-field-select"
            value={ localState.defaultFrequency }
            onChange={ ( e ) =>
              updateField( 'defaultFrequency', e.target.value )
            }
          >
            { localState.frequencies.map( ( f ) => {
              const labels = {
                one_time: __( 'One-time', 'mission-donation-platform' ),
                weekly: __( 'Weekly', 'mission-donation-platform' ),
                monthly: __( 'Monthly', 'mission-donation-platform' ),
                quarterly: __( 'Quarterly', 'mission-donation-platform' ),
                annually: __( 'Annually', 'mission-donation-platform' ),
              };
              return (
                <option key={ f } value={ f }>
                  { labels[ f ] || f }
                </option>
              );
            } ) }
          </select>
          <span className="mission-field-hint">
            { __(
              'Pre-selected frequency when the form loads',
              'mission-donation-platform'
            ) }
          </span>
        </div>
      ) }

      { /* Allow Custom Amount */ }
      <ToggleRow
        checked={ localState.customAmount }
        onChange={ ( val ) => updateField( 'customAmount', val ) }
        label={ __( 'Allow custom amount', 'mission-donation-platform' ) }
        hint={ __(
          'Let donors enter their own amount',
          'mission-donation-platform'
        ) }
        style={ { marginBottom: 20 } }
      />

      { /* Minimum Donation */ }
      <div className="mission-field-group" style={ { maxWidth: '50%' } }>
        <label className="mission-field-label" htmlFor="min-donation">
          { __( 'Minimum Donation', 'mission-donation-platform' ) }
        </label>
        <div className="mission-field-currency">
          <span className="mission-field-currency__symbol">
            { currencySymbol }
          </span>
          <input
            id="min-donation"
            type="text"
            className="mission-field-input"
            value={ localState.minimumAmount }
            onChange={ ( e ) => updateField( 'minimumAmount', e.target.value ) }
          />
        </div>
      </div>
    </div>
  );
}
