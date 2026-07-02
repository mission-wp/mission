import { useState, useEffect, useCallback } from '@wordpress/element';
import {
  Button,
  Card,
  CardBody,
  Modal,
  __experimentalHeading as Heading,
  __experimentalVStack as VStack,
  __experimentalHStack as HStack,
  __experimentalText as Text,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { usePersistedView } from '@shared/hooks/use-persisted-view';
import { usePaginatedFetch } from '@shared/hooks/use-paginated-fetch';
import EmptyState from './EmptyState';
import Toast from './Toast';

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
 * @param {string}      props.title          Page heading.
 * @param {string}      props.description    Sub-heading copy.
 * @param {string}      props.listPath       REST collection path.
 * @param {string}      props.summaryPath    REST summary path.
 * @param {string}      props.bulkPath       REST bulk-action path.
 * @param {string}      props.storageKey     usePersistedView storage key.
 * @param {Function}    props.buildFields    (campaignElements, teamElements) => DataViews fields.
 * @param {Object}      props.defaultView    Default DataViews view.
 * @param {string[]}    props.filterFields   view.filters fields to pass as query params.
 * @param {boolean}     props.withTeamFilter Whether to prefetch teams for a team filter.
 * @param {Function}    props.renderStats    (summary) => stat card nodes.
 * @param {JSX.Element} props.emptyIcon      Empty-state icon.
 * @param {string}      props.emptyText      Empty-state heading.
 * @param {string}      props.emptyHint      Empty-state hint.
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
  withTeamFilter = false,
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
  const [ teamElements, setTeamElements ] = useState( [] );
  const [ toast, setToast ] = useState( null );
  const [ toastKey, setToastKey ] = useState( 0 );
  const [ confirmItems, setConfirmItems ] = useState( null );

  const showToast = useCallback( ( type, message ) => {
    setToast( { type, message } );
    setToastKey( ( key ) => key + 1 );
  }, [] );

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

  // Prefetch teams for the team filter dropdown (Fundraisers page only).
  useEffect( () => {
    if ( ! withTeamFilter ) {
      return;
    }
    apiFetch( { path: '/mission-donation-platform/v1/teams?per_page=100' } )
      .then( ( items ) =>
        setTeamElements(
          ( items || [] ).map( ( t ) => ( {
            value: String( t.id ),
            label: t.name,
          } ) )
        )
      )
      .catch( () => {} );
  }, [ withTeamFilter ] );

  const runBulk = useCallback(
    async ( action, items ) => {
      const ids = items.map( ( item ) => item.id );
      try {
        const result = await apiFetch( {
          path: bulkPath,
          method: 'POST',
          data: { action, ids },
        } );

        const updated = Array.isArray( result?.updated )
          ? result.updated.length
          : ids.length;
        const failed = ( result?.errors || [] ).length;

        if ( failed ) {
          showToast(
            'error',
            sprintf(
              /* translators: 1: number updated, 2: number failed */
              __( '%1$d updated, %2$d failed.', 'mission-donation-platform' ),
              updated,
              failed
            )
          );
        } else {
          showToast(
            'success',
            sprintf(
              /* translators: %d: number of records updated */
              __( '%d updated.', 'mission-donation-platform' ),
              updated
            )
          );
        }
      } catch ( error ) {
        showToast(
          'error',
          error?.message ||
            __( 'The bulk action failed.', 'mission-donation-platform' )
        );
      } finally {
        setSelection( [] );
        refresh();
        fetchSummary();
      }
    },
    [ bulkPath, refresh, fetchSummary, showToast ]
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
      callback: ( items ) => setConfirmItems( items ),
    },
  ];

  const fields = buildFields( campaignElements, teamElements );

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

      { confirmItems && (
        <Modal
          title={ __( 'Deactivate?', 'mission-donation-platform' ) }
          onRequestClose={ () => setConfirmItems( null ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { sprintf(
                /* translators: %d: number of selected records */
                __(
                  'Deactivating hides the selected pages from the site. %d selected.',
                  'mission-donation-platform'
                ),
                confirmItems.length
              ) }
            </Text>
            <HStack justify="flex-end">
              <Button
                variant="tertiary"
                onClick={ () => setConfirmItems( null ) }
              >
                { __( 'Cancel', 'mission-donation-platform' ) }
              </Button>
              <Button
                variant="primary"
                isDestructive
                onClick={ () => {
                  runBulk( 'deactivate', confirmItems );
                  setConfirmItems( null );
                } }
              >
                { __( 'Deactivate', 'mission-donation-platform' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }

      <Toast
        key={ toastKey }
        notice={ toast }
        onDone={ () => setToast( null ) }
      />
    </div>
  );
}
