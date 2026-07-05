import { __ } from '@wordpress/i18n';
import { formatDate } from '@shared/date';
import { formatAmount } from '@shared/currency';
import SkeletonBar from '@shared/components/SkeletonBar';
import StatCard from '@shared/components/StatCard';
import P2PListView from '../../components/P2PListView';
import StatusBadge from '../../components/StatusBadge';
import { P2P_STATUS_META } from '../../constants';

const TeamIcon = () => (
  <svg
    width="48"
    height="48"
    viewBox="0 0 48 48"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.2"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <circle cx="16" cy="18" r="5" />
    <circle cx="32" cy="18" r="5" />
    <path d="M6 38c0-5 4.5-9 10-9s10 4 10 9" />
    <path d="M26 30c1.8-1 3.8-1 6-1 5.5 0 10 4 10 9" />
  </svg>
);

const STATUS_ELEMENTS = Object.entries( P2P_STATUS_META ).map(
  ( [ value, { label } ] ) => ( { value, label } )
);

function buildFields( campaignElements ) {
  return [
    {
      id: 'name',
      label: __( 'Team', 'mission-donation-platform' ),
      enableSorting: true,
      enableHiding: false,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="55%" height="16px" />
        ) : (
          <a
            href={ `${
              window.missiondpAdmin?.adminUrl || ''
            }admin.php?page=mission-donation-platform-teams&team_id=${
              item.id
            }` }
            className="mission-table-link"
            style={ { fontWeight: 500 } }
          >
            { item.name }
          </a>
        ),
    },
    {
      id: 'campaign_id',
      label: __( 'Campaign', 'mission-donation-platform' ),
      enableSorting: false,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="60%" height="16px" />
        ) : (
          <span>{ item.campaign_title || '\u2014' }</span>
        ),
      elements: campaignElements,
      filterBy: { operators: [ 'is' ] },
    },
    {
      id: 'captain_name',
      label: __( 'Captain', 'mission-donation-platform' ),
      enableSorting: false,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="50%" height="16px" />
        ) : (
          <span style={ { color: '#9b9ba8' } }>
            { item.captain_name || '\u2014' }
          </span>
        ),
    },
    {
      id: 'member_count',
      label: __( 'Members', 'mission-donation-platform' ),
      enableSorting: false,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="30%" height="16px" />
        ) : (
          <span>{ item.member_count }</span>
        ),
    },
    {
      id: 'goal',
      label: __( 'Goal', 'mission-donation-platform' ),
      enableSorting: true,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="40%" height="16px" />
        ) : (
          <span>{ formatAmount( item.goal ) }</span>
        ),
    },
    {
      id: 'raised',
      label: __( 'Raised', 'mission-donation-platform' ),
      enableSorting: false,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="40%" height="16px" />
        ) : (
          <span style={ { fontWeight: 500 } }>
            { formatAmount( item.raised ) }
          </span>
        ),
    },
    {
      id: 'status',
      label: __( 'Status', 'mission-donation-platform' ),
      enableSorting: false,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="70px" height="22px" />
        ) : (
          <StatusBadge
            status={ item.status }
            tone={ P2P_STATUS_META[ item.status ]?.tone }
          />
        ),
      elements: STATUS_ELEMENTS,
      filterBy: { operators: [ 'is' ] },
    },
    {
      id: 'date_created',
      label: __( 'Date Created', 'mission-donation-platform' ),
      enableSorting: true,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="60%" height="16px" />
        ) : (
          <span style={ { color: '#9b9ba8', fontSize: '13px' } }>
            { formatDate( item.date_created ) }
          </span>
        ),
    },
  ];
}

const DEFAULT_VIEW = {
  type: 'table',
  titleField: 'name',
  fields: [
    'campaign_id',
    'captain_name',
    'member_count',
    'goal',
    'raised',
    'status',
    'date_created',
  ],
  search: '',
  filters: [],
  sort: { field: 'date_created', direction: 'desc' },
  page: 1,
  perPage: 25,
  layout: {},
};

function renderStats( summary ) {
  return (
    <>
      <StatCard
        label={ __( 'Total Teams', 'mission-donation-platform' ) }
        value={ summary ? summary.total_teams.toLocaleString() : '' }
        isLoading={ ! summary }
      />
      <StatCard
        label={ __( 'Active', 'mission-donation-platform' ) }
        value={ summary ? summary.active_count.toLocaleString() : '' }
        isLoading={ ! summary }
      />
      <StatCard
        label={ __( 'Pending Approval', 'mission-donation-platform' ) }
        value={ summary ? summary.pending_count.toLocaleString() : '' }
        isLoading={ ! summary }
      />
      <StatCard
        label={ __( 'Total Raised', 'mission-donation-platform' ) }
        value={ summary ? formatAmount( summary.total_raised ) : '' }
        isLoading={ ! summary }
      />
    </>
  );
}

export default function TeamList() {
  return (
    <P2PListView
      title={ __( 'Teams', 'mission-donation-platform' ) }
      description={ __(
        'Groups of fundraisers working together on your campaigns.',
        'mission-donation-platform'
      ) }
      listPath="/mission-donation-platform/v1/teams"
      summaryPath="/mission-donation-platform/v1/teams/summary"
      storageKey="teams"
      buildFields={ buildFields }
      defaultView={ DEFAULT_VIEW }
      filterFields={ [ 'campaign_id', 'status' ] }
      renderStats={ renderStats }
      emptyIcon={ <TeamIcon /> }
      emptyText={ __( 'No teams yet.', 'mission-donation-platform' ) }
      emptyHint={ __(
        'Teams appear here once supporters create or are added to one.',
        'mission-donation-platform'
      ) }
    />
  );
}
