import { useState, useEffect } from '@wordpress/element';
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
import { minorToMajor, majorToMinor } from '@shared/currencies';
import Drawer from '../../components/Drawer';
import { P2P_STATUS_META } from '../../constants';

function fundraiserToForm( fundraiser, currency ) {
  return {
    headline: fundraiser?.headline || '',
    goal: fundraiser?.goal
      ? String( minorToMajor( fundraiser.goal, currency ) )
      : '',
    status: fundraiser?.status || 'active',
    dedicationType: fundraiser?.dedication?.type || '',
    dedicationName: fundraiser?.dedication?.name || '',
  };
}

/**
 * Drawer for editing a fundraiser's headline, goal, status, and dedication.
 *
 * @param {Object}   props            Component props.
 * @param {boolean}  props.isOpen     Whether the drawer is open.
 * @param {Function} props.onClose    Close handler.
 * @param {Object}   props.fundraiser Fundraiser detail object.
 * @param {Function} props.onSaved    Called with the updated fundraiser.
 * @return {JSX.Element} The drawer.
 */
export default function EditFundraiserDrawer( {
  isOpen,
  onClose,
  fundraiser,
  onSaved,
} ) {
  const currency = window.missiondpAdmin?.currency || 'USD';
  const [ form, setForm ] = useState( () =>
    fundraiserToForm( fundraiser, currency )
  );
  const [ isSaving, setIsSaving ] = useState( false );
  const [ error, setError ] = useState( '' );

  useEffect( () => {
    if ( isOpen ) {
      setForm( fundraiserToForm( fundraiser, currency ) );
      setError( '' );
    }
  }, [ isOpen, fundraiser, currency ] );

  const setField = ( field ) => ( value ) =>
    setForm( ( prev ) => ( { ...prev, [ field ]: value } ) );

  const save = async () => {
    setIsSaving( true );
    setError( '' );
    try {
      const updated = await apiFetch( {
        path: `/mission-donation-platform/v1/fundraisers/${ fundraiser.id }`,
        method: 'PUT',
        data: {
          headline: form.headline,
          goal: form.goal
            ? majorToMinor( parseFloat( form.goal ), currency )
            : 0,
          status: form.status,
          dedication_type: form.dedicationType,
          dedication_name: form.dedicationName,
        },
      } );
      onSaved( updated );
    } catch ( err ) {
      setError(
        err.message ||
          __( 'Failed to update the fundraiser.', 'mission-donation-platform' )
      );
    } finally {
      setIsSaving( false );
    }
  };

  return (
    <Drawer
      isOpen={ isOpen }
      onClose={ onClose }
      title={ __( 'Edit Fundraiser', 'mission-donation-platform' ) }
      footer={
        <HStack justify="flex-end" spacing={ 3 }>
          <Button variant="tertiary" onClick={ onClose } __next40pxDefaultSize>
            { __( 'Cancel', 'mission-donation-platform' ) }
          </Button>
          <Button
            variant="primary"
            isBusy={ isSaving }
            disabled={ isSaving }
            onClick={ save }
            __next40pxDefaultSize
          >
            { __( 'Save Changes', 'mission-donation-platform' ) }
          </Button>
        </HStack>
      }
    >
      <VStack spacing={ 4 }>
        { error && (
          <Notice status="error" isDismissible={ false }>
            { error }
          </Notice>
        ) }
        <TextControl
          label={ __( 'Headline', 'mission-donation-platform' ) }
          value={ form.headline }
          onChange={ setField( 'headline' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        <TextControl
          label={ __( 'Goal', 'mission-donation-platform' ) }
          type="number"
          min="0"
          value={ form.goal }
          onChange={ setField( 'goal' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        <SelectControl
          label={ __( 'Status', 'mission-donation-platform' ) }
          value={ form.status }
          options={ Object.entries( P2P_STATUS_META ).map(
            ( [ value, { label } ] ) => ( { value, label } )
          ) }
          onChange={ setField( 'status' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        <SelectControl
          label={ __( 'Dedication', 'mission-donation-platform' ) }
          value={ form.dedicationType }
          options={ [
            {
              value: '',
              label: __( 'None', 'mission-donation-platform' ),
            },
            {
              value: 'honor',
              label: __( 'In honor of', 'mission-donation-platform' ),
            },
            {
              value: 'memory',
              label: __( 'In memory of', 'mission-donation-platform' ),
            },
          ] }
          onChange={ setField( 'dedicationType' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        { form.dedicationType && (
          <TextControl
            label={ __( 'Honoree name', 'mission-donation-platform' ) }
            value={ form.dedicationName }
            onChange={ setField( 'dedicationName' ) }
            __next40pxDefaultSize
            __nextHasNoMarginBottom
          />
        ) }
      </VStack>
    </Drawer>
  );
}
