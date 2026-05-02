import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
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
import TransactionDonorCard from '../transactions/TransactionDonorCard';
import SubscriptionDetailsCard from './SubscriptionDetailsCard';
import SubscriptionActivityCard from './SubscriptionActivityCard';
import NotesCard from '../../components/NotesCard';

const FREQUENCY_SUFFIXES = {
  weekly: __( '/wk', 'mission-donation-platform' ),
  monthly: __( '/mo', 'mission-donation-platform' ),
  quarterly: __( '/qtr', 'mission-donation-platform' ),
  annually: __( '/yr', 'mission-donation-platform' ),
};

function StatusBadge( { status } ) {
  const label = status
    ? status.replace( '_', ' ' ).replace( /\b\w/g, ( c ) => c.toUpperCase() )
    : __( 'Pending', 'mission-donation-platform' );
  return (
    <span className={ `mission-status-badge is-${ status || 'pending' }` }>
      { label }
    </span>
  );
}

function ActionsDropdown( { status, onPause, onResume, onCancel } ) {
  const [ isOpen, setIsOpen ] = useState( false );
  const ref = useRef();

  useEffect( () => {
    if ( ! isOpen ) {
      return;
    }
    const close = ( e ) => {
      if ( ref.current && ! ref.current.contains( e.target ) ) {
        setIsOpen( false );
      }
    };
    document.addEventListener( 'click', close, true );
    return () => document.removeEventListener( 'click', close, true );
  }, [ isOpen ] );

  const isPaused = status === 'paused';

  return (
    <div className="mission-dropdown" ref={ ref }>
      <button
        className="mission-dropdown__toggle"
        onClick={ () => setIsOpen( ! isOpen ) }
      >
        { __( 'Actions', 'mission-donation-platform' ) }
        <svg
          width="10"
          height="6"
          viewBox="0 0 10 6"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M1 1l4 4 4-4" />
        </svg>
      </button>
      { isOpen && (
        <div className="mission-dropdown__menu">
          { isPaused ? (
            <button
              className="mission-dropdown__item"
              onClick={ () => {
                setIsOpen( false );
                onResume();
              } }
            >
              <svg
                width="14"
                height="14"
                viewBox="0 0 14 14"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <polygon points="4.5,3 11,7 4.5,11" />
              </svg>
              { __( 'Resume Subscription', 'mission-donation-platform' ) }
            </button>
          ) : (
            <button
              className="mission-dropdown__item"
              onClick={ () => {
                setIsOpen( false );
                onPause();
              } }
            >
              <svg
                width="14"
                height="14"
                viewBox="0 0 14 14"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <rect x="3" y="3" width="3" height="8" rx="0.5" />
                <rect x="8" y="3" width="3" height="8" rx="0.5" />
              </svg>
              { __( 'Pause Subscription', 'mission-donation-platform' ) }
            </button>
          ) }
          <div className="mission-dropdown__divider" />
          <button
            className="mission-dropdown__item mission-dropdown__item--danger"
            onClick={ () => {
              setIsOpen( false );
              onCancel();
            } }
          >
            <svg
              width="14"
              height="14"
              viewBox="0 0 14 14"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <circle cx="7" cy="7" r="6" />
              <path d="M9 5L5 9M5 5l4 4" />
            </svg>
            { __( 'Cancel Subscription', 'mission-donation-platform' ) }
          </button>
        </div>
      ) }
    </div>
  );
}

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
        status: 'cancelled',
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
      setSubscription( ( prev ) => ( { ...prev, status: 'paused' } ) );
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
        status: result.status || 'active',
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
  const hasActions = [ 'active', 'past_due', 'pending', 'paused' ].includes(
    s.status
  );
  const paymentCount = ( s.transactions || [] ).filter(
    ( t ) => t.status === 'completed'
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
              status={ s.status }
              onPause={ () => setShowPauseModal( true ) }
              onResume={ handleResume }
              onCancel={ () => setShowCancelModal( true ) }
            />
          ) }
        </HStack>

        { /* Two-column grid */ }
        <div className="mission-donor-detail-grid">
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
