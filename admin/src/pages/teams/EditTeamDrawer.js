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

function teamToForm( team, currency ) {
  return {
    name: team?.name || '',
    goal: team?.goal ? String( minorToMajor( team.goal, currency ) ) : '',
    access: team?.access || 'public',
    status: team?.status || 'active',
    captainId: team?.captain_id ? String( team.captain_id ) : '',
  };
}

/**
 * Drawer for editing a team's name, goal, visibility, status, and captain.
 *
 * @param {Object}   props         Component props.
 * @param {boolean}  props.isOpen  Whether the drawer is open.
 * @param {Function} props.onClose Close handler.
 * @param {Object}   props.team    Team detail object.
 * @param {Array}    props.members Member fundraisers (for the captain select).
 * @param {Function} props.onSaved Called with the updated team.
 * @return {JSX.Element} The drawer.
 */
export default function EditTeamDrawer( {
  isOpen,
  onClose,
  team,
  members,
  onSaved,
} ) {
  const currency = window.missiondpAdmin?.currency || 'USD';
  const [ form, setForm ] = useState( () => teamToForm( team, currency ) );
  const [ isSaving, setIsSaving ] = useState( false );
  const [ error, setError ] = useState( '' );

  useEffect( () => {
    if ( isOpen ) {
      setForm( teamToForm( team, currency ) );
      setError( '' );
    }
  }, [ isOpen, team, currency ] );

  const setField = ( field ) => ( value ) =>
    setForm( ( prev ) => ( { ...prev, [ field ]: value } ) );

  const save = async () => {
    if ( ! form.name.trim() ) {
      setError( __( 'A team name is required.', 'mission-donation-platform' ) );
      return;
    }

    setIsSaving( true );
    setError( '' );
    try {
      const updated = await apiFetch( {
        path: `/mission-donation-platform/v1/teams/${ team.id }`,
        method: 'PUT',
        data: {
          name: form.name,
          goal: form.goal
            ? majorToMinor( parseFloat( form.goal ), currency )
            : 0,
          access: form.access,
          status: form.status,
          captain_id: form.captainId ? Number( form.captainId ) : null,
        },
      } );
      onSaved( updated );
    } catch ( err ) {
      setError(
        err.message ||
          __( 'Failed to update the team.', 'mission-donation-platform' )
      );
    } finally {
      setIsSaving( false );
    }
  };

  return (
    <Drawer
      isOpen={ isOpen }
      onClose={ onClose }
      title={ __( 'Edit Team', 'mission-donation-platform' ) }
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
          label={ __( 'Team name', 'mission-donation-platform' ) }
          value={ form.name }
          onChange={ setField( 'name' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        <TextControl
          label={ __( 'Team goal', 'mission-donation-platform' ) }
          type="number"
          min="0"
          value={ form.goal }
          onChange={ setField( 'goal' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        <SelectControl
          label={ __( 'Visibility', 'mission-donation-platform' ) }
          value={ form.access }
          options={ [
            {
              value: 'public',
              label: __( 'Public', 'mission-donation-platform' ),
            },
            {
              value: 'private',
              label: __( 'Private', 'mission-donation-platform' ),
            },
          ] }
          onChange={ setField( 'access' ) }
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
          label={ __( 'Captain', 'mission-donation-platform' ) }
          value={ form.captainId }
          options={ [
            {
              value: '',
              label: __( 'No captain', 'mission-donation-platform' ),
            },
            ...members.map( ( member ) => ( {
              value: String( member.id ),
              label: member.donor_name,
            } ) ),
          ] }
          onChange={ setField( 'captainId' ) }
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
      </VStack>
    </Drawer>
  );
}
