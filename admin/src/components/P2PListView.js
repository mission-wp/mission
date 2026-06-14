import { useState, useEffect, useCallback } from '@wordpress/element';
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
import { usePersistedView } from '@shared/hooks/use-persisted-view';
import { usePaginatedFetch } from '@shared/hooks/use-paginated-fetch';
import EmptyState from './EmptyState';

const SKELETON_ROWS = Array.from( { length: 10 }, ( _, i ) => ( {
  id: `skeleton-${ i }`,
  _isSkeleton: true,
} ) );

/**
 * Shared scaffold for the Fundraisers and Teams admin list pages.
 *
 * Owns the persisted view, paginated fetch, summary fetch, the P2P campaign
 * filter elements, and the row + bulk approve/deactivate actions. Each page
 * supplies its columns (via buildFields) and stat cards (via renderStats).
 *
 * @param {Object}      props
 * @param {string}      props.title        Page heading.
 * @param {string}      props.description  Sub-heading copy.
 * @param {string}      props.listPath     REST collection path.
 * @param {string}      props.summaryPath  REST summary path.
 * @param {string}      props.bulkPath     REST bulk-action path.
 * @param {string}      props.storageKey   usePersistedView storage key.
 * @param {Function}    props.buildFields  (campaignElements) => DataViews fields.
 * @param {Object}      props.defaultView  Default DataViews view.
 * @param {string[]}    props.filterFields view.filters fields to pass as query params.
 * @param {Function}    props.renderStats  (summary) => stat card nodes.
 * @param {JSX.Element} props.emptyIcon    Empty-state icon.
 * @param {string}      props.emptyText    Empty-state heading.
 * @param {string}      props.emptyHint    Empty-state hint.
 * @return {JSX.Element} The list page.
 */
export default function P2PListView( {
  title,
  description,
  listPath,
  summaryPath,
  bulkPath,
  storageKey,
  buildFields,
  defaultView,
  filterFields = [],
  renderStats,
  emptyIcon,
  emptyText,
  emptyHint,
} ) {
  const { view, setView, isModified, resetToDefault } = usePersistedView(
    storageKey,
    defaultView
  );
  const { data, totalItems, totalPages, isLoading, refresh } =
    usePaginatedFetch( { path: listPath, view, filterFields } );
  const [ summary, setSummary ] = useState( null );
  const [ selection, setSelection ] = useState( [] );
  const [ campaignElements, setCampaignElements ] = useState( [] );

  const fetchSummary = useCallback( () => {
    apiFetch( { path: summaryPath } )
      .then( setSummary )
      .catch( () => {} );
  }, [ summaryPath ] );

  useEffect( () => {
    fetchSummary();
  }, [ fetchSummary ] );

  // Prefetch P2P campaigns for the campaign filter dropdown.
  useEffect( () => {
    apiFetch( {
      path: '/mission-donation-platform/v1/campaigns?per_page=100&type=p2p',
    } )
      .then( ( items ) =>
        setCampaignElements(
          ( items || [] ).map( ( c ) => ( {
            value: String( c.id ),
            label: c.title,
          } ) )
        )
      )
      .catch( () => {} );
  }, [] );

  const runBulk = useCallback(
    async ( action, items ) => {
      const ids = items.map( ( item ) => item.id );
      try {
        await apiFetch( {
          path: bulkPath,
          method: 'POST',
          data: { action, ids },
        } );
      } finally {
        setSelection( [] );
        refresh();
        fetchSummary();
      }
    },
    [ bulkPath, refresh, fetchSummary ]
  );

  const actions = [
    {
      id: 'approve',
      label: __( 'Approve', 'mission-donation-platform' ),
      isPrimary: true,
      supportsBulk: true,
      isEligible: ( item ) => ! item._isSkeleton && item.status !== 'active',
      callback: ( items ) => runBulk( 'approve', items ),
    },
    {
      id: 'deactivate',
      label: __( 'Deactivate', 'mission-donation-platform' ),
      supportsBulk: true,
      isEligible: ( item ) => ! item._isSkeleton && item.status !== 'inactive',
      callback: ( items ) => runBulk( 'deactivate', items ),
    },
  ];

  const fields = buildFields( campaignElements );

  const hasNoFilters = ! view.filters || view.filters.length === 0;
  const showEmptyState =
    ! isLoading && data.length === 0 && hasNoFilters && ! view.search;

  return (
    <div className="mission-admin-page">
      <VStack spacing={ 6 }>
        <VStack spacing={ 1 }>
          <Heading level={ 1 }>{ title }</Heading>
          <Text variant="muted">{ description }</Text>
        </VStack>

        <div className="mission-stats-row mission-stats-row--4">
          { renderStats( summary ) }
        </div>

        { showEmptyState ? (
          <Card>
            <CardBody>
              <EmptyState
                icon={ emptyIcon }
                text={ emptyText }
                hint={ emptyHint }
              />
            </CardBody>
          </Card>
        ) : (
          <DataViews
            data={ isLoading ? SKELETON_ROWS : data }
            fields={ fields }
            view={ view }
            onChangeView={ setView }
            onReset={ isModified ? resetToDefault : false }
            actions={ actions }
            selection={ selection }
            onChangeSelection={ setSelection }
            getItemId={ ( item ) => String( item.id ) }
            isItemClickable={ () => false }
            paginationInfo={ {
              totalItems: isLoading ? 0 : totalItems,
              totalPages: isLoading ? 0 : totalPages,
            } }
            defaultLayouts={ { table: {} } }
          />
        ) }
      </VStack>
    </div>
  );
}
