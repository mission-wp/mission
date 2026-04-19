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
import DonorAvatar from '../../components/DonorAvatar';
import DonationHistoryTable from './DonationHistoryTable';
import DonorSubscriptionsCard from './DonorSubscriptionsCard';
import DonorDetailsCard from './DonorDetailsCard';
import NotesCard from '../../components/NotesCard';
import EditDonorDrawer from './EditDonorDrawer';

const BRAND_COLOR = '#2FA36B';

function Badge( { children, style } ) {
  return (
    <span
      style={ {
        padding: '2px 10px',
        borderRadius: '20px',
        fontSize: '11px',
        fontWeight: 500,
        ...style,
      } }
    >
      { children }
    </span>
  );
}

export default function DonorDetail( { id } ) {
  const [ donor, setDonor ] = useState( null );
  const [ transactions, setTransactions ] = useState( [] );
  const [ subscriptions, setSubscriptions ] = useState( [] );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ error, setError ] = useState( null );
  const [ showDrawer, setShowDrawer ] = useState( false );
  const [ focusField, setFocusField ] = useState( null );

  const adminUrl = window.missionAdmin?.adminUrl || '';
  const donorsUrl = `${ adminUrl }admin.php?page=mission-donors`;

  const hasLoaded = useRef( false );

  const fetchData = useCallback( async () => {
    if ( ! hasLoaded.current ) {
      setIsLoading( true );
    }
    try {
      const [ donorData, txnData, subData ] = await Promise.all( [
        apiFetch( { path: `/mission/v1/donors/${ id }` } ),
        apiFetch( {
          path: `/mission/v1/transactions?donor_id=${ id }&per_page=100`,
        } ),
        apiFetch( {
          path: `/mission/v1/subscriptions?donor_id=${ id }&per_page=100`,
        } ),
      ] );
      setDonor( donorData );
      setTransactions( txnData );
      setSubscriptions( subData );
    } catch ( err ) {
      setError(
        err.message ||
          __( 'Failed to load donor.', 'missionwp-donation-platform' )
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
            { __( 'Back to Donors', 'missionwp-donation-platform' ) }
          </a>
          <Text>
            { error || __( 'Donor not found.', 'missionwp-donation-platform' ) }
          </Text>
        </VStack>
      </div>
    );
  }

  const fullName =
    [ donor.first_name, donor.last_name ].filter( Boolean ).join( ' ' ) ||
    __( 'Anonymous', 'missionwp-donation-platform' );

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
            &larr; { __( 'Back to Donors', 'missionwp-donation-platform' ) }
          </a>
          <Button
            variant="secondary"
            onClick={ () => {
              setFocusField( null );
              setShowDrawer( true );
            } }
            __next40pxDefaultSize
          >
            { __( 'Edit Donor', 'missionwp-donation-platform' ) }
          </Button>
        </HStack>

        { /* Profile card */ }
        <div className="mission-donor-profile">
          <div className="mission-donor-profile__main">
            <DonorAvatar
              firstName={ donor.first_name }
              lastName={ donor.last_name }
              gravatarHash={ donor.gravatar_hash }
              size="xl"
            />
            <div className="mission-donor-profile__info">
              <h1 className="mission-donor-profile__name">{ fullName }</h1>
              <p className="mission-donor-profile__email">{ donor.email }</p>
              <div className="mission-donor-profile__tags">
                { donor.is_recurring && (
                  <Badge style={ { background: '#e2f4eb', color: '#2fa36b' } }>
                    { __( 'Recurring', 'missionwp-donation-platform' ) }
                  </Badge>
                ) }
                { donor.is_top_donor && (
                  <Badge style={ { background: '#fef3cd', color: '#856404' } }>
                    { __( 'Top Donor', 'missionwp-donation-platform' ) }
                  </Badge>
                ) }
                { donor.since_label && (
                  <Badge style={ { background: '#f0ede8', color: '#6b6b7b' } }>
                    { __( 'Since', 'missionwp-donation-platform' ) }{ ' ' }
                    { donor.since_label }
                  </Badge>
                ) }
              </div>
            </div>
          </div>
          <div className="mission-donor-profile__stats">
            <div className="mission-donor-profile__stat">
              <span className="mission-donor-profile__stat-value">
                { formatAmount( donor.total_donated ) }
              </span>
              <span className="mission-donor-profile__stat-label">
                { __( 'Lifetime given', 'missionwp-donation-platform' ) }
              </span>
            </div>
            <div className="mission-donor-profile__stat">
              <span className="mission-donor-profile__stat-value">
                { donor.transaction_count }
              </span>
              <span className="mission-donor-profile__stat-label">
                { __( 'Donations', 'missionwp-donation-platform' ) }
              </span>
            </div>
            <div className="mission-donor-profile__stat">
              <span className="mission-donor-profile__stat-value">
                { formatAmount( avgDonation ) }
              </span>
              <span className="mission-donor-profile__stat-label">
                { __( 'Avg. donation', 'missionwp-donation-platform' ) }
              </span>
            </div>
          </div>
        </div>

        { /* Two-column grid */ }
        <div className="mission-donor-detail-grid">
          <VStack spacing={ 4 }>
            <DonationHistoryTable transactions={ transactions } />
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
              title={ __( 'Internal Notes', 'missionwp-donation-platform' ) }
              hint={ __(
                'Only visible to your organization.',
                'missionwp-donation-platform'
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
