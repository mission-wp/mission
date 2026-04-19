import { useState, useEffect, useCallback } from '@wordpress/element';
import { formatDate } from '@shared/date';
import ClickableRows from '@shared/components/ClickableRows';
import {
  Button,
  Card,
  CardBody,
  __experimentalHeading as Heading,
  __experimentalHStack as HStack,
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { usePersistedView } from '@shared/hooks/use-persisted-view';
import EmptyState from '../../components/EmptyState';
import AddDonationDrawer from './AddDonationDrawer';

const ReceiptIcon = () => (
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
    <path d="M12 6l3 3 3-3 3 3 3-3 3 3 3-3 3 3 3-3v36l-3-3-3 3-3-3-3 3-3-3-3 3-3-3-3 3V6z" />
    <path d="M18 16h12M18 22h12M18 28h8" />
  </svg>
);

const BRAND_COLOR = '#2FA36B';

const STATUS_STYLES = {
  completed: {
    backgroundColor: '#eafaf0',
    color: '#1a7338',
    label: __( 'Completed', 'missionwp-donation-platform' ),
  },
  pending: {
    backgroundColor: '#fef3c7',
    color: '#92400e',
    label: __( 'Pending', 'missionwp-donation-platform' ),
  },
  refunded: {
    backgroundColor: '#fef2f2',
    color: '#dc2626',
    label: __( 'Refunded', 'missionwp-donation-platform' ),
  },
  cancelled: {
    backgroundColor: '#f0f0f0',
    color: '#757575',
    label: __( 'Cancelled', 'missionwp-donation-platform' ),
  },
  failed: {
    backgroundColor: '#f0f0f0',
    color: '#757575',
    label: __( 'Failed', 'missionwp-donation-platform' ),
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
    ? __( 'Recurring', 'missionwp-donation-platform' )
    : __( 'One-time', 'missionwp-donation-platform' );

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

function buildFields( campaignElements ) {
  return [
    {
      id: 'donation',
      label: __( 'Donation', 'missionwp-donation-platform' ),
      enableSorting: false,
      enableHiding: false,
      render: ( { item } ) => {
        if ( item._isSkeleton ) {
          return <SkeletonBar width="55%" height="16px" />;
        }
        const adminUrl = window.missionAdmin?.adminUrl || '';
        const detailUrl = `${ adminUrl }admin.php?page=mission-transactions&transaction_id=${ item.id }`;
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
      label: __( 'Amount', 'missionwp-donation-platform' ),
      enableSorting: true,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="50%" height="16px" />
        ) : (
          <span style={ { fontWeight: 500 } }>
            { formatAmount( item.amount, item.currency ) }
          </span>
        ),
    },
    {
      id: 'campaign_id',
      label: __( 'Campaign', 'missionwp-donation-platform' ),
      enableSorting: false,
      render: ( { item } ) =>
        item._isSkeleton ? (
          <SkeletonBar width="65%" height="16px" />
        ) : (
          <span>{ item.campaign_title || '\u2014' }</span>
        ),
      elements: campaignElements,
      filterBy: {
        operators: [ 'is' ],
      },
    },
    {
      id: 'date_created',
      label: __( 'Date', 'missionwp-donation-platform' ),
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
      id: 'type',
      label: __( 'Type', 'missionwp-donation-platform' ),
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
      label: __( 'Status', 'missionwp-donation-platform' ),
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
          label: __( 'Pending', 'missionwp-donation-platform' ),
        },
        {
          value: 'completed',
          label: __( 'Completed', 'missionwp-donation-platform' ),
        },
        {
          value: 'refunded',
          label: __( 'Refunded', 'missionwp-donation-platform' ),
        },
        {
          value: 'cancelled',
          label: __( 'Cancelled', 'missionwp-donation-platform' ),
        },
        {
          value: 'failed',
          label: __( 'Failed', 'missionwp-donation-platform' ),
        },
      ],
      filterBy: {
        operators: [ 'is' ],
      },
    },
    {
      id: 'dedication',
      label: __( 'Dedication', 'missionwp-donation-platform' ),
      enableSorting: false,
      enableHiding: false,
      render: () => null,
      elements: [
        {
          value: 'mail_pending',
          label: __( 'Mail \u2014 pending', 'missionwp-donation-platform' ),
        },
        {
          value: 'mail_sent',
          label: __( 'Mail \u2014 sent', 'missionwp-donation-platform' ),
        },
        {
          value: 'email_sent',
          label: __( 'Email \u2014 sent', 'missionwp-donation-platform' ),
        },
        {
          value: 'any',
          label: __( 'Has dedication', 'missionwp-donation-platform' ),
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
  titleField: 'donation',
  fields: [ 'amount', 'campaign_id', 'date_created', 'type', 'status' ],
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

function buildSummaryCards( summary ) {
  const revenueDelta = summary
    ? {
        ...getDelta( summary.total_revenue, summary.previous_revenue ),
        label: __( 'vs last month', 'missionwp-donation-platform' ),
      }
    : null;

  const donationsDelta = summary
    ? {
        ...getDelta( summary.total_donations, summary.previous_donations ),
        label: __( 'vs last month', 'missionwp-donation-platform' ),
      }
    : null;

  const refundPct =
    summary && summary.total_donations > 0
      ? ( ( summary.total_refunded / summary.total_donations ) * 100 ).toFixed(
          1
        )
      : '0';

  const refundSubtitle = summary
    ? `${ summary.total_refunded } ${
        summary.total_refunded === 1
          ? __( 'refund', 'missionwp-donation-platform' )
          : __( 'refunds', 'missionwp-donation-platform' )
      } (${ refundPct }%)`
    : '';

  return { revenueDelta, donationsDelta, refundSubtitle };
}

export default function TransactionList() {
  const [ data, setData ] = useState( [] );
  const { view, setView, isModified, resetToDefault } = usePersistedView(
    'transactions',
    DEFAULT_VIEW
  );
  const [ totalItems, setTotalItems ] = useState( 0 );
  const [ totalPages, setTotalPages ] = useState( 0 );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ summary, setSummary ] = useState( null );
  const [ campaignElements, setCampaignElements ] = useState( [] );
  const [ showDrawer, setShowDrawer ] = useState( false );
  const fetchSummary = useCallback( () => {
    apiFetch( { path: '/mission/v1/transactions/summary' } )
      .then( setSummary )
      .catch( () => {} );
  }, [] );

  // Fetch summary stats on mount.
  useEffect( () => {
    fetchSummary();
  }, [ fetchSummary ] );

  // Fetch campaign list for filter elements on mount.
  useEffect( () => {
    apiFetch( { path: '/mission/v1/campaigns?per_page=100' } )
      .then( ( campaigns ) => {
        setCampaignElements(
          campaigns.map( ( c ) => ( {
            value: String( c.id ),
            label: c.title,
          } ) )
        );
      } )
      .catch( () => {} );
  }, [] );

  const fetchTransactions = useCallback( async () => {
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

    const campaignFilter = view.filters?.find(
      ( f ) => f.field === 'campaign_id'
    );
    if ( campaignFilter?.value ) {
      params.set( 'campaign_id', campaignFilter.value );
    }

    const dedicationFilter = view.filters?.find(
      ( f ) => f.field === 'dedication'
    );
    if ( dedicationFilter?.value ) {
      params.set( 'dedication', dedicationFilter.value );
    }

    try {
      const response = await apiFetch( {
        path: `/mission/v1/transactions?${ params.toString() }`,
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
    fetchTransactions();
  }, [ fetchTransactions ] );

  const fields = buildFields( campaignElements );
  const { revenueDelta, donationsDelta, refundSubtitle } =
    buildSummaryCards( summary );

  const hasNoFilters = ! view.filters || view.filters.length === 0;
  const showEmptyState = ! isLoading && data.length === 0 && hasNoFilters;

  if ( showEmptyState ) {
    return (
      <div className="mission-admin-page">
        <VStack spacing={ 6 }>
          <HStack justify="space-between" alignment="center">
            <VStack spacing={ 1 }>
              <Heading level={ 1 }>
                { __( 'Transactions', 'missionwp-donation-platform' ) }
              </Heading>
              <Text variant="muted">
                { __(
                  'View and manage all donations.',
                  'missionwp-donation-platform'
                ) }
              </Text>
            </VStack>
          </HStack>

          <div className="mission-stats-row mission-stats-row--4">
            <StatCard
              label={ __( 'Total Revenue', 'missionwp-donation-platform' ) }
              value={ formatAmount( 0 ) }
            />
            <StatCard
              label={ __( 'Total Donations', 'missionwp-donation-platform' ) }
              value="0"
            />
            <StatCard
              label={ __( 'Average Donation', 'missionwp-donation-platform' ) }
              value={ formatAmount( 0 ) }
            />
            <StatCard
              label={ __( 'Refunds', 'missionwp-donation-platform' ) }
              value={ formatAmount( 0 ) }
            />
          </div>

          <Card>
            <CardBody>
              <EmptyState
                icon={ <ReceiptIcon /> }
                text={ __(
                  'No transactions yet',
                  'missionwp-donation-platform'
                ) }
                hint={ __(
                  'Transactions will appear here once donors start giving.',
                  'missionwp-donation-platform'
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
        <HStack justify="space-between" alignment="center">
          <VStack spacing={ 1 }>
            <Heading level={ 1 }>
              { __( 'Transactions', 'missionwp-donation-platform' ) }
            </Heading>
            <Text variant="muted">
              { __(
                'View and manage all donations.',
                'missionwp-donation-platform'
              ) }
            </Text>
          </VStack>
          <Button
            variant="primary"
            style={ {
              backgroundColor: BRAND_COLOR,
              borderColor: BRAND_COLOR,
            } }
            onClick={ () => setShowDrawer( true ) }
            __next40pxDefaultSize
          >
            { __( 'Add Donation', 'missionwp-donation-platform' ) }
          </Button>
        </HStack>

        <div className="mission-stats-row mission-stats-row--4">
          <StatCard
            label={ __( 'Total Revenue', 'missionwp-donation-platform' ) }
            value={ summary ? formatAmount( summary.total_revenue ) : '' }
            delta={ revenueDelta }
            isLoading={ ! summary }
          />
          <StatCard
            label={ __( 'Total Donations', 'missionwp-donation-platform' ) }
            value={ summary ? summary.total_donations.toLocaleString() : '' }
            delta={ donationsDelta }
            isLoading={ ! summary }
          />
          <StatCard
            label={ __( 'Average Donation', 'missionwp-donation-platform' ) }
            value={ summary ? formatAmount( summary.average_donation ) : '' }
            subtitle={ __(
              'Across all campaigns',
              'missionwp-donation-platform'
            ) }
            isLoading={ ! summary }
          />
          <StatCard
            label={ __( 'Refunds', 'missionwp-donation-platform' ) }
            value={
              summary ? formatAmount( summary.total_refunded_amount ) : ''
            }
            subtitle={ refundSubtitle }
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

      <AddDonationDrawer
        isOpen={ showDrawer }
        onClose={ () => setShowDrawer( false ) }
        onCreated={ () => {
          setShowDrawer( false );
          fetchTransactions();
          fetchSummary();
        } }
        campaigns={ campaignElements }
      />
    </div>
  );
}
