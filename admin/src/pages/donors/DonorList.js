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
import DonorAvatar from '../../components/DonorAvatar';
import EmptyState from '../../components/EmptyState';
import AddDonorDrawer from './AddDonorDrawer';

const PeopleIcon = () => (
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
    <circle cx="18" cy="16" r="6" />
    <path d="M8 38c0-5.523 4.477-10 10-10s10 4.477 10 10" />
    <circle cx="34" cy="18" r="5" />
    <path d="M32 28c4.418 0 8 3.582 8 8" />
  </svg>
);

const BRAND_COLOR = '#2FA36B';

function SkeletonBar( { width = '60%', height = '24px' } ) {
  return (
    <span
      className="mission-skeleton"
      style={ {
        display: 'block',
        width,
        height,
        borderRadius: '4px',
        background: '#eee9e3',
      } }
    />
  );
}

function StatCard( { label, value, subtitle, isLoading: loading } ) {
  return (
    <Card className="mission-stat-card">
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

const SKELETON_ROWS = Array.from( { length: 10 }, ( _, i ) => ( {
  id: `skeleton-${ i }`,
  _isSkeleton: true,
} ) );

const fields = [
  {
    id: 'donor',
    label: __( 'Donor', 'missionwp-donation-platform' ),
    enableSorting: false,
    enableHiding: false,
    render: ( { item } ) => {
      if ( item._isSkeleton ) {
        return <SkeletonBar width="55%" height="16px" />;
      }
      const name = [ item.first_name, item.last_name ]
        .filter( Boolean )
        .join( ' ' );
      const adminUrl = window.missionAdmin?.adminUrl || '';
      const detailUrl = `${ adminUrl }admin.php?page=mission-donors&donor_id=${ item.id }`;
      return (
        <div className="mission-donor-cell">
          <DonorAvatar
            firstName={ item.first_name }
            lastName={ item.last_name }
            gravatarHash={ item.gravatar_hash }
          />
          <a
            href={ detailUrl }
            style={ {
              color: 'inherit',
              textDecoration: 'none',
              fontWeight: 500,
            } }
          >
            { name || __( 'Anonymous', 'missionwp-donation-platform' ) }
          </a>
        </div>
      );
    },
  },
  {
    id: 'email',
    label: __( 'Email', 'missionwp-donation-platform' ),
    enableSorting: false,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="65%" height="16px" />
      ) : (
        <Text style={ { color: '#9b9ba8' } }>{ item.email || '\u2014' }</Text>
      ),
  },
  {
    id: 'total_donated',
    label: __( 'Total Donated', 'missionwp-donation-platform' ),
    enableSorting: true,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="50%" height="16px" />
      ) : (
        <Text
          style={ { textAlign: 'right', display: 'block', fontWeight: 500 } }
        >
          { formatAmount( item.total_donated ) }
        </Text>
      ),
  },
  {
    id: 'transaction_count',
    label: __( 'Donations', 'missionwp-donation-platform' ),
    enableSorting: true,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="40%" height="16px" />
      ) : (
        <Text>{ item.transaction_count }</Text>
      ),
  },
  {
    id: 'last_transaction',
    label: __( 'Last Donation', 'missionwp-donation-platform' ),
    enableSorting: true,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="60%" height="16px" />
      ) : (
        <Text variant="muted" size="small">
          { formatDate( item.last_transaction ) }
        </Text>
      ),
  },
  {
    id: 'date_created',
    label: __( 'Date Added', 'missionwp-donation-platform' ),
    enableSorting: true,
    render: ( { item } ) =>
      item._isSkeleton ? (
        <SkeletonBar width="60%" height="16px" />
      ) : (
        <Text variant="muted" size="small">
          { formatDate( item.date_created ) }
        </Text>
      ),
  },
];

const DEFAULT_VIEW = {
  type: 'table',
  titleField: 'donor',
  fields: [
    'email',
    'total_donated',
    'transaction_count',
    'last_transaction',
    'date_created',
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

export default function DonorList() {
  const [ data, setData ] = useState( [] );
  const { view, setView, isModified, resetToDefault } = usePersistedView(
    'donors',
    DEFAULT_VIEW
  );
  const [ totalItems, setTotalItems ] = useState( 0 );
  const [ totalPages, setTotalPages ] = useState( 0 );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ summary, setSummary ] = useState( null );
  const [ showDrawer, setShowDrawer ] = useState( false );

  const fetchSummary = useCallback( () => {
    apiFetch( { path: '/mission/v1/donors/summary' } )
      .then( setSummary )
      .catch( () => {} );
  }, [] );

  useEffect( () => {
    fetchSummary();
  }, [ fetchSummary ] );

  const fetchDonors = useCallback( async () => {
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

    try {
      const response = await apiFetch( {
        path: `/mission/v1/donors?${ params.toString() }`,
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
  }, [ view.page, view.perPage, view.sort, view.search ] );

  useEffect( () => {
    fetchDonors();
  }, [ fetchDonors ] );

  const hasNoFilters = ! view.filters || view.filters.length === 0;
  const showEmptyState =
    ! isLoading && data.length === 0 && hasNoFilters && ! view.search;

  if ( showEmptyState ) {
    return (
      <div className="mission-admin-page">
        <VStack spacing={ 6 }>
          <HStack justify="space-between" alignment="center">
            <VStack spacing={ 1 }>
              <Heading level={ 1 }>
                { __( 'Donors', 'missionwp-donation-platform' ) }
              </Heading>
              <Text variant="muted">
                { __(
                  'View and manage all donors.',
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
              { __( 'Add Donor', 'missionwp-donation-platform' ) }
            </Button>
          </HStack>

          <div className="mission-stats-row mission-stats-row--4">
            <StatCard
              label={ __( 'Total Donors', 'missionwp-donation-platform' ) }
              value="0"
            />
            <StatCard
              label={ __( 'Top Donor', 'missionwp-donation-platform' ) }
              value={ '\u2014' }
            />
            <StatCard
              label={ __( 'Average Donated', 'missionwp-donation-platform' ) }
              value={ formatAmount( 0 ) }
            />
            <StatCard
              label={ __( 'Repeat Donors', 'missionwp-donation-platform' ) }
              value="0"
            />
          </div>

          <Card>
            <CardBody>
              <EmptyState
                icon={ <PeopleIcon /> }
                text={ __( 'No donors yet.', 'missionwp-donation-platform' ) }
                hint={ __(
                  'Donors will appear here once people start giving.',
                  'missionwp-donation-platform'
                ) }
              />
            </CardBody>
          </Card>
        </VStack>

        <AddDonorDrawer
          isOpen={ showDrawer }
          onClose={ () => setShowDrawer( false ) }
          onCreated={ () => {
            setShowDrawer( false );
            fetchDonors();
            fetchSummary();
          } }
        />
      </div>
    );
  }

  return (
    <div className="mission-admin-page">
      <VStack spacing={ 6 }>
        <HStack justify="space-between" alignment="center">
          <VStack spacing={ 1 }>
            <Heading level={ 1 }>
              { __( 'Donors', 'missionwp-donation-platform' ) }
            </Heading>
            <Text variant="muted">
              { __(
                'View and manage all donors.',
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
            { __( 'Add Donor', 'missionwp-donation-platform' ) }
          </Button>
        </HStack>

        <div className="mission-stats-row mission-stats-row--4">
          <StatCard
            label={ __( 'Total Donors', 'missionwp-donation-platform' ) }
            value={ summary ? summary.total_donors.toLocaleString() : '' }
            isLoading={ ! summary }
          />
          <StatCard
            label={ __( 'Top Donor', 'missionwp-donation-platform' ) }
            value={ summary ? summary.top_donor_name || '\u2014' : '' }
            subtitle={
              summary?.top_donor_total
                ? `${ formatAmount( summary.top_donor_total ) } ${ __(
                    'lifetime',
                    'missionwp-donation-platform'
                  ) }`
                : undefined
            }
            isLoading={ ! summary }
          />
          <StatCard
            label={ __( 'Average Donated', 'missionwp-donation-platform' ) }
            value={ summary ? formatAmount( summary.average_donated ) : '' }
            isLoading={ ! summary }
          />
          <StatCard
            label={ __( 'Repeat Donors', 'missionwp-donation-platform' ) }
            value={ summary ? summary.repeat_donors.toLocaleString() : '' }
            subtitle={
              summary?.total_donors > 0
                ? `${ (
                    ( summary.repeat_donors / summary.total_donors ) *
                    100
                  ).toFixed( 1 ) }% ${ __(
                    'of all donors',
                    'missionwp-donation-platform'
                  ) }`
                : undefined
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

      <AddDonorDrawer
        isOpen={ showDrawer }
        onClose={ () => setShowDrawer( false ) }
        onCreated={ () => {
          setShowDrawer( false );
          fetchDonors();
          fetchSummary();
        } }
      />
    </div>
  );
}
