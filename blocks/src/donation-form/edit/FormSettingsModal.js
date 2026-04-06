import { Button, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState, useRef, useEffect, useCallback } from '@wordpress/element';
import { minorToMajor, majorToMinor } from '@shared/currencies';
import { getCurrencySymbol } from '@shared/currency';
import AmountsTab from './tabs/AmountsTab';
import DonorInfoTab from './tabs/DonorInfoTab';
import FeesTab from './tabs/FeesTab';
import ConfirmationTab from './tabs/ConfirmationTab';
import AppearanceTab from './tabs/AppearanceTab';
import CustomFieldsTab, { findEmptyLabelField } from './tabs/CustomFieldsTab';

const TABS = [
  { id: 'amounts', label: __( 'Amounts', 'mission' ) },
  { id: 'donor', label: __( 'Donor Info', 'mission' ) },
  { id: 'fees', label: __( 'Fees', 'mission' ) },
  { id: 'custom-fields', label: __( 'Custom Fields', 'mission' ) },
  { id: 'confirmation', label: __( 'Confirmation', 'mission' ) },
  { id: 'appearance', label: __( 'Appearance', 'mission' ) },
];

/**
 * Build a unified frequencies array from the split recurring fields.
 *
 * @param {Object} data Resolved settings object.
 * @return {string[]} Array of frequency IDs.
 */
function buildFrequencyArray( data ) {
  const freqs = [];
  const recurringFreqs = data.recurringFrequencies || [];
  const recurringEnabled = data.recurringEnabled ?? recurringFreqs.length > 0;

  if ( ! recurringEnabled || recurringFreqs.length === 0 ) {
    return [ 'one_time' ];
  }

  freqs.push( 'one_time' );
  freqs.push( ...recurringFreqs );
  return freqs;
}

/**
 * Convert amounts objects from minor to major units for display.
 *
 * @param {Object} amountsByFreq Amounts keyed by frequency.
 * @param {string} currency      ISO 4217 currency code.
 * @return {Object} Converted amounts.
 */
function convertAmountsToMajor( amountsByFreq, currency ) {
  if ( ! amountsByFreq ) {
    return {};
  }
  const result = {};
  for ( const [ freq, amounts ] of Object.entries( amountsByFreq ) ) {
    result[ freq ] = amounts.map( ( a ) => minorToMajor( a, currency ) );
  }
  return result;
}

/**
 * Convert amounts objects from major to minor units for storage.
 *
 * @param {Object} amountsByFreq Amounts keyed by frequency.
 * @param {string} currency      ISO 4217 currency code.
 * @return {Object} Converted amounts.
 */
function convertAmountsToMinor( amountsByFreq, currency ) {
  if ( ! amountsByFreq ) {
    return {};
  }
  const result = {};
  for ( const [ freq, amounts ] of Object.entries( amountsByFreq ) ) {
    result[ freq ] = amounts.map( ( a ) => majorToMinor( a, currency ) );
  }
  return result;
}

/**
 * Convert defaults map from minor to major units.
 *
 * @param {Object} defaults Defaults keyed by frequency.
 * @param {string} currency ISO 4217 currency code.
 * @return {Object} Converted defaults.
 */
function convertDefaultsToMajor( defaults, currency ) {
  if ( ! defaults ) {
    return {};
  }
  const result = {};
  for ( const [ freq, amount ] of Object.entries( defaults ) ) {
    result[ freq ] =
      amount !== null && amount !== undefined
        ? minorToMajor( amount, currency )
        : null;
  }
  return result;
}

/**
 * Convert defaults map from major to minor units.
 *
 * @param {Object} defaults Defaults keyed by frequency.
 * @param {string} currency ISO 4217 currency code.
 * @return {Object} Converted defaults.
 */
function convertDefaultsToMinor( defaults, currency ) {
  if ( ! defaults ) {
    return {};
  }
  const result = {};
  for ( const [ freq, amount ] of Object.entries( defaults ) ) {
    result[ freq ] =
      amount !== null && amount !== undefined
        ? majorToMinor( amount, currency )
        : null;
  }
  return result;
}

/**
 * Convert description keys from minor to major units.
 *
 * @param {Object} descs    Description object keyed by frequency, then by stringified minor amount.
 * @param {string} currency ISO 4217 currency code.
 * @return {Object} Converted descriptions with major-unit string keys.
 */
function convertDescriptionKeysToMajor( descs, currency ) {
  if ( ! descs || typeof descs !== 'object' ) {
    return {};
  }
  const result = {};
  for ( const [ freq, freqDescs ] of Object.entries( descs ) ) {
    if ( ! freqDescs || typeof freqDescs !== 'object' ) {
      continue;
    }
    result[ freq ] = {};
    for ( const [ minorKey, text ] of Object.entries( freqDescs ) ) {
      const majorKey = String( minorToMajor( Number( minorKey ), currency ) );
      result[ freq ][ majorKey ] = text;
    }
  }
  return result;
}

/**
 * Convert description keys from major to minor units.
 *
 * @param {Object} descs    Description object keyed by frequency, then by stringified major amount.
 * @param {string} currency ISO 4217 currency code.
 * @return {Object} Converted descriptions with minor-unit string keys.
 */
function convertDescriptionKeysToMinor( descs, currency ) {
  if ( ! descs || typeof descs !== 'object' ) {
    return {};
  }
  const result = {};
  for ( const [ freq, freqDescs ] of Object.entries( descs ) ) {
    if ( ! freqDescs || typeof freqDescs !== 'object' ) {
      continue;
    }
    result[ freq ] = {};
    for ( const [ majorKey, text ] of Object.entries( freqDescs ) ) {
      if ( ! text || ! text.trim() ) {
        continue;
      }
      const minorKey = String( majorToMinor( Number( majorKey ), currency ) );
      result[ freq ][ minorKey ] = text;
    }
  }
  return result;
}

/**
 * Build local state from resolved settings.
 *
 * @param {Object} resolved Resolved settings (block attrs merged over campaign defaults).
 * @param {string} currency ISO 4217 currency code.
 * @return {Object} Local state for the modal.
 */
function buildLocalState( resolved, currency ) {
  return {
    frequencies: buildFrequencyArray( resolved ),
    amountsByFrequency: convertAmountsToMajor(
      resolved.amountsByFrequency || {},
      currency
    ),
    defaultAmounts: convertDefaultsToMajor(
      resolved.defaultAmounts || {},
      currency
    ),
    amountDescriptions: convertDescriptionKeysToMajor(
      resolved.amountDescriptions || {},
      currency
    ),
    customAmount: resolved.customAmount ?? true,
    minimumAmount:
      resolved.minimumAmount !== null && resolved.minimumAmount !== undefined
        ? minorToMajor( resolved.minimumAmount, currency )
        : '',
    defaultFrequency: resolved.recurringDefault || 'one_time',
    feeMode:
      resolved.feeMode ||
      ( resolved.feeRecovery !== false ? 'optional' : 'none' ),
    tipEnabled: resolved.tipEnabled ?? true,
    anonymousEnabled: resolved.anonymousEnabled ?? false,
    commentsEnabled: resolved.commentsEnabled ?? false,
    phoneRequired: resolved.phoneRequired ?? false,
    collectAddress: resolved.collectAddress ?? true,
    tributeEnabled: resolved.tributeEnabled ?? false,
    confirmationType: resolved.confirmationType || 'message',
    confirmationRedirectUrl: resolved.confirmationRedirectUrl || '',
    primaryColor: resolved.primaryColor || '',
    continueButtonText: resolved.continueButtonText || '',
    donateButtonText: resolved.donateButtonText || '',
    chooseGiftHeading: resolved.chooseGiftHeading || '',
    summaryHeading: resolved.summaryHeading || '',
    additionalInfoHeading: resolved.additionalInfoHeading || '',
    customFields: resolved.customFields || [],
  };
}

/**
 * Merge block attributes over plugin defaults to produce resolved settings.
 *
 * @param {Object} defaults Plugin defaults from the REST API.
 * @param {Object} attrs    Block attributes.
 * @return {Object} Resolved settings.
 */
function resolveSettings( defaults, attrs ) {
  const resolved = { ...defaults };
  const keys = [
    'amountsByFrequency',
    'defaultAmounts',
    'customAmount',
    'minimumAmount',
    'recurringEnabled',
    'recurringFrequencies',
    'recurringDefault',
    'amountDescriptions',
    'feeMode',
    'tipEnabled',
    'anonymousEnabled',
    'commentsEnabled',
    'phoneRequired',
    'collectAddress',
    'tributeEnabled',
    'confirmationType',
    'confirmationRedirectUrl',
    'primaryColor',
    'continueButtonText',
    'donateButtonText',
    'chooseGiftHeading',
    'summaryHeading',
    'additionalInfoHeading',
    'customFields',
  ];
  for ( const key of keys ) {
    if ( attrs[ key ] !== undefined ) {
      resolved[ key ] = attrs[ key ];
    }
  }
  return resolved;
}

/**
 * Derive currency symbol from an ISO 4217 currency code.
 *
 * @param {string} cur ISO 4217 currency code.
 * @return {string} Currency symbol.
 */
function deriveCurrencySymbol( cur ) {
  try {
    const formatted = new Intl.NumberFormat( undefined, {
      style: 'currency',
      currency: cur,
      maximumFractionDigits: 0,
    } ).format( 0 );
    return formatted.replace( /[\d.,\s]/g, '' ).trim() || '$';
  } catch {
    return getCurrencySymbol();
  }
}

/**
 * Hardcoded fallback defaults when the API is unavailable.
 */
const FALLBACK_DEFAULTS = {
  amountsByFrequency: { one_time: [ 2500, 5000, 10000, 25000 ] },
  defaultAmounts: {},
  customAmount: true,
  minimumAmount: 500,
  recurringEnabled: false,
  recurringFrequencies: [],
  recurringDefault: 'one_time',
  amountDescriptions: {},
  feeMode: 'optional',
  tipEnabled: true,
  anonymousEnabled: false,
  commentsEnabled: false,
  phoneRequired: false,
  collectAddress: true,
  tributeEnabled: false,
  confirmationType: 'message',
  confirmationRedirectUrl: '',
  primaryColor: '',
  continueButtonText: '',
  donateButtonText: '',
  chooseGiftHeading: '',
  summaryHeading: '',
  additionalInfoHeading: '',
  customFields: [],
  currency: 'USD',
};

export default function FormSettingsModal( {
  attributes,
  setAttributes,
  pluginDefaults,
  onClose,
} ) {
  const defaults = pluginDefaults || FALLBACK_DEFAULTS;
  const cur = defaults.currency || 'USD';

  const [ activeTab, setActiveTab ] = useState( 'amounts' );
  const [ localState, setLocalState ] = useState( () =>
    buildLocalState( resolveSettings( defaults, attributes ), cur )
  );
  const [ currency ] = useState( cur );
  const [ currencySymbol ] = useState( () => deriveCurrencySymbol( cur ) );
  const navRef = useRef( null );
  const [ indicator, setIndicator ] = useState( { top: 0, height: 0 } );

  const updateIndicator = useCallback( () => {
    if ( ! navRef.current ) {
      return;
    }
    const active = navRef.current.querySelector( '[data-active="true"]' );
    if ( ! active ) {
      return;
    }
    setIndicator( {
      top: active.offsetTop,
      height: active.offsetHeight,
    } );
  }, [] );

  useEffect( () => {
    updateIndicator();
  }, [ activeTab, updateIndicator ] );

  const updateField = useCallback( ( field, value ) => {
    setLocalState( ( prev ) => {
      const next = { ...prev, [ field ]: value };
      // Clear label validation error when custom fields are edited.
      if ( field === 'customFields' && prev._customFieldLabelError ) {
        next._customFieldLabelError = false;
      }
      return next;
    } );
  }, [] );

  const handleSave = () => {
    // Validate custom fields — all must have labels.
    const badField = findEmptyLabelField( localState.customFields );
    if ( badField ) {
      setActiveTab( 'custom-fields' );
      setLocalState( ( prev ) => ( {
        ...prev,
        _customFieldLabelError: true,
        _expandedFieldId: badField.id,
      } ) );
      return;
    }

    const hasRecurring = localState.frequencies.some(
      ( f ) => f !== 'one_time'
    );
    const minAmount = Math.max(
      1,
      parseFloat( localState.minimumAmount ) || 1
    );

    setAttributes( {
      amountsByFrequency: convertAmountsToMinor(
        localState.amountsByFrequency,
        currency
      ),
      defaultAmounts: convertDefaultsToMinor(
        localState.defaultAmounts,
        currency
      ),
      amountDescriptions: convertDescriptionKeysToMinor(
        localState.amountDescriptions,
        currency
      ),
      customAmount: localState.customAmount,
      minimumAmount: majorToMinor( minAmount, currency ),
      recurringEnabled: hasRecurring,
      recurringFrequencies: localState.frequencies.filter(
        ( f ) => f !== 'one_time'
      ),
      recurringDefault: localState.defaultFrequency,
      feeRecovery: localState.feeMode !== 'none',
      feeMode: localState.feeMode,
      tipEnabled: localState.tipEnabled,
      anonymousEnabled: localState.anonymousEnabled,
      commentsEnabled: localState.commentsEnabled,
      phoneRequired: localState.phoneRequired,
      collectAddress: localState.collectAddress,
      tributeEnabled: localState.tributeEnabled,
      confirmationType: localState.confirmationType,
      confirmationRedirectUrl: localState.confirmationRedirectUrl,
      primaryColor: localState.primaryColor || undefined,
      continueButtonText: localState.continueButtonText || undefined,
      donateButtonText: localState.donateButtonText || undefined,
      chooseGiftHeading: localState.chooseGiftHeading || undefined,
      summaryHeading: localState.summaryHeading || undefined,
      additionalInfoHeading: localState.additionalInfoHeading || undefined,
      customFields: localState.customFields.length
        ? localState.customFields
        : undefined,
    } );

    onClose();
  };

  const renderTabContent = () => {
    if ( activeTab === 'amounts' ) {
      return (
        <AmountsTab
          localState={ localState }
          updateField={ updateField }
          currencySymbol={ currencySymbol }
        />
      );
    }

    if ( activeTab === 'donor' ) {
      return (
        <DonorInfoTab localState={ localState } updateField={ updateField } />
      );
    }

    if ( activeTab === 'fees' ) {
      return <FeesTab localState={ localState } updateField={ updateField } />;
    }

    if ( activeTab === 'confirmation' ) {
      return (
        <ConfirmationTab
          localState={ localState }
          updateField={ updateField }
        />
      );
    }

    if ( activeTab === 'custom-fields' ) {
      return (
        <CustomFieldsTab
          localState={ localState }
          updateField={ updateField }
        />
      );
    }

    if ( activeTab === 'appearance' ) {
      return (
        <AppearanceTab
          localState={ localState }
          updateField={ updateField }
          globalPrimaryColor={ defaults.globalPrimaryColor || '#2fa36b' }
        />
      );
    }

    return null;
  };

  return (
    <Modal
      title={ __( 'Donation Form Settings', 'mission' ) }
      onRequestClose={ onClose }
      className="mission-form-settings-modal"
      size="large"
    >
      <div className="mission-form-settings-modal__layout">
        <div className="mission-form-settings-modal__body">
          <nav className="mission-form-settings-modal__nav" ref={ navRef }>
            <span
              className="mission-form-settings-modal__indicator"
              style={ {
                transform: `translateY(${ indicator.top }px)`,
                height: `${ indicator.height }px`,
              } }
            />
            { TABS.map( ( tab ) => (
              <button
                key={ tab.id }
                type="button"
                data-active={ activeTab === tab.id }
                className={ `mission-form-settings-modal__nav-item${
                  activeTab === tab.id ? ' is-active' : ''
                }` }
                onClick={ () => setActiveTab( tab.id ) }
              >
                <span>{ tab.label }</span>
                <svg
                  className="mission-form-settings-modal__nav-arrow"
                  width="7"
                  height="12"
                  viewBox="0 0 7 12"
                  fill="none"
                  xmlns="http://www.w3.org/2000/svg"
                >
                  <path
                    d="M1 1L6 6L1 11"
                    stroke="currentColor"
                    strokeWidth="1.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </button>
            ) ) }
          </nav>
          <div className="mission-form-settings-modal__panel">
            <div className="mission-form-settings-modal__content">
              { renderTabContent() }
            </div>
          </div>
        </div>
        <div className="mission-form-settings-modal__footer">
          <Button variant="tertiary" onClick={ onClose } __next40pxDefaultSize>
            { __( 'Cancel', 'mission' ) }
          </Button>
          <Button
            variant="primary"
            onClick={ handleSave }
            style={ {
              backgroundColor: '#2fa36b',
              borderColor: '#2fa36b',
            } }
            __next40pxDefaultSize
          >
            { __( 'Save', 'mission' ) }
          </Button>
        </div>
      </div>
    </Modal>
  );
}
