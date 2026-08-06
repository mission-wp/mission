import { useState, useEffect, useCallback } from '@wordpress/element';
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
import StatusBadge from '../../components/StatusBadge';
import ActionsDropdown from '../../components/ActionsDropdown';
import {
  MailIcon,
  RefundIcon,
  DownloadIcon,
  FileIcon,
  TrashIcon,
} from '../shared/DetailComponents';
import TransactionDetailsCard from './TransactionDetailsCard';
import TransactionDonorCard from './TransactionDonorCard';
import TransactionActivityCard from './TransactionActivityCard';
import NotesCard from '../../components/NotesCard';
import { TRANSACTION_STATUS } from '../../constants';

function TransactionSubscriptionLink( { subscriptionId } ) {
  const adminUrl = window.missiondpAdmin?.adminUrl || '';
  return (
    <Card>
      <CardHeader size="small">
        <Text weight={ 600 }>
          { __( 'Subscription', 'mission-donation-platform' ) }
        </Text>
      </CardHeader>
      <CardBody>
        <Text>
          { __(
            'This transaction is part of a recurring subscription.',
            'mission-donation-platform'
          ) }
        </Text>
        <div style={ { marginTop: '8px' } }>
          <a
            href={ `${ adminUrl }admin.php?page=mission-donation-platform-subscriptions&subscription_id=${ subscriptionId }` }
            className="mission-back-link"
          >
            { __( 'View Subscription', 'mission-donation-platform' ) } #
            { subscriptionId } &rarr;
          </a>
        </div>
      </CardBody>
    </Card>
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

  const adminUrl = window.missiondpAdmin?.adminUrl || '';
  const transactionsUrl = `${ adminUrl }admin.php?page=mission-donation-platform-transactions`;

  useEffect( () => {
    setIsLoading( true );
    Promise.all( [
      apiFetch( {
        path: `/mission-donation-platform/v1/transactions/${ id }`,
      } ),
      apiFetch( {
        path: '/mission-donation-platform/v1/campaigns?per_page=100&orderby=title&order=ASC',
      } ),
    ] )
      .then( ( [ txn, camps ] ) => {
        setTransaction( txn );
        setCampaigns( camps );
      } )
      .catch( ( err ) => {
        setError(
          err.message ||
            __( 'Failed to load transaction.', 'mission-donation-platform' )
        );
      } )
      .finally( () => setIsLoading( false ) );
  }, [ id ] );

  const applyStatusChange = ( newStatus ) => {
    apiFetch( {
      path: `/mission-donation-platform/v1/transactions/${ id }`,
      method: 'PATCH',
      data: { status: newStatus },
    } )
      .then( () => {
        setTransaction( ( prev ) => ( { ...prev, status: newStatus } ) );
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'success',
          message: __( 'Transaction updated.', 'mission-donation-platform' ),
        } );
      } )
      .catch( ( err ) => {
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'error',
          message:
            err.message ||
            __( 'Failed to update transaction.', 'mission-donation-platform' ),
        } );
      } );
  };

  const handleStatusChange = ( newStatus ) => {
    if (
      newStatus === TRANSACTION_STATUS.REFUNDED &&
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
      path: `/mission-donation-platform/v1/transactions/${ id }`,
      method: 'PATCH',
      data: { is_anonymous: newValue },
    } )
      .then( () => {
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'success',
          message: __( 'Transaction updated.', 'mission-donation-platform' ),
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
            err.message ||
            __( 'Failed to update transaction.', 'mission-donation-platform' ),
        } );
      } );
  };

  const handleCampaignChange = ( campaignObj ) => {
    const campaignId = campaignObj ? campaignObj.id : null;

    apiFetch( {
      path: `/mission-donation-platform/v1/transactions/${ id }`,
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
          message: __( 'Transaction updated.', 'mission-donation-platform' ),
        } );
      } )
      .catch( ( err ) => {
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'error',
          message:
            err.message ||
            __( 'Failed to update transaction.', 'mission-donation-platform' ),
        } );
      } );
  };

  const handleDelete = async () => {
    setIsDeleting( true );
    try {
      await apiFetch( {
        path: `/mission-donation-platform/v1/transactions/${ id }`,
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
            &larr; { __( 'Back to Transactions', 'mission-donation-platform' ) }
          </a>
          <Text>
            { error ||
              __( 'Transaction not found.', 'mission-donation-platform' ) }
          </Text>
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
            &larr; { __( 'Back to Transactions', 'mission-donation-platform' ) }
          </a>
          <ActionsDropdown
            items={ [
              {
                label: __( 'Resend Receipt', 'mission-donation-platform' ),
                icon: <MailIcon />,
                onClick: async () => {
                  try {
                    const result = await apiFetch( {
                      path: `/mission-donation-platform/v1/transactions/${ id }/resend-receipt`,
                      method: 'POST',
                    } );
                    setToastKey( ( k ) => k + 1 );
                    setToast( {
                      type: 'success',
                      message: sprintf(
                        /* translators: %s: recipient email address */
                        __( 'Receipt sent to %s', 'mission-donation-platform' ),
                        result.sent_to
                      ),
                    } );
                  } catch ( err ) {
                    setToastKey( ( k ) => k + 1 );
                    setToast( {
                      type: 'error',
                      message:
                        err.message ||
                        __(
                          'Failed to send receipt.',
                          'mission-donation-platform'
                        ),
                    } );
                  }
                },
              },
              {
                label: __( 'Refund', 'mission-donation-platform' ),
                icon: <RefundIcon />,
                onClick: () => {
                  const refundable =
                    transaction.total_amount -
                    ( transaction.amount_refunded || 0 );
                  setRefundAmount(
                    String( minorToMajor( refundable, transaction.currency ) )
                  );
                  setRefundError( '' );
                  setShowRefundModal( true );
                },
              },
              {
                label: __( 'Download PDF', 'mission-donation-platform' ),
                icon: <DownloadIcon />,
                onClick: () => {
                  window.open(
                    `${ window.missiondpAdmin.restUrl }transactions/${ id }/receipt-pdf?_wpnonce=${ window.missiondpAdmin.restNonce }`,
                    '_blank'
                  );
                },
              },
              {
                label: __( 'Export CSV', 'mission-donation-platform' ),
                icon: <FileIcon />,
                onClick: () => {
                  window.open(
                    `${ window.missiondpAdmin.restUrl }export/download?type=transactions&id=${ id }&format=csv&_wpnonce=${ window.missiondpAdmin.restNonce }`,
                    '_blank'
                  );
                },
              },
              { divider: true },
              {
                label: __( 'Delete Transaction', 'mission-donation-platform' ),
                icon: <TrashIcon />,
                isDanger: true,
                onClick: () => setShowDeleteConfirm( true ),
              },
            ] }
          />
        </HStack>

        { /* Two-column grid */ }
        <div className="mission-detail-grid">
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
              title={ __( 'Donor Notes', 'mission-donation-platform' ) }
              hint={ __(
                'Visible to the donor. Sent via email when added.',
                'mission-donation-platform'
              ) }
              confirmBeforeSave={ {
                title: __( 'Send Note to Donor?', 'mission-donation-platform' ),
                message: __(
                  'This note will be emailed to the donor. Are you sure you want to send it?',
                  'mission-donation-platform'
                ),
                confirmLabel: __( 'Send Note', 'mission-donation-platform' ),
              } }
            />
            <NotesCard
              objectType="transactions"
              objectId={ transaction.id }
              type="internal"
              title={ __( 'Internal Notes', 'mission-donation-platform' ) }
              hint={ __(
                'Only visible to your team.',
                'mission-donation-platform'
              ) }
            />
          </VStack>
        </div>
      </VStack>

      { showRefundModal && (
        <Modal
          title={ __( 'Refund Transaction', 'mission-donation-platform' ) }
          onRequestClose={ () => setShowRefundModal( false ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { sprintf(
                /* translators: %s: formatted total amount */
                __( 'Original amount: %s', 'mission-donation-platform' ),
                formatAmount( transaction.total_amount, transaction.currency )
              ) }
              { transaction.amount_refunded > 0 &&
                ' · ' +
                  sprintf(
                    /* translators: %s: already refunded amount */
                    __( '%s already refunded', 'mission-donation-platform' ),
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
                { __( 'Refund amount', 'mission-donation-platform' ) }
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
                { __( 'Cancel', 'mission-donation-platform' ) }
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
                      __(
                        'Please enter a valid amount.',
                        'mission-donation-platform'
                      )
                    );
                    return;
                  }

                  if ( cents > maxRefundable ) {
                    setRefundError(
                      __(
                        'Amount exceeds refundable balance.',
                        'mission-donation-platform'
                      )
                    );
                    return;
                  }

                  setIsRefunding( true );
                  setRefundError( '' );

                  try {
                    const updated = await apiFetch( {
                      path: `/mission-donation-platform/v1/transactions/${ id }/refund`,
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
                        __(
                          '%s refunded successfully',
                          'mission-donation-platform'
                        ),
                        formatAmount( cents, transaction.currency )
                      ),
                    } );
                  } catch ( err ) {
                    setRefundError(
                      err.message ||
                        __(
                          'Failed to process refund.',
                          'mission-donation-platform'
                        )
                    );
                  }

                  setIsRefunding( false );
                } }
                __next40pxDefaultSize
              >
                { __( 'Process Refund', 'mission-donation-platform' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }

      { showDeleteConfirm && (
        <Modal
          title={ __( 'Delete Transaction', 'mission-donation-platform' ) }
          onRequestClose={ () => setShowDeleteConfirm( false ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { __(
                'Are you sure you want to delete transaction',
                'mission-donation-platform'
              ) }{ ' ' }
              <strong>#{ transaction.id }</strong>?{ ' ' }
              { __(
                'This action cannot be undone.',
                'mission-donation-platform'
              ) }
            </Text>
            <HStack justify="flex-end">
              <Button
                variant="tertiary"
                onClick={ () => setShowDeleteConfirm( false ) }
                __next40pxDefaultSize
              >
                { __( 'Cancel', 'mission-donation-platform' ) }
              </Button>
              <Button
                variant="primary"
                isDestructive
                isBusy={ isDeleting }
                disabled={ isDeleting }
                onClick={ handleDelete }
                __next40pxDefaultSize
              >
                { __( 'Delete', 'mission-donation-platform' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }

      { showRefundConfirm && (
        <Modal
          title={ __(
            'Change Status to Refunded?',
            'mission-donation-platform'
          ) }
          onRequestClose={ () => setShowRefundConfirm( false ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { __(
                'This will only update the status on this record — it will not issue a refund through Stripe. The donor will not receive any money back.',
                'mission-donation-platform'
              ) }
            </Text>
            <Text>
              { __(
                'To process an actual refund, use the Refund action from the Actions menu or refund directly in your Stripe dashboard.',
                'mission-donation-platform'
              ) }
            </Text>
            <HStack justify="flex-end">
              <Button
                variant="tertiary"
                onClick={ () => setShowRefundConfirm( false ) }
                __next40pxDefaultSize
              >
                { __( 'Cancel', 'mission-donation-platform' ) }
              </Button>
              <Button
                variant="primary"
                onClick={ () => {
                  setShowRefundConfirm( false );
                  applyStatusChange( TRANSACTION_STATUS.REFUNDED );
                } }
                __next40pxDefaultSize
              >
                { __( 'Update Status Only', 'mission-donation-platform' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }
    </div>
  );
}
