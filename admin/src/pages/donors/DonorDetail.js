import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
  Button,
  Spinner,
  __experimentalHStack as HStack,
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { BRAND_COLOR } from '@shared/color';
import DonorAvatar from '../../components/DonorAvatar';
import ProfileHeader from '../../components/ProfileHeader';
import TransactionsTableCard from '../../components/TransactionsTableCard';
import DonorSubscriptionsCard from './DonorSubscriptionsCard';
import DonorDetailsCard from './DonorDetailsCard';
import NotesCard from '../../components/NotesCard';
import EditDonorDrawer from './EditDonorDrawer';

export default function DonorDetail( { id } ) {
  const [ donor, setDonor ] = useState( null );
  const [ transactions, setTransactions ] = useState( [] );
  const [ subscriptions, setSubscriptions ] = useState( [] );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ error, setError ] = useState( null );
  const [ showDrawer, setShowDrawer ] = useState( false );
  const [ focusField, setFocusField ] = useState( null );

  const adminUrl = window.missiondpAdmin?.adminUrl || '';
  const donorsUrl = `${ adminUrl }admin.php?page=mission-donation-platform-donors`;

  const hasLoaded = useRef( false );

  const fetchData = useCallback( async () => {
    if ( ! hasLoaded.current ) {
      setIsLoading( true );
    }
    try {
      const [ donorData, txnData, subData ] = await Promise.all( [
        apiFetch( { path: `/mission-donation-platform/v1/donors/${ id }` } ),
        apiFetch( {
          path: `/mission-donation-platform/v1/transactions?donor_id=${ id }&per_page=100`,
        } ),
        apiFetch( {
          path: `/mission-donation-platform/v1/subscriptions?donor_id=${ id }&per_page=100`,
        } ),
      ] );
      setDonor( donorData );
      setTransactions( txnData );
      setSubscriptions( subData );
    } catch ( err ) {
      setError(
        err.message ||
          __( 'Failed to load donor.', 'mission-donation-platform' )
      );
    } finally {
      setIsLoading( false );
      hasLoaded.current = true;
    }
  }, [ id ] );

  useEffect( () => {
    fetchData();
  }, [ fetchData ] );

  if ( isLoading ) {
    return (
      <div className="mission-admin-page">
        <VStack
          spacing={ 6 }
          alignment="center"
          style={ { padding: '48px 0' } }
        >
          <Spinner />
        </VStack>
      </div>
    );
  }

  if ( error || ! donor ) {
    return (
      <div className="mission-admin-page">
        <VStack spacing={ 4 }>
          <a
            href={ donorsUrl }
            style={ { color: BRAND_COLOR, textDecoration: 'none' } }
          >
            { __( 'Back to Donors', 'mission-donation-platform' ) }
          </a>
          <Text>
            { error || __( 'Donor not found.', 'mission-donation-platform' ) }
          </Text>
        </VStack>
      </div>
    );
  }

  const fullName =
    [ donor.first_name, donor.last_name ].filter( Boolean ).join( ' ' ) ||
    __( 'Anonymous', 'mission-donation-platform' );

  const avgDonation =
    donor.transaction_count > 0
      ? Math.round( donor.total_donated / donor.transaction_count )
      : 0;

  return (
    <div className="mission-admin-page">
      <VStack spacing={ 6 }>
        { /* Breadcrumb + Edit */ }
        <HStack justify="space-between" alignment="center">
          <a href={ donorsUrl } className="mission-back-link">
            &larr; { __( 'Back to Donors', 'mission-donation-platform' ) }
          </a>
          <Button
            variant="secondary"
            onClick={ () => {
              setFocusField( null );
              setShowDrawer( true );
            } }
            __next40pxDefaultSize
          >
            { __( 'Edit Donor', 'mission-donation-platform' ) }
          </Button>
        </HStack>

        { /* Profile card */ }
        <ProfileHeader
          avatar={
            <DonorAvatar
              firstName={ donor.first_name }
              lastName={ donor.last_name }
              gravatarHash={ donor.gravatar_hash }
              size="xl"
            />
          }
          name={ fullName }
          subtitle={ donor.email }
          badges={
            <>
              { donor.is_recurring && (
                <span className="mission-chip mission-chip--success">
                  { __( 'Recurring', 'mission-donation-platform' ) }
                </span>
              ) }
              { donor.is_top_donor && (
                <span className="mission-chip mission-chip--warning">
                  { __( 'Top Donor', 'mission-donation-platform' ) }
                </span>
              ) }
              { donor.since_label && (
                <span className="mission-chip">
                  { __( 'Since', 'mission-donation-platform' ) }{ ' ' }
                  { donor.since_label }
                </span>
              ) }
            </>
          }
          stats={ [
            {
              value: formatAmount( donor.total_donated ),
              label: __( 'Lifetime given', 'mission-donation-platform' ),
            },
            {
              value: donor.transaction_count,
              label: __( 'Donations', 'mission-donation-platform' ),
            },
            {
              value: formatAmount( avgDonation ),
              label: __( 'Avg. donation', 'mission-donation-platform' ),
            },
          ] }
        />

        { /* Two-column grid */ }
        <div className="mission-detail-grid">
          <VStack spacing={ 4 }>
            <TransactionsTableCard
              title={ __( 'Donation History', 'mission-donation-platform' ) }
              transactions={ transactions }
              columns={ [
                'id',
                'date',
                'amount',
                'campaign',
                'type',
                'status',
              ] }
            />
            { subscriptions.length > 0 && (
              <DonorSubscriptionsCard subscriptions={ subscriptions } />
            ) }
          </VStack>
          <VStack spacing={ 4 }>
            <DonorDetailsCard
              donor={ donor }
              onEdit={ ( field ) => {
                setFocusField( field );
                setShowDrawer( true );
              } }
            />
            <NotesCard
              objectType="donors"
              objectId={ id }
              title={ __( 'Internal Notes', 'mission-donation-platform' ) }
              hint={ __(
                'Only visible to your organization.',
                'mission-donation-platform'
              ) }
            />
          </VStack>
        </div>
      </VStack>

      <EditDonorDrawer
        isOpen={ showDrawer }
        onClose={ () => setShowDrawer( false ) }
        donor={ donor }
        focusField={ focusField }
        onSaved={ () => {
          setShowDrawer( false );
          fetchData();
        } }
      />
    </div>
  );
}
