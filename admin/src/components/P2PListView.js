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
import ClickableRows from '@shared/components/ClickableRows';
import EmptyState from './EmptyState';
import Toast from './Toast';

const SKELETON_ROWS = Array.from( { length: 10 }, ( _, i ) => ( {
  id: `skeleton-${ i }`,
  _isSkeleton: true,
} ) );

/**
 * Shared scaffold for the Fundraisers and Teams admin list pages.
 *
 * Owns the persisted view, paginated fetch, summary fetch, and the P2P
 * campaign filter elements. Rows link through to the detail pages, where
 * approve/deactivate live. Each page supplies its columns (via buildFields)
 * and stat cards (via renderStats).
 *
 * @param {Object}      props
 * @param {string}      props.title          Page heading.
 * @param {string}      props.description    Sub-heading copy.
 * @param {string}      props.listPath       REST collection path.
 * @param {string}      props.summaryPath    REST summary path.
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
  const { data, totalItems, totalPages, isLoading, error } = usePaginatedFetch(
    { path: listPath, view, filterFields }
  );
  const [ summary, setSummary ] = useState( null );
  const [ campaignElements, setCampaignElements ] = useState( [] );
  const [ teamElements, setTeamElements ] = useState( [] );
  const [ toast, setToast ] = useState( null );
  const [ toastKey, setToastKey ] = useState( 0 );

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

  // Surface list-fetch failures so an error doesn't read as "no results".
  useEffect( () => {
    if ( error ) {
      showToast(
        'error',
        __( 'The list could not be loaded.', 'mission-donation-platform' )
      );
    }
  }, [ error, showToast ] );

  // Deleting the last record on the final page (via a detail page) can
  // strand the view past the new total; clamp back once totals arrive.
  useEffect( () => {
    if ( ! isLoading && totalPages > 0 && view.page > totalPages ) {
      setView( { ...view, page: totalPages } );
    }
  }, [ isLoading, totalPages, view, setView ] );

  // Prefetch P2P campaigns for the campaign filter dropdown. Deliberately
  // capped at the first 100 (newest first) — beyond that the dropdown becomes
  // unusable anyway and the list is still reachable via search.
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
          <ClickableRows>
            <DataViews
              data={ isLoading ? SKELETON_ROWS : data }
              fields={ fields }
              view={ view }
              onChangeView={ setView }
              onReset={ isModified ? resetToDefault : false }
              getItemId={ ( item ) => String( item.id ) }
              paginationInfo={ {
                totalItems: isLoading ? 0 : totalItems,
                totalPages: isLoading ? 0 : totalPages,
              } }
              defaultLayouts={ { table: {} } }
            />
          </ClickableRows>
        ) }
      </VStack>

      <Toast
        key={ toastKey }
        notice={ toast }
        onDone={ () => setToast( null ) }
      />
    </div>
  );
}
