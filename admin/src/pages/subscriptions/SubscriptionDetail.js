import { useState, useEffect, useCallback } from '@wordpress/element';
import { formatDateTime } from '@shared/date';
import {
  Button,
  Modal,
  Spinner,
  __experimentalHStack as HStack,
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import Toast from '../../components/Toast';
import StatusBadge from '../../components/StatusBadge';
import ActionsDropdown from '../../components/ActionsDropdown';
import { MenuIcon } from '../shared/DetailComponents';
import TransactionDonorCard from '../transactions/TransactionDonorCard';
import SubscriptionDetailsCard from './SubscriptionDetailsCard';
import SubscriptionActivityCard from './SubscriptionActivityCard';
import NotesCard from '../../components/NotesCard';
import {
  FREQUENCY_SUFFIXES,
  SUBSCRIPTION_STATUS,
  TRANSACTION_STATUS,
} from '../../constants';

const ResumeIcon = () => (
  <MenuIcon>
    <polygon points="4.5,3 11,7 4.5,11" />
  </MenuIcon>
);

const PauseIcon = () => (
  <MenuIcon>
    <rect x="3" y="3" width="3" height="8" rx="0.5" />
    <rect x="8" y="3" width="3" height="8" rx="0.5" />
  </MenuIcon>
);

const CancelIcon = () => (
  <MenuIcon>
    <circle cx="7" cy="7" r="6" />
    <path d="M9 5L5 9M5 5l4 4" />
  </MenuIcon>
);

export default function SubscriptionDetail( { id } ) {
  const [ subscription, setSubscription ] = useState( null );
  const [ campaigns, setCampaigns ] = useState( [] );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ error, setError ] = useState( null );
  const [ toast, setToast ] = useState( null );
  const [ toastKey, setToastKey ] = useState( 0 );
  const [ showCancelModal, setShowCancelModal ] = useState( false );
  const [ isCancelling, setIsCancelling ] = useState( false );
  const [ showPauseModal, setShowPauseModal ] = useState( false );
  const [ isPausing, setIsPausing ] = useState( false );
  const clearToast = useCallback( () => setToast( null ), [] );

  const adminUrl = window.missiondpAdmin?.adminUrl || '';
  const subscriptionsUrl = `${ adminUrl }admin.php?page=mission-donation-platform-subscriptions`;

  useEffect( () => {
    setIsLoading( true );
    Promise.all( [
      apiFetch( {
        path: `/mission-donation-platform/v1/subscriptions/${ id }`,
      } ),
      apiFetch( {
        path: '/mission-donation-platform/v1/campaigns?per_page=100&orderby=title&order=ASC',
      } ),
    ] )
      .then( ( [ sub, camps ] ) => {
        setSubscription( sub );
        setCampaigns( camps );
      } )
      .catch( ( err ) => {
        setError(
          err.message ||
            __( 'Failed to load subscription.', 'mission-donation-platform' )
        );
      } )
      .finally( () => setIsLoading( false ) );
  }, [ id ] );

  const handleCancel = async () => {
    setIsCancelling( true );
    try {
      await apiFetch( {
        path: `/mission-donation-platform/v1/subscriptions/${ id }/cancel`,
        method: 'POST',
      } );
      setSubscription( ( prev ) => ( {
        ...prev,
        status: SUBSCRIPTION_STATUS.CANCELLED,
        date_cancelled: new Date().toISOString(),
      } ) );
      setShowCancelModal( false );
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'success',
        message: __( 'Subscription cancelled.', 'mission-donation-platform' ),
      } );
    } catch ( err ) {
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'error',
        message:
          err.message ||
          __( 'Failed to cancel subscription.', 'mission-donation-platform' ),
      } );
    } finally {
      setIsCancelling( false );
    }
  };

  const handlePause = async () => {
    setIsPausing( true );
    try {
      await apiFetch( {
        path: `/mission-donation-platform/v1/subscriptions/${ id }/pause`,
        method: 'POST',
      } );
      setSubscription( ( prev ) => ( {
        ...prev,
        status: SUBSCRIPTION_STATUS.PAUSED,
      } ) );
      setShowPauseModal( false );
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'success',
        message: __( 'Subscription paused.', 'mission-donation-platform' ),
      } );
    } catch ( err ) {
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'error',
        message:
          err.message ||
          __( 'Failed to pause subscription.', 'mission-donation-platform' ),
      } );
    } finally {
      setIsPausing( false );
    }
  };

  const handleResume = async () => {
    try {
      const result = await apiFetch( {
        path: `/mission-donation-platform/v1/subscriptions/${ id }/resume`,
        method: 'POST',
      } );
      setSubscription( ( prev ) => ( {
        ...prev,
        status: result.status || SUBSCRIPTION_STATUS.ACTIVE,
      } ) );
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'success',
        message: __( 'Subscription resumed.', 'mission-donation-platform' ),
      } );
    } catch ( err ) {
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'error',
        message:
          err.message ||
          __( 'Failed to resume subscription.', 'mission-donation-platform' ),
      } );
    }
  };

  const handleStatusChange = ( newStatus ) => {
    apiFetch( {
      path: `/mission-donation-platform/v1/subscriptions/${ id }`,
      method: 'PATCH',
      data: { status: newStatus },
    } )
      .then( () => {
        setSubscription( ( prev ) => ( { ...prev, status: newStatus } ) );
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'success',
          message: __( 'Subscription updated.', 'mission-donation-platform' ),
        } );
      } )
      .catch( ( err ) => {
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'error',
          message:
            err.message ||
            __( 'Failed to update subscription.', 'mission-donation-platform' ),
        } );
      } );
  };

  const handleCampaignChange = ( campaignObj ) => {
    const campaignId = campaignObj ? campaignObj.id : null;

    apiFetch( {
      path: `/mission-donation-platform/v1/subscriptions/${ id }`,
      method: 'PATCH',
      data: { campaign_id: campaignId },
    } )
      .then( ( updated ) => {
        setSubscription( ( prev ) => ( {
          ...prev,
          campaign: updated.campaign,
        } ) );
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'success',
          message: __( 'Subscription updated.', 'mission-donation-platform' ),
        } );
      } )
      .catch( ( err ) => {
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'error',
          message:
            err.message ||
            __( 'Failed to update subscription.', 'mission-donation-platform' ),
        } );
      } );
  };

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

  if ( error || ! subscription ) {
    return (
      <div className="mission-admin-page">
        <VStack spacing={ 4 }>
          <a href={ subscriptionsUrl } className="mission-back-link">
            &larr;{ ' ' }
            { __( 'Back to Subscriptions', 'mission-donation-platform' ) }
          </a>
          <Text>
            { error ||
              __( 'Subscription not found.', 'mission-donation-platform' ) }
          </Text>
        </VStack>
      </div>
    );
  }

  const s = subscription;
  const hasActions = [
    SUBSCRIPTION_STATUS.ACTIVE,
    SUBSCRIPTION_STATUS.PAST_DUE,
    SUBSCRIPTION_STATUS.PENDING,
    SUBSCRIPTION_STATUS.PAUSED,
  ].includes( s.status );
  const paymentCount = ( s.transactions || [] ).filter(
    ( t ) => t.status === TRANSACTION_STATUS.COMPLETED
  ).length;
  const freqSuffix = FREQUENCY_SUFFIXES[ s.frequency ] || '';

  return (
    <div className="mission-admin-page">
      <Toast key={ toastKey } notice={ toast } onDone={ clearToast } />
      <VStack spacing={ 6 }>
        { /* Breadcrumb + Actions */ }
        <HStack justify="space-between" alignment="center">
          <a href={ subscriptionsUrl } className="mission-back-link">
            &larr;{ ' ' }
            { __( 'Back to Subscriptions', 'mission-donation-platform' ) }
          </a>
          { hasActions && (
            <ActionsDropdown
              items={ [
                s.status === SUBSCRIPTION_STATUS.PAUSED
                  ? {
                      label: __(
                        'Resume Subscription',
                        'mission-donation-platform'
                      ),
                      icon: <ResumeIcon />,
                      onClick: handleResume,
                    }
                  : {
                      label: __(
                        'Pause Subscription',
                        'mission-donation-platform'
                      ),
                      icon: <PauseIcon />,
                      onClick: () => setShowPauseModal( true ),
                    },
                { divider: true },
                {
                  label: __(
                    'Cancel Subscription',
                    'mission-donation-platform'
                  ),
                  icon: <CancelIcon />,
                  isDanger: true,
                  onClick: () => setShowCancelModal( true ),
                },
              ] }
            />
          ) }
        </HStack>

        { /* Two-column grid */ }
        <div className="mission-detail-grid">
          <VStack spacing={ 4 }>
            { /* Header */ }
            <div
              className={ `mission-txn-header is-${ s.status || 'pending' }` }
            >
              <div className="mission-txn-header__amount-row">
                <h1 className="mission-txn-header__amount">
                  { formatAmount( s.amount, s.currency ) }
                  <span className="mission-txn-header__freq">
                    { freqSuffix }
                  </span>
                </h1>
                <StatusBadge status={ s.status } />
              </div>
              <p className="mission-txn-header__meta">
                <span style={ { fontFamily: 'monospace' } }>#sub-{ s.id }</span>
                { ' \u00B7 ' }
                { formatDateTime( s.date_created ) }
                { paymentCount > 0 && (
                  <>
                    { ' \u00B7 ' }
                    { paymentCount }{ ' ' }
                    { paymentCount === 1
                      ? __( 'payment', 'mission-donation-platform' )
                      : __( 'payments', 'mission-donation-platform' ) }
                  </>
                ) }
              </p>
            </div>
            <SubscriptionDetailsCard
              subscription={ s }
              campaigns={ campaigns }
              onStatusChange={ handleStatusChange }
              onCampaignChange={ handleCampaignChange }
            />
          </VStack>
          <VStack spacing={ 4 } className="mission-txn-sidebar">
            <TransactionDonorCard donor={ s.donor } />
            <SubscriptionActivityCard subscription={ s } />
            <NotesCard
              objectType="subscriptions"
              objectId={ s.id }
              title={ __( 'Internal Notes', 'mission-donation-platform' ) }
              hint={ __(
                'Only visible to your team.',
                'mission-donation-platform'
              ) }
            />
          </VStack>
        </div>
      </VStack>

      { showPauseModal && (
        <Modal
          title={ __( 'Pause Subscription', 'mission-donation-platform' ) }
          onRequestClose={ () => setShowPauseModal( false ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { __(
                'Pausing will stop future renewal charges until the subscription is resumed. The donor will not be charged during this time.',
                'mission-donation-platform'
              ) }
            </Text>
            <HStack justify="flex-end" spacing={ 3 }>
              <Button
                variant="tertiary"
                onClick={ () => setShowPauseModal( false ) }
                __next40pxDefaultSize
              >
                { __( 'Keep Active', 'mission-donation-platform' ) }
              </Button>
              <Button
                variant="primary"
                isBusy={ isPausing }
                disabled={ isPausing }
                onClick={ handlePause }
                style={ {
                  backgroundColor: '#2FA36B',
                  borderColor: '#2FA36B',
                } }
                __next40pxDefaultSize
              >
                { __( 'Pause Subscription', 'mission-donation-platform' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }

      { showCancelModal && (
        <Modal
          title={ __( 'Cancel Subscription', 'mission-donation-platform' ) }
          onRequestClose={ () => setShowCancelModal( false ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { __(
                'Are you sure you want to cancel this subscription? This will also cancel the subscription on Stripe.',
                'mission-donation-platform'
              ) }
            </Text>
            <HStack justify="flex-end" spacing={ 3 }>
              <Button
                variant="tertiary"
                onClick={ () => setShowCancelModal( false ) }
                __next40pxDefaultSize
              >
                { __( 'Keep Subscription', 'mission-donation-platform' ) }
              </Button>
              <Button
                variant="primary"
                isDestructive
                isBusy={ isCancelling }
                disabled={ isCancelling }
                onClick={ handleCancel }
                __next40pxDefaultSize
              >
                { __( 'Cancel Subscription', 'mission-donation-platform' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }
    </div>
  );
}
