import { useState, useEffect, useCallback } from '@wordpress/element';
import { formatDate } from '@shared/date';
import ClickableRows from '@shared/components/ClickableRows';
import {
  Card,
  CardBody,
  __experimentalHeading as Heading,
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { usePersistedView } from '@shared/hooks/use-persisted-view';
import EmptyState from '../../components/EmptyState';

const RecurringIcon = () => (
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
    <path d="M36 18c-1.5-5.5-6.5-9-12.5-9C16 9 10 15 10 22.5" />
    <path d="M36 10v8h-8" />
    <path d="M12 30c1.5 5.5 6.5 9 12.5 9C32 39 38 33 38 25.5" />
    <path d="M12 38v-8h8" />
  </svg>
);

const STATUS_LABELS = {
  active: __( 'Active', 'mission-donation-platform' ),
  pending: __( 'Pending', 'mission-donation-platform' ),
  paused: __( 'Paused', 'mission-donation-platform' ),
  cancelled: __( 'Cancelled', 'mission-donation-platform' ),
  past_due: __( 'Past Due', 'mission-donation-platform' ),
};

const FREQUENCY_SUFFIXES = {
  weekly: '/wk',
  monthly: '/mo',
  quarterly: '/qrt',
  annually: '/yr',
};

function getDelta( current, previous ) {
  if ( ! previous ) {
    return { value: 0, direction: 'neutral' };
  }
  const pct = ( ( current - previous ) / previous ) * 100;
  const rounded = Math.abs( Math.round( pct * 10 ) / 10 );
  if ( pct > 0 ) {
    return { value: rounded, direction: 'positive' };
  }
  if ( pct < 0 ) {
    return { value: rounded, direction: 'negative' };
  }
  return { value: 0, direction: 'neutral' };
}

const ArrowUp = () => (
  <svg
    width="12"
    height="12"
    viewBox="0 0 12 12"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <polyline points="2,8 6,3 10,8" />
  </svg>
);

const ArrowDown = () => (
  <svg
    width="12"
    height="12"
    viewBox="0 0 12 12"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <polyline points="2,4 6,9 10,4" />
  </svg>
);

function SkeletonBar( { width = '60%', height = '24px' } ) {
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

function StatCard( { label, value, delta, subtitle, isLoading: loading } ) {
  return (
    <Card className="mission-stat-card">
      <CardBody size="none">
        <div className="mission-stat-card__label">{ label }</div>
        <div className="mission-stat-card__value">
          { loading ? <span className="mission-skeleton">&nbsp;</span> : value }
        </div>
        { loading && (
          <div className="mission-stat-card__delta">
            <span className="mission-skeleton">&nbsp;</span>
          </div>
        ) }
        { ! loading && delta && (
          <div className={ `mission-stat-card__delta is-${ delta.direction }` }>
            { delta.direction === 'positive' && <ArrowUp /> }
            { delta.direction === 'negative' && <ArrowDown /> }
            <span>
              { delta.value }% { delta.label }
            </span>
          </div>
        ) }
        { ! loading && ! delta && subtitle && (
          <div className="mission-stat-card__subtitle">{ subtitle }</div>
        ) }
      </CardBody>
    </Card>
  );
}

const SKELETON_ROWS = Array.from( { length: 10 }, ( _, i ) => ( {
  id: `skeleton-${ i }`,
  _isSkeleton: true,
} ) );

function buildFields() {
  return [
    {
      id: 'subscription',
      label: __( 'Subscription', 'mission-donation-platform' ),
      enableSorting: false,
      enableHiding: false,
      render: ( { item } ) => {
        if ( item._isSkeleton ) {
          return <SkeletonBar width="55%" height="16px" />;
        }
        const adminUrl = window.missiondpAdmin?.adminUrl || '';
        const detailUrl = `${ adminUrl }admin.php?page=mission-donation-platform-subscriptions&subscription_id=${ item.id }`;
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
                { item.donor_name }
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
          <SkeletonBar width="50%" height="16px" />
        ) : (
          <span style={ { fontWeight: 500 } }>
            { formatAmount( item.amount, item.currency ) }
            <span className="mission-frequency-suffix">
              { FREQUENCY_SUFFIXES[ item.frequency ] || '' }
            </span>
          </span>
        ),
    },
    {
      id: 'campaign_title',
      label: __( 'Campaign', 'mission-donation-platform' ),
      enableSorting: false,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="55%" height="16px" />
        ) : (
          <span style={ { color: '#6b6b7b', fontSize: '13px' } }>
            { item.campaign_title || '\u2014' }
          </span>
        ),
    },
    {
      id: 'date_created',
      label: __( 'Started', 'mission-donation-platform' ),
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
    {
      id: 'date_next_renewal',
      label: __( 'Next Renewal', 'mission-donation-platform' ),
      enableSorting: true,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="60%" height="16px" />
        ) : (
          <span style={ { color: '#9b9ba8', fontSize: '13px' } }>
            { item.date_next_renewal
              ? formatDate( item.date_next_renewal )
              : '\u2014' }
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
          <span className={ `mission-status-badge is-${ item.status }` }>
            { STATUS_LABELS[ item.status ] || item.status }
          </span>
        ),
      elements: [
        {
          value: 'active',
          label: __( 'Active', 'mission-donation-platform' ),
        },
        {
          value: 'pending',
          label: __( 'Pending', 'mission-donation-platform' ),
        },
        {
          value: 'paused',
          label: __( 'Paused', 'mission-donation-platform' ),
        },
        {
          value: 'cancelled',
          label: __( 'Cancelled', 'mission-donation-platform' ),
        },
        {
          value: 'past_due',
          label: __( 'Past Due', 'mission-donation-platform' ),
        },
      ],
      filterBy: {
        operators: [ 'is' ],
      },
    },
  ];
}

const DEFAULT_VIEW = {
  type: 'table',
  titleField: 'subscription',
  fields: [
    'amount',
    'campaign_title',
    'date_created',
    'date_next_renewal',
    'status',
  ],
  search: '',
  filters: [],
  sort: {
    field: 'date_created',
    direction: 'desc',
  },
  page: 1,
  perPage: 25,
  layout: {},
};

export default function SubscriptionList() {
  const [ data, setData ] = useState( [] );
  const { view, setView, isModified, resetToDefault } = usePersistedView(
    'subscriptions',
    DEFAULT_VIEW
  );
  const [ totalItems, setTotalItems ] = useState( 0 );
  const [ totalPages, setTotalPages ] = useState( 0 );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ summary, setSummary ] = useState( null );

  const fetchSummary = useCallback( () => {
    apiFetch( { path: '/mission-donation-platform/v1/subscriptions/summary' } )
      .then( setSummary )
      .catch( () => {} );
  }, [] );

  useEffect( () => {
    fetchSummary();
  }, [ fetchSummary ] );

  const fetchSubscriptions = useCallback( async () => {
    setIsLoading( true );

    const params = new URLSearchParams( {
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
        path: `/mission-donation-platform/v1/subscriptions?${ params.toString() }`,
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
  }, [ view.page, view.perPage, view.sort, view.filters, view.search ] );

  useEffect( () => {
    fetchSubscriptions();
  }, [ fetchSubscriptions ] );

  const fields = buildFields();

  const mrrDelta = summary
    ? {
        ...getDelta( summary.mrr, summary.previous_mrr ),
        label: __( 'vs last month', 'mission-donation-platform' ),
      }
    : null;

  const hasNoFilters = ! view.filters || view.filters.length === 0;
  const showEmptyState = ! isLoading && data.length === 0 && hasNoFilters;

  if ( showEmptyState ) {
    return (
      <div className="mission-admin-page">
        <VStack spacing={ 6 }>
          <VStack spacing={ 1 }>
            <Heading level={ 1 }>
              { __( 'Subscriptions', 'mission-donation-platform' ) }
            </Heading>
            <Text variant="muted">
              { __(
                'Manage recurring donations.',
                'mission-donation-platform'
              ) }
            </Text>
          </VStack>

          <div className="mission-stats-row mission-stats-row--4">
            <StatCard
              label={ __( 'Monthly Recurring', 'mission-donation-platform' ) }
              value={ formatAmount( 0 ) }
            />
            <StatCard
              label={ __(
                'Active Subscriptions',
                'mission-donation-platform'
              ) }
              value="0"
            />
            <StatCard
              label={ __( 'Avg. Subscription', 'mission-donation-platform' ) }
              value={ formatAmount( 0 ) }
            />
            <StatCard
              label={ __( 'Churned', 'mission-donation-platform' ) }
              value="0"
            />
          </div>

          <Card>
            <CardBody>
              <EmptyState
                icon={ <RecurringIcon /> }
                text={ __(
                  'No subscriptions yet',
                  'mission-donation-platform'
                ) }
                hint={ __(
                  'Recurring donations will appear here once donors subscribe.',
                  'mission-donation-platform'
                ) }
              />
            </CardBody>
          </Card>
        </VStack>
      </div>
    );
  }

  return (
    <div className="mission-admin-page">
      <VStack spacing={ 6 }>
        <VStack spacing={ 1 }>
          <Heading level={ 1 }>
            { __( 'Subscriptions', 'mission-donation-platform' ) }
          </Heading>
          <Text variant="muted">
            { __( 'Manage recurring donations.', 'mission-donation-platform' ) }
          </Text>
        </VStack>

        <div className="mission-stats-row mission-stats-row--4">
          <StatCard
            label={ __( 'Monthly Recurring', 'mission-donation-platform' ) }
            value={ summary ? formatAmount( summary.mrr ) : '' }
            delta={ mrrDelta }
            isLoading={ ! summary }
          />
          <StatCard
            label={ __( 'Active Subscriptions', 'mission-donation-platform' ) }
            value={ summary ? String( summary.active ) : '' }
            subtitle={
              summary
                ? `${ summary.new_this_month } ${ __(
                    'new this month',
                    'mission-donation-platform'
                  ) }`
                : ''
            }
            isLoading={ ! summary }
          />
          <StatCard
            label={ __( 'Avg. Subscription', 'mission-donation-platform' ) }
            value={ summary ? formatAmount( summary.average_monthly ) : '' }
            subtitle={ __( 'Per month', 'mission-donation-platform' ) }
            isLoading={ ! summary }
          />
          <StatCard
            label={ __( 'Churned', 'mission-donation-platform' ) }
            value={ summary ? String( summary.churned ) : '' }
            subtitle={
              summary
                ? `${ formatAmount( summary.churned_mrr ) }${ __(
                    '/mo lost',
                    'mission-donation-platform'
                  ) }`
                : ''
            }
            isLoading={ ! summary }
          />
        </div>

        <ClickableRows>
          <DataViews
            data={ isLoading ? SKELETON_ROWS : data }
            fields={ fields }
            view={ view }
            onChangeView={ setView }
            onReset={ isModified ? resetToDefault : false }
            paginationInfo={ {
              totalItems: isLoading ? 0 : totalItems,
              totalPages: isLoading ? 0 : totalPages,
            } }
            defaultLayouts={ { table: {} } }
          />
        </ClickableRows>
      </VStack>
    </div>
  );
}
