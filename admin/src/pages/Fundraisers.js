import { __ } from '@wordpress/i18n';
import { formatDate } from '@shared/date';
import { formatAmount } from '@shared/currency';
import SkeletonBar from '@shared/components/SkeletonBar';
import StatCard from '@shared/components/StatCard';
import P2PListView from '../components/P2PListView';
import P2PStatusBadge from '../components/P2PStatusBadge';

const FlagIcon = () => (
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
    <path d="M12 42V8" />
    <path d="M12 9c8-4 16 4 24 0v18c-8 4-16-4-24 0" />
  </svg>
);

const STATUS_ELEMENTS = [
  { value: 'active', label: __( 'Active', 'mission-donation-platform' ) },
  { value: 'pending', label: __( 'Pending', 'mission-donation-platform' ) },
  { value: 'inactive', label: __( 'Inactive', 'mission-donation-platform' ) },
];

function buildFields( campaignElements ) {
  return [
    {
      id: 'fundraiser',
      label: __( 'Fundraiser', 'mission-donation-platform' ),
      enableSorting: false,
      enableHiding: false,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="55%" height="16px" />
        ) : (
          <span style={ { fontWeight: 500 } }>{ item.donor_name }</span>
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
      id: 'team_name',
      label: __( 'Team', 'mission-donation-platform' ),
      enableSorting: false,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="50%" height="16px" />
        ) : (
          <span style={ { color: '#9b9ba8' } }>
            { item.team_name || '\u2014' }
          </span>
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
      enableSorting: true,
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
          <P2PStatusBadge status={ item.status } />
        ),
      elements: STATUS_ELEMENTS,
      filterBy: { operators: [ 'is' ] },
    },
    {
      id: 'date_created',
      label: __( 'Date Added', 'mission-donation-platform' ),
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
  titleField: 'fundraiser',
  fields: [
    'campaign_id',
    'team_name',
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
        label={ __( 'Total Fundraisers', 'mission-donation-platform' ) }
        value={ summary ? summary.total_fundraisers.toLocaleString() : '' }
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

export default function Fundraisers() {
  return (
    <P2PListView
      title={ __( 'Fundraisers', 'mission-donation-platform' ) }
      description={ __(
        'People raising money for your peer-to-peer campaigns.',
        'mission-donation-platform'
      ) }
      listPath="/mission-donation-platform/v1/fundraisers"
      summaryPath="/mission-donation-platform/v1/fundraisers/summary"
      bulkPath="/mission-donation-platform/v1/fundraisers/bulk"
      storageKey="fundraisers"
      buildFields={ buildFields }
      defaultView={ DEFAULT_VIEW }
      filterFields={ [ 'campaign_id', 'status' ] }
      renderStats={ renderStats }
      emptyIcon={ <FlagIcon /> }
      emptyText={ __( 'No fundraisers yet.', 'mission-donation-platform' ) }
      emptyHint={ __(
        'Fundraisers appear here once supporters sign up for a peer-to-peer campaign.',
        'mission-donation-platform'
      ) }
    />
  );
}
