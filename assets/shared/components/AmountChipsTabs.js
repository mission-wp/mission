import { useState, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { FREQUENCIES } from './FrequencyChips';
import { getCurrencySymbol } from '@shared/currency';

export default function AmountChipsTabs( {
  selectedFrequencies,
  amountsByFrequency,
  defaultAmounts,
  onAmountsChange,
  onDefaultChange,
  currencySymbol,
  showDescriptions,
  amountDescriptions,
  onDescriptionChange,
} ) {
  const enabledFreqs = FREQUENCIES.filter( ( f ) =>
    selectedFrequencies.includes( f.id )
  );
  const [ activeTab, setActiveTab ] = useState(
    enabledFreqs[ 0 ]?.id || 'one_time'
  );
  const [ copyMenuOpen, setCopyMenuOpen ] = useState( null );
  const inputRefs = useRef( {} );

  const symbol = currencySymbol || getCurrencySymbol();

  // Switch to first enabled tab if current is disabled.
  const isActiveEnabled = selectedFrequencies.includes( activeTab );
  const effectiveTab = isActiveEnabled
    ? activeTab
    : enabledFreqs[ 0 ]?.id || 'one_time';

  const handleAdd = ( freqId, value ) => {
    const num = parseFloat( value );
    if ( isNaN( num ) || num <= 0 ) {
      return;
    }
    const current = amountsByFrequency[ freqId ] || [];
    if ( current.includes( num ) ) {
      return;
    }
    const next = [ ...current, num ].sort( ( a, b ) => a - b );
    onAmountsChange( freqId, next );
    if ( inputRefs.current[ freqId ] ) {
      inputRefs.current[ freqId ].value = '';
    }
  };

  const handleRemove = ( freqId, amount ) => {
    const current = amountsByFrequency[ freqId ] || [];
    onAmountsChange(
      freqId,
      current.filter( ( a ) => a !== amount )
    );
    // If removed amount was default, clear it.
    if ( defaultAmounts[ freqId ] === amount ) {
      onDefaultChange( freqId, null );
    }
    // Clean up description for removed amount.
    if (
      onDescriptionChange &&
      amountDescriptions?.[ freqId ]?.[ String( amount ) ]
    ) {
      onDescriptionChange( freqId, amount, '' );
    }
  };

  const handleSetDefault = ( freqId, amount ) => {
    onDefaultChange(
      freqId,
      defaultAmounts[ freqId ] === amount ? null : amount
    );
  };

  const handleCopyFrom = ( targetFreq, sourceFreq ) => {
    const source = amountsByFrequency[ sourceFreq ] || [];
    onAmountsChange( targetFreq, [ ...source ] );
    setCopyMenuOpen( null );
  };

  const renderCopyFrom = ( freq, otherFreqs ) => {
    if ( otherFreqs.length === 0 ) {
      return null;
    }

    return (
      <div className="mission-amounts-tabbed__copy-from">
        <button
          type="button"
          className="mission-amounts-tabbed__copy-btn"
          onClick={ () =>
            setCopyMenuOpen( copyMenuOpen === freq.id ? null : freq.id )
          }
        >
          <svg
            width="12"
            height="12"
            viewBox="0 0 12 12"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <rect x="4" y="4" width="7" height="7" rx="1" />
            <path d="M8 4V2.5A1.5 1.5 0 0 0 6.5 1h-4A1.5 1.5 0 0 0 1 2.5v4A1.5 1.5 0 0 0 2.5 8H4" />
          </svg>
          { __( 'Copy from…', 'missionwp-donation-platform' ) }
        </button>
        { copyMenuOpen === freq.id && (
          <div className="mission-amounts-tabbed__copy-menu">
            { otherFreqs.map( ( other ) => (
              <button
                key={ other.id }
                type="button"
                className="mission-amounts-tabbed__copy-menu-item"
                onClick={ () => handleCopyFrom( freq.id, other.id ) }
              >
                { other.label }
              </button>
            ) ) }
          </div>
        ) }
      </div>
    );
  };

  return (
    <div className="mission-amounts-tabbed">
      <div className="mission-amounts-tabbed__tabs">
        { FREQUENCIES.map( ( freq ) => {
          const isEnabled = selectedFrequencies.includes( freq.id );
          const isActive = freq.id === effectiveTab;
          let cls = 'mission-amounts-tabbed__tab';
          if ( isActive ) {
            cls += ' is-active';
          }
          if ( ! isEnabled ) {
            cls += ' is-disabled';
          }
          return (
            <button
              key={ freq.id }
              type="button"
              className={ cls }
              onClick={ () => isEnabled && setActiveTab( freq.id ) }
              disabled={ ! isEnabled }
            >
              { freq.label }
            </button>
          );
        } ) }
      </div>

      { FREQUENCIES.map( ( freq ) => {
        if ( freq.id !== effectiveTab ) {
          return null;
        }
        const amounts = amountsByFrequency[ freq.id ] || [];
        const defaultAmount = defaultAmounts[ freq.id ];
        const otherFreqs = enabledFreqs.filter( ( f ) => f.id !== freq.id );
        const descriptions = amountDescriptions?.[ freq.id ] || {};

        if ( showDescriptions ) {
          return (
            <div key={ freq.id } className="mission-amounts-tabbed__panel">
              <div className="mission-amount-rows">
                { amounts.map( ( amount ) => (
                  <div key={ amount } className="mission-amount-row">
                    <span
                      className={ `mission-amount-row__value${
                        defaultAmount === amount ? ' is-default' : ''
                      }` }
                      onClick={ () => handleSetDefault( freq.id, amount ) }
                      role="button"
                      tabIndex={ 0 }
                      onKeyDown={ ( e ) => {
                        if ( e.key === 'Enter' || e.key === ' ' ) {
                          handleSetDefault( freq.id, amount );
                        }
                      } }
                    >
                      { symbol }
                      { amount.toLocaleString() }
                    </span>
                    <input
                      type="text"
                      className="mission-amount-row__desc"
                      placeholder={ __(
                        'Add a description (optional)',
                        'missionwp-donation-platform'
                      ) }
                      value={ descriptions[ String( amount ) ] || '' }
                      onChange={ ( e ) =>
                        onDescriptionChange( freq.id, amount, e.target.value )
                      }
                    />
                    <button
                      type="button"
                      className="mission-amount-row__remove"
                      onClick={ () => handleRemove( freq.id, amount ) }
                    >
                      &times;
                    </button>
                  </div>
                ) ) }
                <div className="mission-amount-row mission-amount-row--add">
                  <span className="mission-amount-row__value mission-amount-row__value--input">
                    { symbol }
                    <input
                      type="text"
                      placeholder={ __( 'Add', 'missionwp-donation-platform' ) }
                      ref={ ( el ) => {
                        inputRefs.current[ freq.id ] = el;
                      } }
                      onKeyDown={ ( e ) => {
                        if ( e.key === 'Enter' ) {
                          e.preventDefault();
                          handleAdd( freq.id, e.target.value );
                        }
                      } }
                    />
                  </span>
                </div>
              </div>

              { renderCopyFrom( freq, otherFreqs ) }
            </div>
          );
        }

        return (
          <div key={ freq.id } className="mission-amounts-tabbed__panel">
            <div className="mission-amount-chips">
              { amounts.map( ( amount ) => (
                <span
                  key={ amount }
                  className={ `mission-amount-chip${
                    defaultAmount === amount ? ' is-default' : ''
                  }` }
                  onClick={ () => handleSetDefault( freq.id, amount ) }
                  role="button"
                  tabIndex={ 0 }
                  onKeyDown={ ( e ) => {
                    if ( e.key === 'Enter' || e.key === ' ' ) {
                      handleSetDefault( freq.id, amount );
                    }
                  } }
                >
                  { symbol }
                  { amount.toLocaleString() }
                  <button
                    type="button"
                    className="mission-amount-chip__remove"
                    onClick={ ( e ) => {
                      e.stopPropagation();
                      handleRemove( freq.id, amount );
                    } }
                  >
                    &times;
                  </button>
                </span>
              ) ) }
              <div className="mission-amount-chip-input-wrap">
                <span className="mission-amount-chip-input-wrap__prefix">
                  { symbol }
                </span>
                <input
                  type="text"
                  placeholder={ __( 'Add', 'missionwp-donation-platform' ) }
                  ref={ ( el ) => {
                    inputRefs.current[ freq.id ] = el;
                  } }
                  onKeyDown={ ( e ) => {
                    if ( e.key === 'Enter' ) {
                      e.preventDefault();
                      handleAdd( freq.id, e.target.value );
                    }
                  } }
                />
              </div>
            </div>

            { renderCopyFrom( freq, otherFreqs ) }
          </div>
        );
      } ) }
    </div>
  );
}
