import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
  Button,
  Modal,
  __experimentalHStack as HStack,
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { formatDateTime } from '@shared/date';
import SkeletonBar from '@shared/components/SkeletonBar';
import Toast from '../../components/Toast';
import ActionsDropdown from '../../components/ActionsDropdown';
import DonorAvatar from '../../components/DonorAvatar';
import ProfileHeader from '../../components/ProfileHeader';
import GoalMeter from '../../components/GoalMeter';
import StatusBadge from '../../components/StatusBadge';
import TransactionsTableCard from '../../components/TransactionsTableCard';
import ActivityTimelineCard from '../../components/ActivityTimelineCard';
import {
  CopyIcon,
  CheckIcon,
  EyeOffIcon,
  TrashIcon,
  ExternalLinkIcon,
  dedicationLabel,
} from '../shared/DetailComponents';
import FundraiserDetailsCard from './FundraiserDetailsCard';
import StoryCard from './StoryCard';
import EditFundraiserDrawer from './EditFundraiserDrawer';
import { P2P_STATUS, P2P_STATUS_META } from '../../constants';

const EVENT_LABELS = {
  fundraiser_registered: __(
    'Fundraiser registered',
    'mission-donation-platform'
  ),
  fundraiser_approved: __( 'Fundraiser approved', 'mission-donation-platform' ),
  fundraiser_reactivated: __(
    'Fundraiser reactivated',
    'mission-donation-platform'
  ),
};

const DOT_CLASSES = {
  fundraiser_registered: 'is-success',
  fundraiser_approved: 'is-success',
  fundraiser_reactivated: 'is-success',
  fundraiser_milestone: 'is-reached',
};

function getEventLabel( entry ) {
  if ( entry.event === 'fundraiser_milestone' && entry.data?.percentage ) {
    return sprintf(
      /* translators: %d: percentage of goal reached */
      __( 'Reached %d%% of goal', 'mission-donation-platform' ),
      entry.data.percentage
    );
  }
  return EVENT_LABELS[ entry.event ] || entry.event;
}

export default function FundraiserDetail( { id } ) {
  const [ fundraiser, setFundraiser ] = useState( null );
  const [ transactions, setTransactions ] = useState( [] );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ error, setError ] = useState( null );
  const [ toast, setToast ] = useState( null );
  const [ toastKey, setToastKey ] = useState( 0 );
  const [ showDrawer, setShowDrawer ] = useState( false );
  const [ showDeleteConfirm, setShowDeleteConfirm ] = useState( false );
  const [ isDeleting, setIsDeleting ] = useState( false );
  const clearToast = useCallback( () => setToast( null ), [] );

  const adminUrl = window.missiondpAdmin?.adminUrl || '';
  const fundraisersUrl = `${ adminUrl }admin.php?page=mission-donation-platform-fundraisers`;

  const hasLoaded = useRef( false );

  const showToast = useCallback( ( type, message ) => {
    setToastKey( ( k ) => k + 1 );
    setToast( { type, message } );
  }, [] );

  const fetchData = useCallback( async () => {
    if ( ! hasLoaded.current ) {
      setIsLoading( true );
    }
    setError( null );
    try {
      const [ fundraiserData, txnData ] = await Promise.all( [
        apiFetch( {
          path: `/mission-donation-platform/v1/fundraisers/${ id }`,
        } ),
        apiFetch( {
          path: `/mission-donation-platform/v1/transactions?fundraiser_id=${ id }&per_page=100`,
        } ),
      ] );
      setFundraiser( fundraiserData );
      setTransactions( txnData );
      hasLoaded.current = true;
    } catch ( err ) {
      const message =
        err.message ||
        __( 'Failed to load fundraiser.', 'mission-donation-platform' );
      if ( hasLoaded.current ) {
        showToast( 'error', message );
      } else {
        setError( message );
      }
    } finally {
      setIsLoading( false );
    }
  }, [ id, showToast ] );

  useEffect( () => {
    fetchData();
  }, [ fetchData ] );

  if ( error ) {
    return (
      <div className="mission-admin-page">
        <VStack spacing={ 4 }>
          <a href={ fundraisersUrl } className="mission-back-link">
            &larr; { __( 'Back to Fundraisers', 'mission-donation-platform' ) }
          </a>
          <Text>{ error }</Text>
        </VStack>
      </div>
    );
  }

  if ( isLoading || ! fundraiser ) {
    return (
      <div className="mission-admin-page">
        <VStack spacing={ 6 }>
          <HStack justify="space-between" alignment="center">
            <a href={ fundraisersUrl } className="mission-back-link">
              &larr;{ ' ' }
              { __( 'Back to Fundraisers', 'mission-donation-platform' ) }
            </a>
          </HStack>
          <ProfileHeader avatar isLoading />
          <div className="mission-detail-grid">
            <div className="mission-card">
              <SkeletonBar width="40%" height="18px" />
            </div>
            <div className="mission-card">
              <SkeletonBar width="60%" height="18px" />
            </div>
          </div>
        </VStack>
      </div>
    );
  }

  const statusMeta = P2P_STATUS_META[ fundraiser.status ];
  const avgGift =
    fundraiser.transaction_count > 0
      ? Math.round( fundraiser.raised / fundraiser.transaction_count )
      : 0;

  const handleCopyUrl = async () => {
    try {
      await window.navigator.clipboard.writeText( fundraiser.page_url );
      showToast(
        'success',
        __( 'Page URL copied.', 'mission-donation-platform' )
      );
    } catch {
      showToast(
        'error',
        __( 'Could not copy the URL.', 'mission-donation-platform' )
      );
    }
  };

  const handleApprove = async () => {
    try {
      const updated = await apiFetch( {
        path: `/mission-donation-platform/v1/fundraisers/${ id }/approve`,
        method: 'POST',
      } );
      setFundraiser( updated );
      showToast(
        'success',
        __( 'Fundraiser approved.', 'mission-donation-platform' )
      );
    } catch ( err ) {
      showToast(
        'error',
        err.message ||
          __( 'Failed to approve fundraiser.', 'mission-donation-platform' )
      );
    }
  };

  const handleUnpublish = async () => {
    try {
      const updated = await apiFetch( {
        path: `/mission-donation-platform/v1/fundraisers/${ id }`,
        method: 'PUT',
        data: { status: P2P_STATUS.INACTIVE },
      } );
      setFundraiser( updated );
      showToast(
        'success',
        __( 'Fundraiser page unpublished.', 'mission-donation-platform' )
      );
    } catch ( err ) {
      showToast(
        'error',
        err.message ||
          __( 'Failed to unpublish page.', 'mission-donation-platform' )
      );
    }
  };

  const handleDelete = async () => {
    setIsDeleting( true );
    try {
      await apiFetch( {
        path: `/mission-donation-platform/v1/fundraisers/${ id }`,
        method: 'DELETE',
      } );
      window.location.href = fundraisersUrl;
    } catch ( err ) {
      setIsDeleting( false );
      setShowDeleteConfirm( false );
      showToast(
        'error',
        err.message ||
          __( 'Failed to delete fundraiser.', 'mission-donation-platform' )
      );
    }
  };

  const actionItems = [
    ...( fundraiser.page_url
      ? [
          {
            label: __( 'View Public Page', 'mission-donation-platform' ),
            icon: <ExternalLinkIcon />,
            onClick: () => window.open( fundraiser.page_url, '_blank' ),
          },
          {
            label: __( 'Copy Page URL', 'mission-donation-platform' ),
            icon: <CopyIcon />,
            onClick: handleCopyUrl,
          },
          { divider: true },
        ]
      : [] ),
    fundraiser.status === P2P_STATUS.ACTIVE
      ? {
          label: __( 'Unpublish Page', 'mission-donation-platform' ),
          icon: <EyeOffIcon />,
          onClick: handleUnpublish,
        }
      : {
          label:
            fundraiser.status === P2P_STATUS.PENDING
              ? __( 'Approve', 'mission-donation-platform' )
              : __( 'Reactivate Page', 'mission-donation-platform' ),
          icon: <CheckIcon />,
          onClick: handleApprove,
        },
    {
      label: __( 'Delete Page', 'mission-donation-platform' ),
      icon: <TrashIcon />,
      isDanger: true,
      onClick: () => setShowDeleteConfirm( true ),
    },
  ];

  return (
    <div className="mission-admin-page">
      <Toast key={ toastKey } notice={ toast } onDone={ clearToast } />
      <VStack spacing={ 6 }>
        { /* Breadcrumb + actions */ }
        <HStack justify="space-between" alignment="center">
          <a href={ fundraisersUrl } className="mission-back-link">
            &larr; { __( 'Back to Fundraisers', 'mission-donation-platform' ) }
          </a>
          <HStack justify="flex-end" expanded={ false } spacing={ 2 }>
            <Button
              variant="secondary"
              onClick={ () => setShowDrawer( true ) }
              __next40pxDefaultSize
            >
              { __( 'Edit Fundraiser', 'mission-donation-platform' ) }
            </Button>
            <ActionsDropdown items={ actionItems } />
          </HStack>
        </HStack>

        { /* Profile header */ }
        <ProfileHeader
          avatar={
            <DonorAvatar
              firstName={ fundraiser.donor_name?.split( ' ' )[ 0 ] }
              lastName={ fundraiser.donor_name
                ?.split( ' ' )
                .slice( 1 )
                .join( ' ' ) }
              imageUrl={ fundraiser.profile_image }
              size="xl"
            />
          }
          name={ fundraiser.donor_name }
          subtitle={ fundraiser.headline || fundraiser.campaign_title }
          badges={
            <>
              <StatusBadge
                status={ fundraiser.status }
                tone={ statusMeta?.tone }
              />
              { fundraiser.team_id && (
                <a
                  className="mission-chip"
                  href={ `${ adminUrl }admin.php?page=mission-donation-platform-teams&team_id=${ fundraiser.team_id }` }
                >
                  { fundraiser.is_team_captain
                    ? sprintf(
                        /* translators: %s: team name */
                        __( 'Captain · %s', 'mission-donation-platform' ),
                        fundraiser.team_name
                      )
                    : fundraiser.team_name }
                </a>
              ) }
              { fundraiser.dedication && (
                <span className="mission-chip mission-chip--tribute">
                  { dedicationLabel( fundraiser.dedication ) }
                </span>
              ) }
            </>
          }
          stats={ [
            {
              value: formatAmount( fundraiser.raised ),
              label: __( 'Raised', 'mission-donation-platform' ),
            },
            {
              value: fundraiser.transaction_count,
              label: __( 'Donations', 'mission-donation-platform' ),
            },
            {
              value: formatAmount( avgGift ),
              label: __( 'Avg. gift', 'mission-donation-platform' ),
            },
          ] }
        />

        { /* Two-column grid */ }
        <div className="mission-detail-grid">
          <VStack spacing={ 4 }>
            <GoalMeter
              raised={ fundraiser.raised }
              goal={ fundraiser.goal }
              endDate={ fundraiser.campaign_end_date }
              daysLeft={ fundraiser.campaign_days_left }
            />
            <TransactionsTableCard
              title={ __( 'Donations', 'mission-donation-platform' ) }
              badge={ sprintf(
                /* translators: %d: number of donations */
                _n(
                  '%d donation',
                  '%d donations',
                  transactions.length,
                  'mission-donation-platform'
                ),
                transactions.length
              ) }
              transactions={ transactions }
              columns={ [ 'date', 'donor', 'amount', 'type', 'status' ] }
              collapsedCount={ 10 }
            />
            <StoryCard
              fundraiser={ fundraiser }
              onSaved={ ( updated ) => {
                setFundraiser( updated );
                showToast(
                  'success',
                  __( 'Story saved.', 'mission-donation-platform' )
                );
              } }
              onError={ ( message ) => showToast( 'error', message ) }
            />
          </VStack>
          <VStack spacing={ 4 }>
            <FundraiserDetailsCard fundraiser={ fundraiser } />
            <ActivityTimelineCard
              path={ `/mission-donation-platform/v1/activity?object_type=fundraiser&object_id=${ id }&per_page=25` }
              mapEntry={ ( entry ) => ( {
                title: getEventLabel( entry ),
                date: formatDateTime( entry.date_created ),
                dotClass: DOT_CLASSES[ entry.event ] || 'is-success',
              } ) }
              fallbackEvents={ [
                {
                  title: __(
                    'Fundraiser created',
                    'mission-donation-platform'
                  ),
                  date: formatDateTime( fundraiser.date_created ),
                  dotClass: 'is-success',
                },
              ] }
            />
          </VStack>
        </div>
      </VStack>

      <EditFundraiserDrawer
        isOpen={ showDrawer }
        onClose={ () => setShowDrawer( false ) }
        fundraiser={ fundraiser }
        onSaved={ ( updated ) => {
          setShowDrawer( false );
          setFundraiser( updated );
          showToast(
            'success',
            __( 'Fundraiser updated.', 'mission-donation-platform' )
          );
        } }
      />

      { showDeleteConfirm && (
        <Modal
          title={ __( 'Delete Fundraiser Page', 'mission-donation-platform' ) }
          onRequestClose={ () => setShowDeleteConfirm( false ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { sprintf(
                /* translators: %s: fundraiser name */
                __(
                  'Are you sure you want to delete the fundraising page for %s? Donations already received are kept, but the page and its progress are removed. This action cannot be undone.',
                  'mission-donation-platform'
                ),
                fundraiser.donor_name
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
    </div>
  );
}
