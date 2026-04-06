import { useState, useRef, useMemo } from '@wordpress/element';
import {
  Button,
  TextControl,
  TextareaControl,
  SelectControl,
  Notice,
  __experimentalHStack as HStack,
  __experimentalVStack as VStack,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import Drawer from '../../components/Drawer';
import { COUNTRIES, getRegionConfig } from '@shared/address';

const BRAND_COLOR = '#2FA36B';
const ERROR_COLOR = '#dc2626';

const errorStyle = {
  borderColor: ERROR_COLOR,
  boxShadow: '0 0 0 1px ' + ERROR_COLOR,
};

const errorHintStyle = {
  margin: '4px 0 0',
  fontSize: '13px',
  color: ERROR_COLOR,
};

const INITIAL_FORM = {
  email: '',
  firstName: '',
  lastName: '',
  phone: '',
  address1: '',
  address2: '',
  city: '',
  state: '',
  zip: '',
  country: 'US',
  note: '',
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

export default function AddDonorDrawer( { isOpen, onClose, onCreated } ) {
  const bodyRef = useRef( null );
  const [ form, setForm ] = useState( INITIAL_FORM );
  const [ isSaving, setIsSaving ] = useState( false );
  const [ error, setError ] = useState( '' );
  const [ fieldErrors, setFieldErrors ] = useState( {} );

  const regionConfig = useMemo(
    () => getRegionConfig( form.country ),
    [ form.country ]
  );

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

  function handleCountryChange( value ) {
    setForm( ( prev ) => ( { ...prev, country: value, state: '' } ) );
  }

  function validate() {
    const errors = {};

    if ( ! form.email ) {
      errors.email = __( 'This field is required.', 'mission' );
    } else if ( ! isValidEmail( form.email ) ) {
      errors.email = __( 'Please enter a valid email address.', 'mission' );
    }

    if ( ! form.firstName ) {
      errors.firstName = __( 'This field is required.', 'mission' );
    }

    return errors;
  }

  async function handleSubmit() {
    setError( '' );

    const errors = validate();
    setFieldErrors( errors );

    if ( Object.keys( errors ).length > 0 ) {
      setError( __( 'Please fix the errors below and try again.', 'mission' ) );
      bodyRef.current?.scrollTo( { top: 0, behavior: 'smooth' } );
      return;
    }

    setIsSaving( true );

    try {
      await apiFetch( {
        path: '/mission/v1/donors',
        method: 'POST',
        data: {
          email: form.email,
          first_name: form.firstName,
          last_name: form.lastName,
          phone: form.phone,
          address_1: form.address1,
          address_2: form.address2,
          city: form.city,
          state: form.state,
          zip: form.zip,
          country: form.country,
          note: form.note,
        },
      } );
      setForm( INITIAL_FORM );
      setError( '' );
      setFieldErrors( {} );
      onCreated();
    } catch ( err ) {
      setError( err.message || __( 'Something went wrong.', 'mission' ) );
      bodyRef.current?.scrollTo( { top: 0, behavior: 'smooth' } );
    } finally {
      setIsSaving( false );
    }
  }

  function resetAndClose() {
    setForm( INITIAL_FORM );
    setError( '' );
    setFieldErrors( {} );
    onClose();
  }

  const footer = (
    <HStack justify="flex-end" spacing={ 3 }>
      <Button
        variant="tertiary"
        onClick={ resetAndClose }
        disabled={ isSaving }
        __next40pxDefaultSize
      >
        { __( 'Cancel', 'mission' ) }
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
        { __( 'Save Donor', 'mission' ) }
      </Button>
    </HStack>
  );

  return (
    <Drawer
      title={ __( 'Add Donor', 'mission' ) }
      isOpen={ isOpen }
      onClose={ onClose }
      footer={ footer }
      bodyRef={ bodyRef }
    >
      <VStack spacing={ 4 }>
        { error && (
          <Notice status="error" isDismissible={ false }>
            { error }
          </Notice>
        ) }
        <div>
          <TextControl
            label={ <RequiredLabel text={ __( 'Email', 'mission' ) } /> }
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
              label={ <RequiredLabel text={ __( 'First Name', 'mission' ) } /> }
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
              label={ __( 'Last Name', 'mission' ) }
              value={ form.lastName }
              onChange={ setField( 'lastName' ) }
              __next40pxDefaultSize
              __nextHasNoMarginBottom
            />
          </div>
        </HStack>
        <TextControl
          label={ __( 'Phone', 'mission' ) }
          type="tel"
          value={ form.phone }
          onChange={ setField( 'phone' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        <TextControl
          label={ __( 'Address Line 1', 'mission' ) }
          value={ form.address1 }
          onChange={ setField( 'address1' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        <TextControl
          label={ __( 'Address Line 2', 'mission' ) }
          value={ form.address2 }
          onChange={ setField( 'address2' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        <HStack spacing={ 4 } alignment="top">
          <div style={ { flex: 1 } }>
            <TextControl
              label={ __( 'City', 'mission' ) }
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
                  { value: '', label: __( 'Select…', 'mission' ) },
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
          <div style={ { flex: 1 } }>
            <SelectControl
              label={ __( 'Country', 'mission' ) }
              value={ form.country }
              options={ COUNTRIES }
              onChange={ handleCountryChange }
              __next40pxDefaultSize
              __nextHasNoMarginBottom
            />
          </div>
        </HStack>
        <TextareaControl
          label={ __( 'Note', 'mission' ) }
          value={ form.note }
          onChange={ setField( 'note' ) }
          __nextHasNoMarginBottom
        />
      </VStack>
    </Drawer>
  );
}
