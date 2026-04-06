import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { formatDateTime } from '@shared/date';
import {
  Button,
  Card,
  CardBody,
  CardHeader,
  Modal,
  Spinner,
  __experimentalHStack as HStack,
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { minorToMajor, majorToMinor } from '@shared/currencies';
import Toast from '../../components/Toast';
import TransactionDetailsCard from './TransactionDetailsCard';
import TransactionDonorCard from './TransactionDonorCard';
import TransactionActivityCard from './TransactionActivityCard';
import NotesCard from '../../components/NotesCard';

function TransactionSubscriptionLink( { subscriptionId } ) {
  const adminUrl = window.missionAdmin?.adminUrl || '';
  return (
    <Card>
      <CardHeader size="small">
        <Text weight={ 600 }>{ __( 'Subscription', 'mission' ) }</Text>
      </CardHeader>
      <CardBody>
        <Text>
          { __(
            'This transaction is part of a recurring subscription.',
            'mission'
          ) }
        </Text>
        <div style={ { marginTop: '8px' } }>
          <a
            href={ `${ adminUrl }admin.php?page=mission-subscriptions&subscription_id=${ subscriptionId }` }
            className="mission-back-link"
          >
            { __( 'View Subscription', 'mission' ) } #{ subscriptionId } &rarr;
          </a>
        </div>
      </CardBody>
    </Card>
  );
}

function StatusBadge( { status } ) {
  const label = status
    ? status.charAt( 0 ).toUpperCase() + status.slice( 1 )
    : __( 'Pending', 'mission' );
  return (
    <span className={ `mission-status-badge is-${ status || 'pending' }` }>
      { label }
    </span>
  );
}

function ActionsDropdown( {
  onResendReceipt,
  onRefund,
  onDownloadPdf,
  onExportCsv,
  onDelete,
} ) {
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

  return (
    <div className="mission-dropdown" ref={ ref }>
      <button
        className="mission-dropdown__toggle"
        onClick={ () => setIsOpen( ! isOpen ) }
      >
        { __( 'Actions', 'mission' ) }
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
          <button
            className="mission-dropdown__item"
            onClick={ () => {
              setIsOpen( false );
              onResendReceipt();
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
              <path d="M1 3.5l6 4 6-4" />
              <rect x="1" y="2" width="12" height="10" rx="1.5" />
            </svg>
            { __( 'Resend Receipt', 'mission' ) }
          </button>
          <button
            className="mission-dropdown__item"
            onClick={ () => {
              setIsOpen( false );
              onRefund();
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
              <path d="M2 7h8M6 3l-4 4 4 4" />
            </svg>
            { __( 'Refund', 'mission' ) }
          </button>
          <button
            className="mission-dropdown__item"
            onClick={ () => {
              setIsOpen( false );
              onDownloadPdf();
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
              <path d="M7 1v9M4 7l3 3 3-3" />
              <path d="M1 11v1.5a.5.5 0 0 0 .5.5h11a.5.5 0 0 0 .5-.5V11" />
            </svg>
            { __( 'Download PDF', 'mission' ) }
          </button>
          <button
            className="mission-dropdown__item"
            onClick={ () => {
              setIsOpen( false );
              onExportCsv();
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
              <path d="M8 1H3.5A1.5 1.5 0 0 0 2 2.5v9A1.5 1.5 0 0 0 3.5 13h7a1.5 1.5 0 0 0 1.5-1.5V5L8 1z" />
              <path d="M8 1v4h4" />
            </svg>
            { __( 'Export CSV', 'mission' ) }
          </button>
          <div className="mission-dropdown__divider" />
          <button
            className="mission-dropdown__item mission-dropdown__item--danger"
            onClick={ () => {
              setIsOpen( false );
              onDelete();
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
              <path d="M2 3.5h10M4.5 3.5V2.5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v1M10 3.5l-.5 8.5a1 1 0 0 1-1 1H5.5a1 1 0 0 1-1-1L4 3.5" />
            </svg>
            { __( 'Delete Transaction', 'mission' ) }
          </button>
        </div>
      ) }
    </div>
  );
}

export default function TransactionDetail( { id } ) {
  const [ transaction, setTransaction ] = useState( null );
  const [ campaigns, setCampaigns ] = useState( [] );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ error, setError ] = useState( null );
  const [ toast, setToast ] = useState( null );
  const [ toastKey, setToastKey ] = useState( 0 );
  const [ showDeleteConfirm, setShowDeleteConfirm ] = useState( false );
  const [ isDeleting, setIsDeleting ] = useState( false );
  const [ showRefundConfirm, setShowRefundConfirm ] = useState( false );
  const [ showRefundModal, setShowRefundModal ] = useState( false );
  const [ refundAmount, setRefundAmount ] = useState( '' );
  const [ isRefunding, setIsRefunding ] = useState( false );
  const [ refundError, setRefundError ] = useState( '' );
  const clearToast = useCallback( () => setToast( null ), [] );

  const adminUrl = window.missionAdmin?.adminUrl || '';
  const transactionsUrl = `${ adminUrl }admin.php?page=mission-transactions`;

  useEffect( () => {
    setIsLoading( true );
    Promise.all( [
      apiFetch( { path: `/mission/v1/transactions/${ id }` } ),
      apiFetch( {
        path: '/mission/v1/campaigns?per_page=100&orderby=title&order=ASC',
      } ),
    ] )
      .then( ( [ txn, camps ] ) => {
        setTransaction( txn );
        setCampaigns( camps );
      } )
      .catch( ( err ) => {
        setError(
          err.message || __( 'Failed to load transaction.', 'mission' )
        );
      } )
      .finally( () => setIsLoading( false ) );
  }, [ id ] );

  const applyStatusChange = ( newStatus ) => {
    apiFetch( {
      path: `/mission/v1/transactions/${ id }`,
      method: 'PATCH',
      data: { status: newStatus },
    } )
      .then( () => {
        setTransaction( ( prev ) => ( { ...prev, status: newStatus } ) );
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'success',
          message: __( 'Transaction updated.', 'mission' ),
        } );
      } )
      .catch( ( err ) => {
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'error',
          message:
            err.message || __( 'Failed to update transaction.', 'mission' ),
        } );
      } );
  };

  const handleStatusChange = ( newStatus ) => {
    if (
      newStatus === 'refunded' &&
      transaction.payment_gateway &&
      transaction.payment_gateway !== 'manual'
    ) {
      setShowRefundConfirm( true );
      return;
    }
    applyStatusChange( newStatus );
  };

  const handleAnonymousChange = ( newValue ) => {
    const previous = transaction.is_anonymous;
    setTransaction( { ...transaction, is_anonymous: newValue } );

    apiFetch( {
      path: `/mission/v1/transactions/${ id }`,
      method: 'PATCH',
      data: { is_anonymous: newValue },
    } )
      .then( () => {
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'success',
          message: __( 'Transaction updated.', 'mission' ),
        } );
      } )
      .catch( ( err ) => {
        setTransaction( ( prev ) => ( {
          ...prev,
          is_anonymous: previous,
        } ) );
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'error',
          message:
            err.message || __( 'Failed to update transaction.', 'mission' ),
        } );
      } );
  };

  const handleCampaignChange = ( campaignObj ) => {
    const campaignId = campaignObj ? campaignObj.id : null;

    apiFetch( {
      path: `/mission/v1/transactions/${ id }`,
      method: 'PATCH',
      data: { campaign_id: campaignId },
    } )
      .then( ( updated ) => {
        setTransaction( ( prev ) => ( {
          ...prev,
          campaign: updated.campaign,
        } ) );
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'success',
          message: __( 'Transaction updated.', 'mission' ),
        } );
      } )
      .catch( ( err ) => {
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'error',
          message:
            err.message || __( 'Failed to update transaction.', 'mission' ),
        } );
      } );
  };

  const handleDelete = async () => {
    setIsDeleting( true );
    try {
      await apiFetch( {
        path: `/mission/v1/transactions/${ id }`,
        method: 'DELETE',
      } );
      window.location.href = transactionsUrl;
    } catch {
      setIsDeleting( false );
      setShowDeleteConfirm( false );
    }
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

  if ( error || ! transaction ) {
    return (
      <div className="mission-admin-page">
        <VStack spacing={ 4 }>
          <a href={ transactionsUrl } className="mission-back-link">
            &larr; { __( 'Back to Transactions', 'mission' ) }
          </a>
          <Text>{ error || __( 'Transaction not found.', 'mission' ) }</Text>
        </VStack>
      </div>
    );
  }

  return (
    <div className="mission-admin-page">
      <Toast key={ toastKey } notice={ toast } onDone={ clearToast } />
      <VStack spacing={ 6 }>
        { /* Breadcrumb + Actions */ }
        <HStack justify="space-between" alignment="center">
          <a href={ transactionsUrl } className="mission-back-link">
            &larr; { __( 'Back to Transactions', 'mission' ) }
          </a>
          <ActionsDropdown
            onResendReceipt={ async () => {
              try {
                const result = await apiFetch( {
                  path: `/mission/v1/transactions/${ id }/resend-receipt`,
                  method: 'POST',
                } );
                setToastKey( ( k ) => k + 1 );
                setToast( {
                  type: 'success',
                  message: sprintf(
                    /* translators: %s: recipient email address */
                    __( 'Receipt sent to %s', 'mission' ),
                    result.sent_to
                  ),
                } );
              } catch ( err ) {
                setToastKey( ( k ) => k + 1 );
                setToast( {
                  type: 'error',
                  message:
                    err.message || __( 'Failed to send receipt.', 'mission' ),
                } );
              }
            } }
            onRefund={ () => {
              const refundable =
                transaction.total_amount - ( transaction.amount_refunded || 0 );
              setRefundAmount(
                String( minorToMajor( refundable, transaction.currency ) )
              );
              setRefundError( '' );
              setShowRefundModal( true );
            } }
            onDownloadPdf={ () => {
              window.open(
                `${ window.missionAdmin.restUrl }transactions/${ id }/receipt-pdf?_wpnonce=${ window.missionAdmin.restNonce }`,
                '_blank'
              );
            } }
            onExportCsv={ () => {
              window.open(
                `${ window.missionAdmin.restUrl }export/download?type=transactions&id=${ id }&format=csv&_wpnonce=${ window.missionAdmin.restNonce }`,
                '_blank'
              );
            } }
            onDelete={ () => setShowDeleteConfirm( true ) }
          />
        </HStack>

        { /* Two-column grid */ }
        <div className="mission-donor-detail-grid">
          <VStack spacing={ 4 }>
            { /* Header */ }
            <div
              className={ `mission-txn-header is-${
                transaction.status || 'pending'
              }` }
            >
              <div className="mission-txn-header__amount-row">
                <h1 className="mission-txn-header__amount">
                  { formatAmount( transaction.amount, transaction.currency ) }
                </h1>
                <StatusBadge status={ transaction.status } />
              </div>
              <p className="mission-txn-header__meta">
                <span style={ { fontFamily: 'monospace' } }>
                  #{ transaction.id }
                </span>
                { ' \u00B7 ' }
                { formatDateTime( transaction.date_created ) }
              </p>
            </div>
            <TransactionDetailsCard
              transaction={ transaction }
              onStatusChange={ handleStatusChange }
              onAnonymousChange={ handleAnonymousChange }
              onCampaignChange={ handleCampaignChange }
              onTributeChange={ ( tribute ) =>
                setTransaction( ( prev ) => ( { ...prev, tribute } ) )
              }
              campaigns={ campaigns }
            />
          </VStack>
          <VStack spacing={ 4 } className="mission-txn-sidebar">
            <TransactionDonorCard donor={ transaction.donor } />
            { transaction.subscription_id && (
              <TransactionSubscriptionLink
                subscriptionId={ transaction.subscription_id }
              />
            ) }
            <TransactionActivityCard
              transaction={ transaction }
              transactionId={ transaction.id }
            />
            <NotesCard
              objectType="transactions"
              objectId={ transaction.id }
              type="donor"
              title={ __( 'Donor Notes', 'mission' ) }
              hint={ __(
                'Visible to the donor. Sent via email when added.',
                'mission'
              ) }
              confirmBeforeSave={ {
                title: __( 'Send Note to Donor?', 'mission' ),
                message: __(
                  'This note will be emailed to the donor. Are you sure you want to send it?',
                  'mission'
                ),
                confirmLabel: __( 'Send Note', 'mission' ),
              } }
            />
            <NotesCard
              objectType="transactions"
              objectId={ transaction.id }
              type="internal"
              title={ __( 'Internal Notes', 'mission' ) }
              hint={ __( 'Only visible to your team.', 'mission' ) }
            />
          </VStack>
        </div>
      </VStack>

      { showRefundModal && (
        <Modal
          title={ __( 'Refund Transaction', 'mission' ) }
          onRequestClose={ () => setShowRefundModal( false ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { sprintf(
                /* translators: %s: formatted total amount */
                __( 'Original amount: %s', 'mission' ),
                formatAmount( transaction.total_amount, transaction.currency )
              ) }
              { transaction.amount_refunded > 0 &&
                ' · ' +
                  sprintf(
                    /* translators: %s: already refunded amount */
                    __( '%s already refunded', 'mission' ),
                    formatAmount(
                      transaction.amount_refunded,
                      transaction.currency
                    )
                  ) }
            </Text>
            <div>
              <label
                htmlFor="mission-refund-amount"
                style={ {
                  display: 'block',
                  fontSize: '13px',
                  fontWeight: 500,
                  marginBottom: '6px',
                } }
              >
                { __( 'Refund amount', 'mission' ) }
              </label>
              <input
                id="mission-refund-amount"
                type="number"
                min="0.01"
                step="0.01"
                value={ refundAmount }
                onChange={ ( e ) => setRefundAmount( e.target.value ) }
                className="mission-settings-field__input"
                style={ { width: '100%' } }
              />
              { refundError && (
                <p
                  style={ {
                    color: '#b85c5c',
                    fontSize: '12px',
                    margin: '6px 0 0',
                  } }
                >
                  { refundError }
                </p>
              ) }
            </div>
            <HStack justify="flex-end">
              <Button
                variant="tertiary"
                onClick={ () => setShowRefundModal( false ) }
                __next40pxDefaultSize
              >
                { __( 'Cancel', 'mission' ) }
              </Button>
              <Button
                variant="primary"
                isDestructive
                isBusy={ isRefunding }
                disabled={ isRefunding }
                onClick={ async () => {
                  const cents = majorToMinor(
                    parseFloat( refundAmount ),
                    transaction.currency
                  );
                  const maxRefundable =
                    transaction.total_amount -
                    ( transaction.amount_refunded || 0 );

                  if ( ! cents || cents <= 0 ) {
                    setRefundError(
                      __( 'Please enter a valid amount.', 'mission' )
                    );
                    return;
                  }

                  if ( cents > maxRefundable ) {
                    setRefundError(
                      __( 'Amount exceeds refundable balance.', 'mission' )
                    );
                    return;
                  }

                  setIsRefunding( true );
                  setRefundError( '' );

                  try {
                    const updated = await apiFetch( {
                      path: `/mission/v1/transactions/${ id }/refund`,
                      method: 'POST',
                      data: { amount: cents },
                    } );
                    setTransaction( updated );
                    setShowRefundModal( false );
                    setToastKey( ( k ) => k + 1 );
                    setToast( {
                      type: 'success',
                      message: sprintf(
                        /* translators: %s: refunded amount */
                        __( '%s refunded successfully', 'mission' ),
                        formatAmount( cents, transaction.currency )
                      ),
                    } );
                  } catch ( err ) {
                    setRefundError(
                      err.message ||
                        __( 'Failed to process refund.', 'mission' )
                    );
                  }

                  setIsRefunding( false );
                } }
                __next40pxDefaultSize
              >
                { __( 'Process Refund', 'mission' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }

      { showDeleteConfirm && (
        <Modal
          title={ __( 'Delete Transaction', 'mission' ) }
          onRequestClose={ () => setShowDeleteConfirm( false ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { __( 'Are you sure you want to delete transaction', 'mission' ) }{ ' ' }
              <strong>#{ transaction.id }</strong>?{ ' ' }
              { __( 'This action cannot be undone.', 'mission' ) }
            </Text>
            <HStack justify="flex-end">
              <Button
                variant="tertiary"
                onClick={ () => setShowDeleteConfirm( false ) }
                __next40pxDefaultSize
              >
                { __( 'Cancel', 'mission' ) }
              </Button>
              <Button
                variant="primary"
                isDestructive
                isBusy={ isDeleting }
                disabled={ isDeleting }
                onClick={ handleDelete }
                __next40pxDefaultSize
              >
                { __( 'Delete', 'mission' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }

      { showRefundConfirm && (
        <Modal
          title={ __( 'Change Status to Refunded?', 'mission' ) }
          onRequestClose={ () => setShowRefundConfirm( false ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { __(
                'This will only update the status on this record — it will not issue a refund through Stripe. The donor will not receive any money back.',
                'mission'
              ) }
            </Text>
            <Text>
              { __(
                'To process an actual refund, use the Refund action from the Actions menu or refund directly in your Stripe dashboard.',
                'mission'
              ) }
            </Text>
            <HStack justify="flex-end">
              <Button
                variant="tertiary"
                onClick={ () => setShowRefundConfirm( false ) }
                __next40pxDefaultSize
              >
                { __( 'Cancel', 'mission' ) }
              </Button>
              <Button
                variant="primary"
                onClick={ () => {
                  setShowRefundConfirm( false );
                  applyStatusChange( 'refunded' );
                } }
                __next40pxDefaultSize
              >
                { __( 'Update Status Only', 'mission' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }
    </div>
  );
}
