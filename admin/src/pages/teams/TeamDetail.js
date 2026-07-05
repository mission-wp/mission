import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
  Button,
  Modal,
  __experimentalHStack as HStack,
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { formatDateTime } from '@shared/date';
import SkeletonBar from '@shared/components/SkeletonBar';
import Toast from '../../components/Toast';
import ActionsDropdown from '../../components/ActionsDropdown';
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
} from '../shared/DetailComponents';
import TeamDetailsCard from './TeamDetailsCard';
import TeamMembersCard from './TeamMembersCard';
import EditTeamDrawer from './EditTeamDrawer';
import { P2P_STATUS, P2P_STATUS_META } from '../../constants';

const EVENT_LABELS = {
  team_created: __( 'Team created', 'mission-donation-platform' ),
  team_approved: __( 'Team approved', 'mission-donation-platform' ),
  team_reactivated: __( 'Team reactivated', 'mission-donation-platform' ),
};

function getEventLabel( entry ) {
  const data = entry.data || {};

  switch ( entry.event ) {
    case 'team_joined':
      return data.donor_name
        ? sprintf(
            /* translators: %s: member name */
            __( '%s joined the team', 'mission-donation-platform' ),
            data.donor_name
          )
        : __( 'A fundraiser joined the team', 'mission-donation-platform' );

    case 'team_invited':
      return data.email
        ? sprintf(
            /* translators: %s: invited email address */
            __( '%s was invited', 'mission-donation-platform' ),
            data.email
          )
        : __( 'An invitation was sent', 'mission-donation-platform' );

    case 'team_captain_promoted':
      return data.captain_name
        ? sprintf(
            /* translators: %s: new captain name */
            __( '%s became captain', 'mission-donation-platform' ),
            data.captain_name
          )
        : __( 'A new captain was promoted', 'mission-donation-platform' );

    default:
      return EVENT_LABELS[ entry.event ] || entry.event;
  }
}

export default function TeamDetail( { id } ) {
  const [ team, setTeam ] = useState( null );
  const [ members, setMembers ] = useState( [] );
  const [ transactions, setTransactions ] = useState( [] );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ error, setError ] = useState( null );
  const [ toast, setToast ] = useState( null );
  const [ toastKey, setToastKey ] = useState( 0 );
  const [ showDrawer, setShowDrawer ] = useState( false );
  const [ showDisbandConfirm, setShowDisbandConfirm ] = useState( false );
  const [ isDisbanding, setIsDisbanding ] = useState( false );
  const clearToast = useCallback( () => setToast( null ), [] );

  const adminUrl = window.missiondpAdmin?.adminUrl || '';
  const teamsUrl = `${ adminUrl }admin.php?page=mission-donation-platform-teams`;

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
      const [ teamData, memberData, txnData ] = await Promise.all( [
        apiFetch( { path: `/mission-donation-platform/v1/teams/${ id }` } ),
        apiFetch( {
          path: `/mission-donation-platform/v1/fundraisers?team_id=${ id }&per_page=100&orderby=raised&order=DESC`,
        } ),
        apiFetch( {
          path: `/mission-donation-platform/v1/transactions?team_id=${ id }&per_page=100`,
        } ),
      ] );
      setTeam( teamData );
      setMembers( memberData );
      setTransactions( txnData );
      hasLoaded.current = true;
    } catch ( err ) {
      const message =
        err.message ||
        __( 'Failed to load team.', 'mission-donation-platform' );
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
          <a href={ teamsUrl } className="mission-back-link">
            &larr; { __( 'Back to Teams', 'mission-donation-platform' ) }
          </a>
          <Text>{ error }</Text>
        </VStack>
      </div>
    );
  }

  if ( isLoading || ! team ) {
    return (
      <div className="mission-admin-page">
        <VStack spacing={ 6 }>
          <HStack justify="space-between" alignment="center">
            <a href={ teamsUrl } className="mission-back-link">
              &larr; { __( 'Back to Teams', 'mission-donation-platform' ) }
            </a>
          </HStack>
          <ProfileHeader isLoading />
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

  const statusMeta = P2P_STATUS_META[ team.status ];

  const handleCopyUrl = async () => {
    try {
      await window.navigator.clipboard.writeText( team.page_url );
      showToast(
        'success',
        __( 'Team URL copied.', 'mission-donation-platform' )
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
        path: `/mission-donation-platform/v1/teams/${ id }/approve`,
        method: 'POST',
      } );
      setTeam( updated );
      showToast(
        'success',
        __( 'Team approved.', 'mission-donation-platform' )
      );
    } catch ( err ) {
      showToast(
        'error',
        err.message ||
          __( 'Failed to approve team.', 'mission-donation-platform' )
      );
    }
  };

  const handleDeactivate = async () => {
    try {
      const updated = await apiFetch( {
        path: `/mission-donation-platform/v1/teams/${ id }`,
        method: 'PUT',
        data: { status: P2P_STATUS.INACTIVE },
      } );
      setTeam( updated );
      showToast(
        'success',
        __( 'Team deactivated.', 'mission-donation-platform' )
      );
    } catch ( err ) {
      showToast(
        'error',
        err.message ||
          __( 'Failed to deactivate team.', 'mission-donation-platform' )
      );
    }
  };

  const handleDisband = async () => {
    setIsDisbanding( true );
    try {
      await apiFetch( {
        path: `/mission-donation-platform/v1/teams/${ id }`,
        method: 'DELETE',
      } );
      window.location.href = teamsUrl;
    } catch ( err ) {
      setIsDisbanding( false );
      setShowDisbandConfirm( false );
      showToast(
        'error',
        err.message ||
          __( 'Failed to disband team.', 'mission-donation-platform' )
      );
    }
  };

  const handlePromote = async ( member ) => {
    try {
      await apiFetch( {
        path: `/mission-donation-platform/v1/teams/${ id }/members/${ member.id }/promote`,
        method: 'POST',
      } );
      await fetchData();
      showToast(
        'success',
        sprintf(
          /* translators: %s: member name */
          __( '%s is now the team captain.', 'mission-donation-platform' ),
          member.donor_name
        )
      );
    } catch ( err ) {
      showToast(
        'error',
        err.message ||
          __( 'Failed to promote member.', 'mission-donation-platform' )
      );
    }
  };

  const handleRemove = async ( member ) => {
    try {
      await apiFetch( {
        path: `/mission-donation-platform/v1/teams/${ id }/members/${ member.id }/remove`,
        method: 'POST',
      } );
      await fetchData();
      showToast(
        'success',
        sprintf(
          /* translators: %s: member name */
          __( '%s was removed from the team.', 'mission-donation-platform' ),
          member.donor_name
        )
      );
    } catch ( err ) {
      showToast(
        'error',
        err.message ||
          __( 'Failed to remove member.', 'mission-donation-platform' )
      );
    }
  };

  const actionItems = [
    ...( team.page_url
      ? [
          {
            label: __( 'View Public Page', 'mission-donation-platform' ),
            icon: <ExternalLinkIcon />,
            onClick: () => window.open( team.page_url, '_blank' ),
          },
          {
            label: __( 'Copy Team URL', 'mission-donation-platform' ),
            icon: <CopyIcon />,
            onClick: handleCopyUrl,
          },
          { divider: true },
        ]
      : [] ),
    team.status === P2P_STATUS.ACTIVE
      ? {
          label: __( 'Deactivate Team', 'mission-donation-platform' ),
          icon: <EyeOffIcon />,
          onClick: handleDeactivate,
        }
      : {
          label:
            team.status === P2P_STATUS.PENDING
              ? __( 'Approve', 'mission-donation-platform' )
              : __( 'Reactivate Team', 'mission-donation-platform' ),
          icon: <CheckIcon />,
          onClick: handleApprove,
        },
    {
      label: __( 'Disband Team', 'mission-donation-platform' ),
      icon: <TrashIcon />,
      isDanger: true,
      onClick: () => setShowDisbandConfirm( true ),
    },
  ];

  return (
    <div className="mission-admin-page">
      <Toast key={ toastKey } notice={ toast } onDone={ clearToast } />
      <VStack spacing={ 6 }>
        { /* Breadcrumb + actions */ }
        <HStack justify="space-between" alignment="center">
          <a href={ teamsUrl } className="mission-back-link">
            &larr; { __( 'Back to Teams', 'mission-donation-platform' ) }
          </a>
          <HStack justify="flex-end" expanded={ false } spacing={ 2 }>
            <Button
              variant="secondary"
              onClick={ () => setShowDrawer( true ) }
              __next40pxDefaultSize
            >
              { __( 'Edit Team', 'mission-donation-platform' ) }
            </Button>
            <ActionsDropdown items={ actionItems } />
          </HStack>
        </HStack>

        { /* Profile header */ }
        <ProfileHeader
          name={ team.name }
          subtitle={ sprintf(
            /* translators: %s: campaign title */
            __( 'Team fundraising for %s', 'mission-donation-platform' ),
            team.campaign_title
          ) }
          badges={
            <>
              <StatusBadge status={ team.status } tone={ statusMeta?.tone } />
              <span className="mission-chip">
                { team.access === 'private'
                  ? __( 'Private', 'mission-donation-platform' )
                  : __( 'Public', 'mission-donation-platform' ) }
              </span>
              { team.rank > 0 && (
                <span className="mission-chip">
                  { sprintf(
                    /* translators: 1: leaderboard rank, 2: total number of teams */
                    __(
                      'Rank #%1$d of %2$d teams',
                      'mission-donation-platform'
                    ),
                    team.rank,
                    team.rank_total
                  ) }
                </span>
              ) }
            </>
          }
          stats={ [
            {
              value: formatAmount( team.raised ),
              label: __( 'Raised', 'mission-donation-platform' ),
            },
            {
              value: team.member_count,
              label: __( 'Members', 'mission-donation-platform' ),
            },
            {
              value: team.donation_count,
              label: __( 'Donations', 'mission-donation-platform' ),
            },
          ] }
        />

        { /* Two-column grid */ }
        <div className="mission-detail-grid">
          <VStack spacing={ 4 }>
            <GoalMeter
              raised={ team.raised }
              goal={ team.goal }
              endDate={ team.campaign_end_date }
              daysLeft={ team.campaign_days_left }
              goalLabel={ __( 'team goal', 'mission-donation-platform' ) }
            />
            <TeamMembersCard
              members={ members }
              onPromote={ handlePromote }
              onRemove={ handleRemove }
            />
            <TransactionsTableCard
              title={ __( 'Recent Donations', 'mission-donation-platform' ) }
              badge={ sprintf(
                /* translators: %d: number of donations */
                __( '%d donations', 'mission-donation-platform' ),
                transactions.length
              ) }
              transactions={ transactions }
              columns={ [ 'date', 'donor', 'fundraiser', 'amount', 'status' ] }
              collapsedCount={ 6 }
            />
          </VStack>
          <VStack spacing={ 4 }>
            <TeamDetailsCard team={ team } />
            <ActivityTimelineCard
              path={ `/mission-donation-platform/v1/activity?object_type=team&object_id=${ id }&per_page=25` }
              mapEntry={ ( entry ) => ( {
                title: getEventLabel( entry ),
                date: formatDateTime( entry.date_created ),
                dotClass: 'is-success',
              } ) }
              fallbackEvents={ [
                {
                  title: __( 'Team created', 'mission-donation-platform' ),
                  date: formatDateTime( team.date_created ),
                  dotClass: 'is-success',
                },
              ] }
            />
          </VStack>
        </div>
      </VStack>

      <EditTeamDrawer
        isOpen={ showDrawer }
        onClose={ () => setShowDrawer( false ) }
        team={ team }
        members={ members }
        onSaved={ ( updated ) => {
          setShowDrawer( false );
          setTeam( updated );
          fetchData();
          showToast(
            'success',
            __( 'Team updated.', 'mission-donation-platform' )
          );
        } }
      />

      { showDisbandConfirm && (
        <Modal
          title={ __( 'Disband Team', 'mission-donation-platform' ) }
          onRequestClose={ () => setShowDisbandConfirm( false ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { sprintf(
                /* translators: %s: team name */
                __(
                  'Are you sure you want to disband %s? Members keep their individual fundraising pages, but the team page and its combined progress are removed. This action cannot be undone.',
                  'mission-donation-platform'
                ),
                team.name
              ) }
            </Text>
            <HStack justify="flex-end">
              <Button
                variant="tertiary"
                onClick={ () => setShowDisbandConfirm( false ) }
                __next40pxDefaultSize
              >
                { __( 'Cancel', 'mission-donation-platform' ) }
              </Button>
              <Button
                variant="primary"
                isDestructive
                isBusy={ isDisbanding }
                disabled={ isDisbanding }
                onClick={ handleDisband }
                __next40pxDefaultSize
              >
                { __( 'Disband Team', 'mission-donation-platform' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }
    </div>
  );
}
