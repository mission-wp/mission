import { useState, useEffect, useCallback } from '@wordpress/element';
import ClickableRows from '@shared/components/ClickableRows';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { formatDate } from '@shared/date';
import { usePersistedView } from '@shared/hooks/use-persisted-view';
import EmptyState from '../../../components/EmptyState';

const TableIcon = () => (
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
    <rect x="6" y="8" width="36" height="32" rx="3" />
    <path d="M6 16h36M6 24h36M6 32h36" />
    <path d="M18 16v24M32 16v24" />
  </svg>
);

const STATUS_STYLES = {
  completed: {
    backgroundColor: '#eafaf0',
    color: '#1a7338',
    label: __( 'Completed', 'mission-donation-platform' ),
  },
  pending: {
    backgroundColor: '#fef3c7',
    color: '#92400e',
    label: __( 'Pending', 'mission-donation-platform' ),
  },
  refunded: {
    backgroundColor: '#fef2f2',
    color: '#dc2626',
    label: __( 'Refunded', 'mission-donation-platform' ),
  },
  cancelled: {
    backgroundColor: '#f0f0f0',
    color: '#757575',
    label: __( 'Cancelled', 'mission-donation-platform' ),
  },
  failed: {
    backgroundColor: '#f0f0f0',
    color: '#757575',
    label: __( 'Failed', 'mission-donation-platform' ),
  },
};

function StatusBadge( { status } ) {
  const style = STATUS_STYLES[ status ] || STATUS_STYLES.pending;
  return (
    <span
      style={ {
        display: 'inline-block',
        padding: '2px 8px',
        borderRadius: '2px',
        fontSize: '12px',
        fontWeight: 500,
        backgroundColor: style.backgroundColor,
        color: style.color,
      } }
    >
      { style.label }
    </span>
  );
}

function TypeBadge( { type } ) {
  const isRecurring = [ 'monthly', 'quarterly', 'annually' ].includes( type );
  const bg = isRecurring ? '#eafaf0' : '#f0f0f5';
  const color = isRecurring ? '#1a7338' : '#6b6b7b';
  const label = isRecurring
    ? __( 'Recurring', 'mission-donation-platform' )
    : __( 'One-time', 'mission-donation-platform' );

  return (
    <span
      style={ {
        display: 'inline-block',
        padding: '2px 8px',
        borderRadius: '2px',
        fontSize: '12px',
        fontWeight: 500,
        backgroundColor: bg,
        color,
      } }
    >
      { label }
    </span>
  );
}

function SkeletonBar( { width = '60%', height = '16px' } ) {
  return (
    <span
      className="mission-skeleton"
      style={ {
        display: 'block',
        width,
        height,
        borderRadius: '4px',
        background: '#e2e4e9',
      } }
    />
  );
}

const SKELETON_ROWS = Array.from( { length: 5 }, ( _, i ) => ( {
  id: `skeleton-${ i }`,
  _isSkeleton: true,
} ) );

const fields = [
  {
    id: 'donation',
    label: __( 'Donation', 'mission-donation-platform' ),
    enableSorting: false,
    enableHiding: false,
    render: ( { item } ) => {
      if ( item._isSkeleton ) {
        return <SkeletonBar width="55%" />;
      }
      const adminUrl = window.missiondpAdmin?.adminUrl || '';
      const detailUrl = `${ adminUrl }admin.php?page=mission-donation-platform-transactions&transaction_id=${ item.id }`;
      return (
        <a
          href={ detailUrl }
          className="mission-table-link"
          style={ {
            display: 'flex',
            alignItems: 'flex-start',
            gap: '10px',
            textDecoration: 'none',
          } }
        >
          <span
            style={ {
              color: '#9b9ba8',
              fontFamily: 'monospace',
              fontSize: '13px',
              lineHeight: '20px',
              flexShrink: 0,
            } }
          >
            { `#${ item.id }` }
          </span>
          <div>
            <div style={ { fontWeight: 500, color: '#1a1a2e' } }>
              { item.donor_name ||
                __( 'Anonymous', 'mission-donation-platform' ) }
            </div>
            { item.donor_email && (
              <div style={ { color: '#9b9ba8', fontSize: '12px' } }>
                { item.donor_email }
              </div>
            ) }
          </div>
        </a>
      );
    },
  },
  {
    id: 'amount',
    label: __( 'Amount', 'mission-donation-platform' ),
    enableSorting: true,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="50%" />
      ) : (
        <span style={ { fontWeight: 500 } }>
          { formatAmount( item.amount, item.currency ) }
        </span>
      ),
  },
  {
    id: 'date_created',
    label: __( 'Date', 'mission-donation-platform' ),
    enableSorting: true,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="60%" />
      ) : (
        <span style={ { color: '#9b9ba8', fontSize: '13px' } }>
          { formatDate( item.date_created ) }
        </span>
      ),
  },
  {
    id: 'type',
    label: __( 'Type', 'mission-donation-platform' ),
    enableSorting: false,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="55%" height="22px" />
      ) : (
        <TypeBadge type={ item.type } />
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
        <StatusBadge status={ item.status } />
      ),
    elements: [
      {
        value: 'pending',
        label: __( 'Pending', 'mission-donation-platform' ),
      },
      {
        value: 'completed',
        label: __( 'Completed', 'mission-donation-platform' ),
      },
      {
        value: 'refunded',
        label: __( 'Refunded', 'mission-donation-platform' ),
      },
      {
        value: 'cancelled',
        label: __( 'Cancelled', 'mission-donation-platform' ),
      },
      { value: 'failed', label: __( 'Failed', 'mission-donation-platform' ) },
    ],
    filterBy: {
      operators: [ 'is' ],
    },
  },
];

const DEFAULT_VIEW = {
  type: 'table',
  titleField: 'donation',
  fields: [ 'amount', 'date_created', 'type', 'status' ],
  search: '',
  filters: [],
  sort: { field: 'date_created', direction: 'desc' },
  page: 1,
  perPage: 10,
  layout: {},
};

const MILESTONE_LABELS = {
  created: {
    reached: __( 'Campaign created', 'mission-donation-platform' ),
    pending: __( 'Campaign created', 'mission-donation-platform' ),
  },
  'first-donation': {
    reached: __( 'First donation received', 'mission-donation-platform' ),
    pending: __( 'First donation received', 'mission-donation-platform' ),
  },
  '25-pct': {
    reached: __( '25% milestone reached', 'mission-donation-platform' ),
    pending: __( '25% milestone', 'mission-donation-platform' ),
  },
  '50-pct': {
    reached: __( '50% milestone reached', 'mission-donation-platform' ),
    pending: __( '50% milestone', 'mission-donation-platform' ),
  },
  '75-pct': {
    reached: __( '75% milestone reached', 'mission-donation-platform' ),
    pending: __( '75% milestone', 'mission-donation-platform' ),
  },
  '100-pct': {
    reached: __( '100% \u2014 Goal reached!', 'mission-donation-platform' ),
    pending: __( '100% \u2014 Goal reached!', 'mission-donation-platform' ),
  },
};

const THRESHOLD_PCTS = {
  '25-pct': 25,
  '50-pct': 50,
  '75-pct': 75,
  '100-pct': 100,
};

function formatGoalValue( value, goalType ) {
  if ( ( goalType || 'amount' ) === 'amount' ) {
    return formatAmount( value );
  }
  return value.toLocaleString();
}

function goalUnit( goalType ) {
  if ( goalType === 'donors' ) {
    return __( 'donors', 'mission-donation-platform' );
  }
  if ( goalType === 'donations' ) {
    return __( 'donations', 'mission-donation-platform' );
  }
  return __( 'raised', 'mission-donation-platform' );
}

function prepareMilestones( campaign ) {
  const raw = campaign.milestones || [];
  const goal = campaign.goal_amount || 0;
  const progress = campaign.goal_progress ?? campaign.total_raised ?? 0;
  const gType = campaign.goal_type || 'amount';

  return raw.map( ( m ) => {
    const labels = MILESTONE_LABELS[ m.id ] || { reached: m.id, pending: m.id };
    const milestone = {
      id: m.id,
      title: m.reached ? labels.reached : labels.pending,
      reached: m.reached,
      date: m.date ? formatDate( m.date ) : undefined,
    };

    const pctVal = THRESHOLD_PCTS[ m.id ];
    if ( pctVal && goal > 0 ) {
      const target = Math.round( goal * ( pctVal / 100 ) );
      if ( m.reached ) {
        milestone.detail =
          formatGoalValue( target, gType ) + ' ' + goalUnit( gType );
      } else {
        const remaining = Math.max( 0, target - progress );
        milestone.detail =
          formatGoalValue( remaining, gType ) +
          ' ' +
          __( 'more needed', 'mission-donation-platform' );
      }
    }

    return milestone;
  } );
}

export default function OverviewTab( { campaignId, campaign } ) {
  const [ data, setData ] = useState( [] );
  const [ totalItems, setTotalItems ] = useState( 0 );
  const [ totalPages, setTotalPages ] = useState( 0 );
  const [ isLoading, setIsLoading ] = useState( true );
  const { view, setView } = usePersistedView(
    `campaign-donations-${ campaignId }`,
    DEFAULT_VIEW
  );
  const fetchTransactions = useCallback( async () => {
    setIsLoading( true );
    const params = new URLSearchParams( {
      campaign_id: String( campaignId ),
      page: String( view.page ),
      per_page: String( view.perPage ),
      order: view.sort?.direction?.toUpperCase() || 'DESC',
      orderby: view.sort?.field || 'date_created',
    } );

    if ( view.search ) {
      params.set( 'search', view.search );
    }

    const statusFilter = view.filters?.find( ( f ) => f.field === 'status' );
    if ( statusFilter?.value ) {
      params.set( 'status', statusFilter.value );
    }

    try {
      const response = await apiFetch( {
        path: `/mission-donation-platform/v1/transactions?${ params.toString() }`,
        parse: false,
      } );
      setTotalItems(
        parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 )
      );
      setTotalPages(
        parseInt( response.headers.get( 'X-WP-TotalPages' ) || '0', 10 )
      );
      const items = await response.json();
      setData( items );
    } catch {
      setData( [] );
      setTotalItems( 0 );
      setTotalPages( 0 );
    } finally {
      setIsLoading( false );
    }
  }, [
    campaignId,
    view.page,
    view.perPage,
    view.sort,
    view.filters,
    view.search,
  ] );

  useEffect( () => {
    fetchTransactions();
  }, [ fetchTransactions ] );

  const hasGoal = campaign.goal_amount > 0;
  const milestones = hasGoal ? prepareMilestones( campaign ) : [];

  return (
    <div className="mission-tab-panel">
      <div
        className={ `mission-campaign-detail-grid${
          ! hasGoal ? ' mission-campaign-detail-grid--full' : ''
        }` }
      >
        <div className="mission-card" style={ { padding: 0 } }>
          { ! isLoading && data.length === 0 ? (
            <>
              <h2 className="mission-card__heading">
                { __( 'Donations', 'mission-donation-platform' ) }
              </h2>
              <EmptyState
                icon={ <TableIcon /> }
                text={ __( 'No donations yet', 'mission-donation-platform' ) }
                hint={ __(
                  'Donations to this campaign will appear here as they come in',
                  'mission-donation-platform'
                ) }
              />
            </>
          ) : (
            <ClickableRows>
              <DataViews
                data={ isLoading ? SKELETON_ROWS : data }
                fields={ fields }
                view={ view }
                onChangeView={ setView }
                actions={ [] }
                paginationInfo={ {
                  totalItems: isLoading ? 0 : totalItems,
                  totalPages: isLoading ? 0 : totalPages,
                } }
                defaultLayouts={ { table: {} } }
              />
            </ClickableRows>
          ) }
        </div>

        { hasGoal && (
          <div className="mission-card" style={ { padding: 0 } }>
            <h2 className="mission-card__heading">
              { __( 'Milestones', 'mission-donation-platform' ) }
            </h2>
            <div className="mission-timeline">
              { milestones.map( ( milestone ) => (
                <div
                  key={ milestone.id }
                  className={ `mission-timeline__item${
                    milestone.reached ? ' is-reached' : ''
                  }` }
                >
                  <div
                    className={ `mission-timeline__dot${
                      milestone.reached ? ' is-reached' : ''
                    }` }
                  />
                  <div className="mission-timeline__title">
                    { milestone.title }
                  </div>
                  { milestone.date && (
                    <div className="mission-timeline__date">
                      { milestone.date }
                    </div>
                  ) }
                  { milestone.detail && (
                    <div className="mission-timeline__detail">
                      { milestone.detail }
                    </div>
                  ) }
                </div>
              ) ) }
            </div>
          </div>
        ) }
      </div>
    </div>
  );
}
