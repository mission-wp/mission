import {
  useState,
  useRef,
  useCallback,
  useEffect,
  useMemo,
} from '@wordpress/element';
import {
  BaseControl,
  Button,
  TextControl,
  TextareaControl,
  ComboboxControl,
  SelectControl,
  Notice,
  __experimentalInputControl as InputControl,
  __experimentalHStack as HStack,
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import { Icon, plus, cancelCircleFilled } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { getCurrencySymbol, getCurrencyCode } from '@shared/currency';
import { majorToMinor } from '@shared/currencies';
import { COUNTRIES, getRegionConfig } from '@shared/address';
import Drawer from '../../components/Drawer';

const BRAND_COLOR = '#2FA36B';
const ERROR_COLOR = '#dc2626';
const isTestMode = !! window.missiondpAdmin?.testMode;

const errorStyle = {
  borderColor: ERROR_COLOR,
  boxShadow: '0 0 0 1px ' + ERROR_COLOR,
};

const errorHintStyle = {
  margin: '4px 0 0',
  fontSize: '13px',
  color: ERROR_COLOR,
};

function isValidEmail( email ) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
}

function RequiredLabel( { text } ) {
  return (
    <>
      { text }
      <span style={ { color: ERROR_COLOR, marginLeft: '4px' } }>*</span>
    </>
  );
}

function todayString() {
  return new Date().toISOString().slice( 0, 10 );
}

function gravatarUrl( hash, size = 40 ) {
  if ( ! hash ) {
    return `https://www.gravatar.com/avatar/?d=mp&s=${ size }`;
  }
  return `https://www.gravatar.com/avatar/${ hash }?d=mp&s=${ size }`;
}

const INITIAL_FORM = {
  email: '',
  firstName: '',
  lastName: '',
  amount: '',
  campaignId: '',
  date: todayString(),
  notes: '',
  sendReceipt: true,
  address1: '',
  address2: '',
  city: '',
  state: '',
  zip: '',
  country: window.missiondpAdmin?.orgCountry || 'US',
};

/**
 * Selected donor card — shows avatar, name, email, and a remove button.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.donor    Donor object with first_name, last_name, email, gravatar_hash.
 * @param {Function} props.onRemove Callback when the remove button is clicked.
 */
function SelectedDonorCard( { donor, onRemove } ) {
  const name = [ donor.first_name, donor.last_name ]
    .filter( Boolean )
    .join( ' ' );

  return (
    <div
      style={ {
        display: 'flex',
        alignItems: 'center',
        gap: '12px',
        padding: '12px',
        background: '#f9f9f9',
        borderRadius: '4px',
        border: '1px solid #e0e0e0',
      } }
    >
      <img
        src={ gravatarUrl( donor.gravatar_hash, 80 ) }
        alt=""
        width={ 40 }
        height={ 40 }
        style={ { borderRadius: '50%', flexShrink: 0 } }
      />
      <div style={ { flex: 1, minWidth: 0 } }>
        <div
          style={ {
            fontWeight: 500,
            fontSize: '13px',
            lineHeight: '1.4',
            overflow: 'hidden',
            textOverflow: 'ellipsis',
            whiteSpace: 'nowrap',
          } }
        >
          { name }
        </div>
        <div
          style={ {
            fontSize: '12px',
            color: '#757575',
            overflow: 'hidden',
            textOverflow: 'ellipsis',
            whiteSpace: 'nowrap',
          } }
        >
          { donor.email }
        </div>
      </div>
      <Button
        icon={ cancelCircleFilled }
        label={ __( 'Remove donor', 'mission-donation-platform' ) }
        onClick={ onRemove }
        size="small"
        style={ { color: '#757575', flexShrink: 0 } }
      />
    </div>
  );
}

export default function AddDonationDrawer( {
  isOpen,
  onClose,
  onCreated,
  campaigns,
} ) {
  const bodyRef = useRef( null );
  const searchTimer = useRef( null );
  const [ form, setForm ] = useState( { ...INITIAL_FORM } );
  const [ isSaving, setIsSaving ] = useState( false );
  const [ error, setError ] = useState( '' );
  const [ fieldErrors, setFieldErrors ] = useState( {} );

  // Donor selection state.
  const [ donorMode, setDonorMode ] = useState( 'search' ); // 'search' | 'new'
  const [ selectedDonor, setSelectedDonor ] = useState( null );
  const [ donorOptions, setDonorOptions ] = useState( [] );
  const [ donorSearch, setDonorSearch ] = useState( '' );
  // Cache the full donor objects returned from the API.
  const donorCacheRef = useRef( {} );

  // Country-aware address config.
  const regionConfig = useMemo(
    () => getRegionConfig( form.country ),
    [ form.country ]
  );

  function handleCountryChange( value ) {
    setForm( ( prev ) => ( { ...prev, country: value, state: '' } ) );
  }

  // Fetch donors as the user types in the combobox.
  const fetchDonors = useCallback( ( query ) => {
    if ( searchTimer.current ) {
      clearTimeout( searchTimer.current );
    }

    if ( ! query || query.length < 2 ) {
      setDonorOptions( [] );
      return;
    }

    searchTimer.current = setTimeout( async () => {
      try {
        const donors = await apiFetch( {
          path: `/mission-donation-platform/v1/donors?search=${ encodeURIComponent(
            query
          ) }&per_page=10`,
        } );

        const options = donors.map( ( d ) => {
          // Cache the donor data so we can retrieve it on selection.
          donorCacheRef.current[ String( d.id ) ] = d;

          const name = [ d.first_name, d.last_name ]
            .filter( Boolean )
            .join( ' ' );
          return {
            label: name ? `${ name } (${ d.email })` : d.email,
            value: String( d.id ),
          };
        } );

        setDonorOptions( options );
      } catch {
        // Silently ignore search errors.
      }
    }, 300 );
  }, [] );

  // Clean up timer on unmount.
  useEffect( () => {
    return () => {
      if ( searchTimer.current ) {
        clearTimeout( searchTimer.current );
      }
    };
  }, [] );

  function handleDonorSelect( donorId ) {
    if ( ! donorId ) {
      setSelectedDonor( null );
      return;
    }

    const donor = donorCacheRef.current[ donorId ];
    if ( ! donor ) {
      return;
    }

    setSelectedDonor( donor );
    setForm( ( prev ) => ( {
      ...prev,
      email: donor.email,
      firstName: donor.first_name,
      lastName: donor.last_name || '',
    } ) );
    setFieldErrors( ( prev ) => {
      const next = { ...prev };
      delete next.donor;
      delete next.email;
      delete next.firstName;
      return next;
    } );
    setDonorSearch( '' );
  }

  function clearDonor() {
    setSelectedDonor( null );
    setForm( ( prev ) => ( {
      ...prev,
      email: '',
      firstName: '',
      lastName: '',
    } ) );
    setDonorOptions( [] );
    setDonorSearch( '' );
  }

  function switchToNewDonor() {
    clearDonor();
    setDonorMode( 'new' );
  }

  function switchToSearch() {
    clearDonor();
    setDonorMode( 'search' );
  }

  function setField( key ) {
    return ( value ) => {
      setForm( ( prev ) => ( { ...prev, [ key ]: value } ) );
      setFieldErrors( ( prev ) => {
        if ( ! prev[ key ] ) {
          return prev;
        }
        const next = { ...prev };
        delete next[ key ];
        return next;
      } );
    };
  }

  function validate() {
    const errors = {};

    if ( donorMode === 'search' && ! selectedDonor ) {
      errors.donor = __(
        'Please select a donor.',
        'mission-donation-platform'
      );
    }

    if ( donorMode === 'new' ) {
      if ( ! form.email ) {
        errors.email = __(
          'This field is required.',
          'mission-donation-platform'
        );
      } else if ( ! isValidEmail( form.email ) ) {
        errors.email = __(
          'Please enter a valid email address.',
          'mission-donation-platform'
        );
      }

      if ( ! form.firstName ) {
        errors.firstName = __(
          'This field is required.',
          'mission-donation-platform'
        );
      }
    }

    if ( ! form.amount ) {
      errors.amount = __(
        'This field is required.',
        'mission-donation-platform'
      );
    } else {
      const cents = majorToMinor(
        parseFloat( form.amount ),
        getCurrencyCode()
      );
      if ( isNaN( cents ) || cents <= 0 ) {
        errors.amount = __(
          'Please enter a valid amount.',
          'mission-donation-platform'
        );
      }
    }

    return errors;
  }

  async function handleSubmit() {
    setError( '' );

    const errors = validate();
    setFieldErrors( errors );

    if ( Object.keys( errors ).length > 0 ) {
      setError(
        __(
          'Please fix the errors below and try again.',
          'mission-donation-platform'
        )
      );
      bodyRef.current?.scrollTo( { top: 0, behavior: 'smooth' } );
      return;
    }

    const cents = majorToMinor( parseFloat( form.amount ), getCurrencyCode() );
    setIsSaving( true );

    try {
      await apiFetch( {
        path: '/mission-donation-platform/v1/transactions',
        method: 'POST',
        data: {
          donor_email: form.email,
          donor_first_name: form.firstName,
          donor_last_name: form.lastName,
          donation_amount: cents,
          campaign_id: form.campaignId ? Number( form.campaignId ) : 0,
          frequency: 'one_time',
          date_created: form.date || undefined,
          notes: form.notes || undefined,
          send_receipt: form.sendReceipt,
          address_1: form.address1 || undefined,
          address_2: form.address2 || undefined,
          city: form.city || undefined,
          state: form.state || undefined,
          zip: form.zip || undefined,
          country: form.country || undefined,
          is_test: isTestMode,
        },
      } );
      resetForm();
      onCreated();
    } catch ( err ) {
      setError(
        err.message ||
          __( 'Something went wrong.', 'mission-donation-platform' )
      );
      bodyRef.current?.scrollTo( { top: 0, behavior: 'smooth' } );
    } finally {
      setIsSaving( false );
    }
  }

  function resetForm() {
    setForm( { ...INITIAL_FORM } );
    setError( '' );
    setFieldErrors( {} );
    setSelectedDonor( null );
    setDonorMode( 'search' );
    setDonorOptions( [] );
    setDonorSearch( '' );
    donorCacheRef.current = {};
  }

  function resetAndClose() {
    resetForm();
    onClose();
  }

  const campaignOptions = ( campaigns || [] ).map( ( c ) => ( {
    label: c.label,
    value: c.value,
  } ) );

  const footer = (
    <HStack justify="flex-end" spacing={ 3 }>
      <Button
        variant="tertiary"
        onClick={ resetAndClose }
        disabled={ isSaving }
        __next40pxDefaultSize
      >
        { __( 'Cancel', 'mission-donation-platform' ) }
      </Button>
      <Button
        variant="primary"
        onClick={ handleSubmit }
        isBusy={ isSaving }
        disabled={ isSaving }
        style={ {
          backgroundColor: BRAND_COLOR,
          borderColor: BRAND_COLOR,
        } }
        __next40pxDefaultSize
      >
        { __( 'Save Donation', 'mission-donation-platform' ) }
      </Button>
    </HStack>
  );

  return (
    <Drawer
      title={ __( 'Add Donation', 'mission-donation-platform' ) }
      isOpen={ isOpen }
      onClose={ onClose }
      footer={ footer }
      bodyRef={ bodyRef }
    >
      <VStack spacing={ 4 }>
        { isTestMode && (
          <Notice status="warning" isDismissible={ false }>
            { __(
              'Test mode is active. This donation will be recorded as a test transaction.',
              'mission-donation-platform'
            ) }
          </Notice>
        ) }
        { error && (
          <Notice status="error" isDismissible={ false }>
            { error }
          </Notice>
        ) }

        { /* Donor section */ }
        <div>
          { donorMode === 'search' && ! selectedDonor && (
            <>
              <div>
                <ComboboxControl
                  label={
                    <RequiredLabel
                      text={ __( 'Donor', 'mission-donation-platform' ) }
                    />
                  }
                  value={ null }
                  options={ donorOptions }
                  onChange={ handleDonorSelect }
                  onFilterValueChange={ ( value ) => {
                    setDonorSearch( value );
                    fetchDonors( value );
                  } }
                  placeholder={ __(
                    'Search by name or email…',
                    'mission-donation-platform'
                  ) }
                  __next40pxDefaultSize
                  __nextHasNoMarginBottom
                  style={ fieldErrors.donor ? errorStyle : undefined }
                />
                { fieldErrors.donor && (
                  <p style={ errorHintStyle }>{ fieldErrors.donor }</p>
                ) }
                { donorSearch.length >= 2 && donorOptions.length === 0 && (
                  <p
                    style={ {
                      margin: '6px 0 0',
                      fontSize: '12px',
                      color: '#757575',
                    } }
                  >
                    { __( 'No donors found.', 'mission-donation-platform' ) }
                  </p>
                ) }
              </div>
              <button
                type="button"
                onClick={ switchToNewDonor }
                style={ {
                  background: 'none',
                  border: 'none',
                  padding: '6px 0 0',
                  fontSize: '13px',
                  color: BRAND_COLOR,
                  cursor: 'pointer',
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '4px',
                } }
              >
                <Icon icon={ plus } size={ 18 } />
                { __( 'Add a new donor', 'mission-donation-platform' ) }
              </button>
            </>
          ) }

          { donorMode === 'search' && selectedDonor && (
            <div>
              <Text
                style={ {
                  display: 'block',
                  fontSize: '11px',
                  fontWeight: 500,
                  textTransform: 'uppercase',
                  letterSpacing: '0.05em',
                  marginBottom: '8px',
                } }
              >
                { __( 'Donor', 'mission-donation-platform' ) }
              </Text>
              <SelectedDonorCard
                donor={ selectedDonor }
                onRemove={ clearDonor }
              />
            </div>
          ) }

          { donorMode === 'new' && (
            <VStack spacing={ 4 }>
              <div>
                <TextControl
                  label={
                    <RequiredLabel
                      text={ __( 'Email', 'mission-donation-platform' ) }
                    />
                  }
                  type="email"
                  value={ form.email }
                  onChange={ setField( 'email' ) }
                  required
                  __next40pxDefaultSize
                  __nextHasNoMarginBottom
                  style={ fieldErrors.email ? errorStyle : undefined }
                />
                { fieldErrors.email && (
                  <p style={ errorHintStyle }>{ fieldErrors.email }</p>
                ) }
              </div>
              <HStack spacing={ 4 } alignment="top">
                <div style={ { flex: 1 } }>
                  <TextControl
                    label={
                      <RequiredLabel
                        text={ __( 'First Name', 'mission-donation-platform' ) }
                      />
                    }
                    value={ form.firstName }
                    onChange={ setField( 'firstName' ) }
                    required
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                    style={ fieldErrors.firstName ? errorStyle : undefined }
                  />
                  { fieldErrors.firstName && (
                    <p style={ errorHintStyle }>{ fieldErrors.firstName }</p>
                  ) }
                </div>
                <div style={ { flex: 1 } }>
                  <TextControl
                    label={ __( 'Last Name', 'mission-donation-platform' ) }
                    value={ form.lastName }
                    onChange={ setField( 'lastName' ) }
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                  />
                </div>
              </HStack>
              <div style={ { textAlign: 'left' } }>
                <button
                  type="button"
                  onClick={ switchToSearch }
                  style={ {
                    background: 'none',
                    border: 'none',
                    padding: 0,
                    fontSize: '13px',
                    color: BRAND_COLOR,
                    cursor: 'pointer',
                  } }
                >
                  { __(
                    'Select an existing donor instead',
                    'mission-donation-platform'
                  ) }
                </button>
              </div>
            </VStack>
          ) }
        </div>

        { /* Donation details */ }
        <HStack spacing={ 4 } alignment="top">
          <div style={ { flex: 1 } }>
            <BaseControl
              id="mission-donation-amount"
              label={
                <RequiredLabel
                  text={ __( 'Amount', 'mission-donation-platform' ) }
                />
              }
              __nextHasNoMarginBottom
            >
              <InputControl
                id="mission-donation-amount"
                type="number"
                min={ 0.01 }
                step={ 0.01 }
                value={ form.amount }
                onChange={ setField( 'amount' ) }
                prefix={
                  <Text style={ { paddingLeft: '12px', color: '#757575' } }>
                    { getCurrencySymbol() }
                  </Text>
                }
                required
                __next40pxDefaultSize
                style={ fieldErrors.amount ? errorStyle : undefined }
              />
            </BaseControl>
            { fieldErrors.amount && (
              <p style={ errorHintStyle }>{ fieldErrors.amount }</p>
            ) }
          </div>
          <div style={ { flex: 1 } }>
            <ComboboxControl
              label={ __( 'Campaign', 'mission-donation-platform' ) }
              value={ form.campaignId || null }
              options={ campaignOptions }
              onChange={ ( value ) => setField( 'campaignId' )( value || '' ) }
              __next40pxDefaultSize
              __nextHasNoMarginBottom
            />
          </div>
        </HStack>
        <HStack spacing={ 4 } alignment="top">
          <div style={ { flex: 1 } }>
            <TextControl
              label={ __( 'Date', 'mission-donation-platform' ) }
              type="date"
              value={ form.date }
              onChange={ setField( 'date' ) }
              __next40pxDefaultSize
              __nextHasNoMarginBottom
            />
          </div>
          <div style={ { flex: 1 } } />
        </HStack>
        <TextareaControl
          label={ __( 'Notes', 'mission-donation-platform' ) }
          value={ form.notes }
          onChange={ setField( 'notes' ) }
          __nextHasNoMarginBottom
        />

        { /* Billing Address */ }
        <div>
          <Text
            style={ {
              display: 'block',
              fontSize: '11px',
              fontWeight: 500,
              textTransform: 'uppercase',
              letterSpacing: '0.05em',
              color: '#757575',
              marginBottom: '12px',
            } }
          >
            { __( 'Billing Address', 'mission-donation-platform' ) }
          </Text>
          <VStack spacing={ 3 }>
            <SelectControl
              label={ __( 'Country', 'mission-donation-platform' ) }
              value={ form.country }
              options={ COUNTRIES }
              onChange={ handleCountryChange }
              __next40pxDefaultSize
              __nextHasNoMarginBottom
            />
            <TextControl
              label={ __( 'Address Line 1', 'mission-donation-platform' ) }
              value={ form.address1 }
              onChange={ setField( 'address1' ) }
              __next40pxDefaultSize
              __nextHasNoMarginBottom
            />
            <TextControl
              label={ __( 'Address Line 2', 'mission-donation-platform' ) }
              value={ form.address2 }
              onChange={ setField( 'address2' ) }
              __next40pxDefaultSize
              __nextHasNoMarginBottom
            />
            <HStack spacing={ 4 } alignment="top">
              <div style={ { flex: 1 } }>
                <TextControl
                  label={ __( 'City', 'mission-donation-platform' ) }
                  value={ form.city }
                  onChange={ setField( 'city' ) }
                  __next40pxDefaultSize
                  __nextHasNoMarginBottom
                />
              </div>
              <div style={ { flex: 1 } }>
                { regionConfig.options ? (
                  <SelectControl
                    label={ regionConfig.label }
                    value={ form.state }
                    options={ [
                      {
                        value: '',
                        label: __( 'Select…', 'mission-donation-platform' ),
                      },
                      ...regionConfig.options,
                    ] }
                    onChange={ setField( 'state' ) }
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                  />
                ) : (
                  <TextControl
                    label={ regionConfig.label }
                    value={ form.state }
                    onChange={ setField( 'state' ) }
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                  />
                ) }
              </div>
            </HStack>
            <HStack spacing={ 4 } alignment="top">
              <div style={ { flex: 1 } }>
                <TextControl
                  label={ regionConfig.postalLabel }
                  value={ form.zip }
                  onChange={ setField( 'zip' ) }
                  __next40pxDefaultSize
                  __nextHasNoMarginBottom
                />
              </div>
              <div style={ { flex: 1 } } />
            </HStack>
          </VStack>
        </div>

        { /* Send Receipt Toggle */ }
        <div
          style={ {
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            padding: '12px 0',
            borderTop: '1px solid #e0e0e0',
          } }
        >
          <input
            type="checkbox"
            id="mission-send-receipt"
            checked={ form.sendReceipt }
            onChange={ ( e ) => setField( 'sendReceipt' )( e.target.checked ) }
            style={ { margin: 0 } }
          />
          { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
          <label
            htmlFor="mission-send-receipt"
            style={ { fontSize: '13px', cursor: 'pointer' } }
          >
            { __(
              'Send donation receipt email to donor',
              'mission-donation-platform'
            ) }
          </label>
        </div>
      </VStack>
    </Drawer>
  );
}
