import { useState, useEffect, useRef, useMemo } from '@wordpress/element';
import {
  Button,
  TextControl,
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

function RequiredLabel( { text } ) {
  return (
    <>
      { text }
      <span style={ { color: ERROR_COLOR, marginLeft: '4px' } }>*</span>
    </>
  );
}

function isValidEmail( email ) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
}

function donorToForm( donor ) {
  return {
    email: donor?.email || '',
    firstName: donor?.first_name || '',
    lastName: donor?.last_name || '',
    phone: donor?.phone || '',
    address1: donor?.address_1 || '',
    address2: donor?.address_2 || '',
    city: donor?.city || '',
    state: donor?.state || '',
    zip: donor?.zip || '',
    country: donor?.country || 'US',
  };
}

const FIELD_IDS = {
  email: 'mission-edit-donor-email',
  firstName: 'mission-edit-donor-first-name',
  lastName: 'mission-edit-donor-last-name',
  phone: 'mission-edit-donor-phone',
  address1: 'mission-edit-donor-address1',
  address2: 'mission-edit-donor-address2',
  city: 'mission-edit-donor-city',
  state: 'mission-edit-donor-state',
  zip: 'mission-edit-donor-zip',
  country: 'mission-edit-donor-country',
};

export default function EditDonorDrawer( {
  isOpen,
  onClose,
  donor,
  focusField,
  onSaved,
} ) {
  const bodyRef = useRef( null );
  const [ form, setForm ] = useState( () => donorToForm( donor ) );
  const [ isSaving, setIsSaving ] = useState( false );
  const [ error, setError ] = useState( '' );
  const [ fieldErrors, setFieldErrors ] = useState( {} );

  useEffect( () => {
    if ( donor ) {
      setForm( donorToForm( donor ) );
    }
  }, [ donor ] );

  useEffect( () => {
    if ( isOpen && focusField && FIELD_IDS[ focusField ] ) {
      const timer = setTimeout( () => {
        const el = document.getElementById( FIELD_IDS[ focusField ] );
        el?.focus();
      }, 350 );
      return () => clearTimeout( timer );
    }
  }, [ isOpen, focusField ] );

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

    setIsSaving( true );

    try {
      await apiFetch( {
        path: `/mission-donation-platform/v1/donors/${ donor.id }`,
        method: 'PUT',
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
        },
      } );
      setError( '' );
      setFieldErrors( {} );
      onSaved();
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

  function resetAndClose() {
    setForm( donorToForm( donor ) );
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
        { __( 'Save Changes', 'mission-donation-platform' ) }
      </Button>
    </HStack>
  );

  return (
    <Drawer
      title={ __( 'Edit Donor', 'mission-donation-platform' ) }
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
            id={ FIELD_IDS.email }
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
              id={ FIELD_IDS.firstName }
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
              id={ FIELD_IDS.lastName }
              label={ __( 'Last Name', 'mission-donation-platform' ) }
              value={ form.lastName }
              onChange={ setField( 'lastName' ) }
              __next40pxDefaultSize
              __nextHasNoMarginBottom
            />
          </div>
        </HStack>
        <TextControl
          id={ FIELD_IDS.phone }
          label={ __( 'Phone', 'mission-donation-platform' ) }
          type="tel"
          value={ form.phone }
          onChange={ setField( 'phone' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        <TextControl
          id={ FIELD_IDS.address1 }
          label={ __( 'Address Line 1', 'mission-donation-platform' ) }
          value={ form.address1 }
          onChange={ setField( 'address1' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        <TextControl
          id={ FIELD_IDS.address2 }
          label={ __( 'Address Line 2', 'mission-donation-platform' ) }
          value={ form.address2 }
          onChange={ setField( 'address2' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        <HStack spacing={ 4 } alignment="top">
          <div style={ { flex: 1 } }>
            <TextControl
              id={ FIELD_IDS.city }
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
                id={ FIELD_IDS.state }
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
                id={ FIELD_IDS.state }
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
              id={ FIELD_IDS.zip }
              label={ regionConfig.postalLabel }
              value={ form.zip }
              onChange={ setField( 'zip' ) }
              __next40pxDefaultSize
              __nextHasNoMarginBottom
            />
          </div>
          <div style={ { flex: 1 } }>
            <SelectControl
              id={ FIELD_IDS.country }
              label={ __( 'Country', 'mission-donation-platform' ) }
              value={ form.country }
              options={ COUNTRIES }
              onChange={ handleCountryChange }
              __next40pxDefaultSize
              __nextHasNoMarginBottom
            />
          </div>
        </HStack>
      </VStack>
    </Drawer>
  );
}
