import { useState, useEffect, useCallback } from '@wordpress/element';
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
import { external, pencil, trash } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import CampaignCreateModal from './CampaignCreateModal';
import { formatAmount } from '@shared/currency';
import { formatDateOnly } from '@shared/date';
import { usePersistedView } from '@shared/hooks/use-persisted-view';
import EmptyState from '../../components/EmptyState';

const MegaphoneIcon = () => (
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
    <path d="M36 12L18 20H10a2 2 0 00-2 2v4a2 2 0 002 2h8l18 8V12z" />
    <path d="M18 28v8a2 2 0 002 2h2a2 2 0 002-2v-6" />
    <path d="M40 18c1.5 1.5 1.5 4.5 0 6" />
  </svg>
);

export { formatAmount };

const BRAND_COLOR = '#2FA36B';

const STATUS_STYLES = {
  active: {
    backgroundColor: 'rgba(47, 163, 107, 0.12)',
    color: '#278f5c',
    label: __( 'Active', 'missionwp-donation-platform' ),
  },
  scheduled: {
    backgroundColor: '#e4eff5',
    color: '#4a7a9b',
    label: __( 'Scheduled', 'missionwp-donation-platform' ),
  },
  ended: {
    backgroundColor: '#f0eeeb',
    color: '#8a7e72',
    label: __( 'Ended', 'missionwp-donation-platform' ),
  },
};

/**
 * Status badge component.
 *
 * @param {Object} props
 * @param {string} props.status Campaign status.
 * @return {JSX.Element} Badge element.
 */
export function StatusBadge( { status } ) {
  const style = STATUS_STYLES[ status ] || STATUS_STYLES.active;
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

function StatCard( { label, value, subtitle, isLoading: loading, className } ) {
  const classes = [ 'mission-stat-card', className ]
    .filter( Boolean )
    .join( ' ' );
  return (
    <Card className={ classes }>
      <CardBody size="none">
        <div className="mission-stat-card__label">{ label }</div>
        <div className="mission-stat-card__value">
          { loading ? <span className="mission-skeleton">&nbsp;</span> : value }
        </div>
        { subtitle && ! loading && (
          <div className="mission-stat-card__subtitle">{ subtitle }</div>
        ) }
        { loading && (
          <div className="mission-stat-card__subtitle">
            <span className="mission-skeleton">&nbsp;</span>
          </div>
        ) }
      </CardBody>
    </Card>
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

const SKELETON_ROWS = Array.from( { length: 10 }, ( _, i ) => ( {
  id: `skeleton-${ i }`,
  _isSkeleton: true,
} ) );

const adminUrl = window.missionAdmin?.adminUrl || '';

function campaignDetailUrl( id ) {
  return `${ adminUrl }admin.php?page=mission-campaigns&campaign=${ id }`;
}

const fields = [
  {
    id: 'title',
    label: __( 'Campaign', 'missionwp-donation-platform' ),
    enableGlobalSearch: true,
    enableSorting: true,
    enableHiding: false,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="55%" height="16px" />
      ) : (
        <a href={ campaignDetailUrl( item.id ) } className="mission-table-link">
          { item.title }
        </a>
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
      { value: 'active', label: __( 'Active', 'missionwp-donation-platform' ) },
      {
        value: 'scheduled',
        label: __( 'Scheduled', 'missionwp-donation-platform' ),
      },
      { value: 'ended', label: __( 'Ended', 'missionwp-donation-platform' ) },
    ],
    filterBy: {
      operators: [ 'is' ],
    },
  },
  {
    id: 'total_raised',
    label: __( 'Raised', 'missionwp-donation-platform' ),
    enableSorting: true,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="50%" height="16px" />
      ) : (
        <Text
          style={ { textAlign: 'right', display: 'block', fontWeight: 500 } }
        >
          { formatAmount( item.total_raised ) }
        </Text>
      ),
  },
  {
    id: 'goal_amount',
    label: __( 'Goal', 'missionwp-donation-platform' ),
    enableSorting: true,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="50%" height="16px" />
      ) : (
        <Text
          style={ { textAlign: 'right', display: 'block', fontWeight: 500 } }
        >
          { ( () => {
            if ( ! item.goal_amount ) {
              return '\u2014';
            }
            if ( ( item.goal_type || 'amount' ) === 'amount' ) {
              return formatAmount( item.goal_amount );
            }
            const unit = item.goal_type === 'donors' ? 'donors' : 'donations';
            return `${ item.goal_amount.toLocaleString() } ${ unit }`;
          } )() }
        </Text>
      ),
  },
  {
    id: 'progress',
    label: __( 'Progress', 'missionwp-donation-platform' ),
    enableSorting: false,
    enableHiding: true,
    render: ( { item } ) => {
      if ( item._isSkeleton ) {
        return <SkeletonBar width="80px" height="16px" />;
      }
      if ( ! item.goal_amount ) {
        return <Text style={ { color: '#9b9ba8' } }>{ '\u2014' }</Text>;
      }
      const progress = item.goal_progress ?? item.total_raised;
      const pct = Math.min(
        Math.round( ( progress / item.goal_amount ) * 100 ),
        100
      );
      return (
        <span className="mission-progress-bar">
          <span className="mission-progress-bar__track">
            <span
              className="mission-progress-bar__fill"
              style={ { width: `${ pct }%` } }
            />
          </span>
          <span className="mission-progress-bar__text">{ pct }%</span>
        </span>
      );
    },
  },
  {
    id: 'transaction_count',
    label: __( 'Transactions', 'missionwp-donation-platform' ),
    enableSorting: true,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="40%" height="16px" />
      ) : (
        <Text style={ { textAlign: 'right', display: 'block' } }>
          { item.transaction_count.toLocaleString() }
        </Text>
      ),
  },
  {
    id: 'date_start',
    label: __( 'Starts', 'missionwp-donation-platform' ),
    enableSorting: true,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="60%" height="16px" />
      ) : (
        <Text size="small" style={ { color: '#9b9ba8' } }>
          { item.date_start ? formatDateOnly( item.date_start ) : '\u2014' }
        </Text>
      ),
  },
  {
    id: 'date_end',
    label: __( 'Ends', 'missionwp-donation-platform' ),
    enableSorting: true,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="60%" height="16px" />
      ) : (
        <Text size="small" style={ { color: '#9b9ba8' } }>
          { item.date_end ? formatDateOnly( item.date_end ) : '\u221E' }
        </Text>
      ),
  },
];

const DEFAULT_VIEW = {
  type: 'table',
  titleField: 'title',
  fields: [
    'status',
    'total_raised',
    'goal_amount',
    'progress',
    'date_start',
    'date_end',
  ],
  search: '',
  filters: [],
  sort: {},
  page: 1,
  perPage: 25,
  layout: {
    styles: {
      total_raised: { width: '120px' },
      goal_amount: { width: '120px' },
      progress: { width: '120px' },
      transaction_count: { width: '120px' },
      date_start: { width: '120px' },
      date_end: { width: '120px' },
      status: { width: '100px' },
    },
  },
};

function campaignStatusSubtitle( summary ) {
  if ( summary.total_campaigns <= 1 ) {
    return undefined;
  }

  const parts = [
    [ summary.active, __( 'active', 'missionwp-donation-platform' ) ],
    [ summary.ended, __( 'ended', 'missionwp-donation-platform' ) ],
    [ summary.scheduled, __( 'scheduled', 'missionwp-donation-platform' ) ],
  ].filter( ( [ count ] ) => count > 0 );

  if ( parts.length === 1 ) {
    return `${ __( 'All', 'missionwp-donation-platform' ) } ${
      parts[ 0 ][ 1 ]
    }`;
  }

  return parts
    .map( ( [ count, label ] ) => `${ count } ${ label }` )
    .join( ' \u00B7 ' );
}

export default function CampaignList() {
  const [ data, setData ] = useState( [] );
  const { view, setView, isModified, resetToDefault } = usePersistedView(
    'campaigns',
    DEFAULT_VIEW
  );
  const [ totalItems, setTotalItems ] = useState( 0 );
  const [ totalPages, setTotalPages ] = useState( 0 );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ showCreateModal, setShowCreateModal ] = useState( false );
  const [ summary, setSummary ] = useState( null );

  const fetchSummary = useCallback( () => {
    apiFetch( { path: '/mission/v1/campaigns/summary' } )
      .then( setSummary )
      .catch( () => {} );
  }, [] );

  useEffect( () => {
    fetchSummary();
  }, [ fetchSummary ] );

  const fetchCampaigns = useCallback( async () => {
    setIsLoading( true );

    const params = new URLSearchParams( {
      page: String( view.page ),
      per_page: String( view.perPage ),
      order: view.sort?.direction?.toUpperCase() || 'DESC',
      orderby: view.sort?.field || 'date',
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
        path: `/mission/v1/campaigns?${ params.toString() }`,
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
  }, [ view.page, view.perPage, view.sort, view.search, view.filters ] );

  useEffect( () => {
    fetchCampaigns();
  }, [ fetchCampaigns ] );

  const actions = [
    {
      id: 'edit',
      label: __( 'Edit', 'missionwp-donation-platform' ),
      icon: pencil,
      callback: ( items ) => {
        window.location.href = `${ window.missionAdmin.adminUrl }admin.php?page=mission-campaigns&campaign=${ items[ 0 ].id }`;
      },
    },
    {
      id: 'view',
      label: __( 'View', 'missionwp-donation-platform' ),
      icon: external,
      isEligible: ( item ) => !! item.url,
      callback: ( items ) => {
        window.open( items[ 0 ].url, '_blank', 'noopener,noreferrer' );
      },
    },
    {
      id: 'delete',
      label: __( 'Delete', 'missionwp-donation-platform' ),
      icon: trash,
      isDestructive: true,
      RenderModal: ( { items, closeModal } ) => {
        return (
          <VStack spacing={ 4 }>
            <Text>
              { __(
                'Are you sure you want to delete this campaign?',
                'missionwp-donation-platform'
              ) }
            </Text>
            <Text variant="muted">{ items[ 0 ].title }</Text>
            <Text>
              { __(
                'This action cannot be undone.',
                'missionwp-donation-platform'
              ) }
            </Text>
            <HStack justify="flex-end">
              <Button
                variant="tertiary"
                onClick={ closeModal }
                __next40pxDefaultSize
              >
                { __( 'Cancel', 'missionwp-donation-platform' ) }
              </Button>
              <Button
                variant="primary"
                isDestructive
                __next40pxDefaultSize
                onClick={ async () => {
                  await apiFetch( {
                    path: `/mission/v1/campaigns/${ items[ 0 ].id }`,
                    method: 'DELETE',
                  } );
                  closeModal();
                  fetchCampaigns();
                } }
              >
                { __( 'Delete', 'missionwp-donation-platform' ) }
              </Button>
            </HStack>
          </VStack>
        );
      },
    },
  ];

  const hasNoSearchOrFilters =
    ! view.search && ( ! view.filters || view.filters.length === 0 );
  const showEmptyState =
    ! isLoading && data.length === 0 && hasNoSearchOrFilters;

  const onCreated = ( id ) => {
    window.location.href = campaignDetailUrl( id );
  };

  const summaryCards = (
    <div className="mission-stats-row mission-stats-row--4">
      <StatCard
        label={ __( 'Total Campaigns', 'missionwp-donation-platform' ) }
        value={ summary ? summary.total_campaigns.toLocaleString() : '' }
        subtitle={ summary ? campaignStatusSubtitle( summary ) : undefined }
        isLoading={ ! summary }
      />
      <StatCard
        label={ __( 'Total Raised', 'missionwp-donation-platform' ) }
        value={ summary ? formatAmount( summary.total_raised ) : '' }
        isLoading={ ! summary }
      />
      <StatCard
        label={ __( 'Avg. per Campaign', 'missionwp-donation-platform' ) }
        value={ summary ? formatAmount( summary.average_per_campaign ) : '' }
        subtitle={ __(
          'Across active campaigns',
          'missionwp-donation-platform'
        ) }
        isLoading={ ! summary }
      />
      <StatCard
        className="mission-stat-card--text-value"
        label={ __( 'Top Campaign', 'missionwp-donation-platform' ) }
        value={ summary ? summary.top_campaign_name || '\u2014' : '' }
        subtitle={
          summary?.top_campaign_raised
            ? `${ formatAmount( summary.top_campaign_raised ) } ${ __(
                'raised',
                'missionwp-donation-platform'
              ) }`
            : undefined
        }
        isLoading={ ! summary }
      />
    </div>
  );

  if ( showEmptyState ) {
    return (
      <div className="mission-admin-page">
        <VStack spacing={ 6 }>
          <HStack justify="space-between" alignment="center">
            <VStack spacing={ 1 }>
              <Heading level={ 1 }>
                { __( 'Campaigns', 'missionwp-donation-platform' ) }
              </Heading>
              <Text variant="muted">
                { __(
                  'Create and manage your fundraising campaigns.',
                  'missionwp-donation-platform'
                ) }
              </Text>
            </VStack>
          </HStack>

          { summaryCards }

          <Card>
            <CardBody>
              <EmptyState
                icon={ <MegaphoneIcon /> }
                text={ __( 'No campaigns yet', 'missionwp-donation-platform' ) }
                hint={ __(
                  'Create your first campaign to start accepting donations.',
                  'missionwp-donation-platform'
                ) }
                action={
                  <Button
                    variant="primary"
                    style={ {
                      backgroundColor: BRAND_COLOR,
                      borderColor: BRAND_COLOR,
                    } }
                    onClick={ () => setShowCreateModal( true ) }
                    __next40pxDefaultSize
                  >
                    { __( 'Create a Campaign', 'missionwp-donation-platform' ) }
                  </Button>
                }
              />
            </CardBody>
          </Card>
        </VStack>

        { showCreateModal && (
          <CampaignCreateModal
            onClose={ () => setShowCreateModal( false ) }
            onCreated={ onCreated }
          />
        ) }
      </div>
    );
  }

  return (
    <div className="mission-admin-page">
      <VStack spacing={ 6 }>
        <HStack justify="space-between" alignment="center">
          <VStack spacing={ 1 }>
            <Heading level={ 1 }>
              { __( 'Campaigns', 'missionwp-donation-platform' ) }
            </Heading>
            <Text variant="muted">
              { __(
                'Create and manage your fundraising campaigns.',
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
            onClick={ () => setShowCreateModal( true ) }
            __next40pxDefaultSize
          >
            { __( 'Add Campaign', 'missionwp-donation-platform' ) }
          </Button>
        </HStack>

        { summaryCards }

        <ClickableRows>
          <DataViews
            data={ isLoading ? SKELETON_ROWS : data }
            fields={ fields }
            view={ view }
            onChangeView={ setView }
            onReset={ isModified ? resetToDefault : false }
            actions={ isLoading ? [] : actions }
            paginationInfo={ {
              totalItems: isLoading ? 0 : totalItems,
              totalPages: isLoading ? 0 : totalPages,
            } }
            defaultLayouts={ { table: {} } }
          />
        </ClickableRows>
      </VStack>

      { showCreateModal && (
        <CampaignCreateModal
          onClose={ () => setShowCreateModal( false ) }
          onCreated={ onCreated }
        />
      ) }
    </div>
  );
}
