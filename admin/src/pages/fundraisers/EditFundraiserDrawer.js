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
    teamId: fundraiser?.team_id ? String( fundraiser.team_id ) : '',
    dedicationType: fundraiser?.dedication?.type || '',
    dedicationName: fundraiser?.dedication?.name || '',
  };
}

/**
 * Drawer for editing a fundraiser's headline, goal, status, team, and dedication.
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
  const [ teams, setTeams ] = useState( [] );
  const [ isSaving, setIsSaving ] = useState( false );
  const [ error, setError ] = useState( '' );

  useEffect( () => {
    if ( isOpen ) {
      setForm( fundraiserToForm( fundraiser, currency ) );
      setError( '' );
    }
  }, [ isOpen, fundraiser, currency ] );

  useEffect( () => {
    if ( ! fundraiser?.campaign_id ) {
      return;
    }
    apiFetch( {
      path: `/mission-donation-platform/v1/teams?campaign_id=${ fundraiser.campaign_id }&per_page=100&orderby=name&order=ASC`,
    } )
      .then( ( items ) => setTeams( items || [] ) )
      .catch( () => {} );
  }, [ fundraiser?.campaign_id ] );

  const setField = ( field ) => ( value ) =>
    setForm( ( prev ) => ( { ...prev, [ field ]: value } ) );

  // Ensure the current team is always selectable, even before the
  // team list has loaded or if it falls outside the first page.
  const teamOptions =
    fundraiser?.team_id &&
    ! teams.some( ( team ) => team.id === fundraiser.team_id )
      ? [ { id: fundraiser.team_id, name: fundraiser.team_name }, ...teams ]
      : teams;

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
          team_id: form.teamId ? parseInt( form.teamId, 10 ) : null,
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
        { teamOptions.length > 0 && (
          <SelectControl
            label={ __( 'Team', 'mission-donation-platform' ) }
            value={ form.teamId }
            options={ [
              {
                value: '',
                label: __( 'No team', 'mission-donation-platform' ),
              },
              ...teamOptions.map( ( team ) => ( {
                value: String( team.id ),
                label: team.name,
              } ) ),
            ] }
            onChange={ setField( 'teamId' ) }
            help={
              fundraiser?.is_team_captain &&
              form.teamId !== String( fundraiser?.team_id || '' )
                ? __(
                    'This fundraiser is their team’s captain. Changing their team leaves the captain role vacant.',
                    'mission-donation-platform'
                  )
                : undefined
            }
            __next40pxDefaultSize
            __nextHasNoMarginBottom
          />
        ) }
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
