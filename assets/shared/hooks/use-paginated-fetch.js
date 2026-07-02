import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Fetch a paginated REST collection driven by a DataViews view.
 *
 * Builds the page/per_page/order/orderby/search query from the view, copies
 * any of `filterFields` present in view.filters to same-named query params,
 * and reads the totals from the X-WP-Total / X-WP-TotalPages headers.
 *
 * Each refresh aborts the previous in-flight request so rapid view changes
 * can't paint stale out-of-order responses. A failed fetch resets the data
 * and exposes the error so callers can tell "no results" from "failed".
 *
 * @param {Object}   options
 * @param {string}   options.path             REST path without a query string.
 * @param {Object}   options.view             DataViews view (from usePersistedView).
 * @param {string}   [options.defaultOrderby] Orderby used when the view has no sort field.
 * @param {string[]} [options.filterFields]   view.filters fields to copy to query params.
 * @param {Object}   [options.extraParams]    Fixed query params (e.g. { campaign_id }).
 * @return {Object} { data, totalItems, totalPages, isLoading, error, refresh }.
 */
export function usePaginatedFetch( {
  path,
  view,
  defaultOrderby = 'date_created',
  filterFields = [],
  extraParams = {},
} ) {
  const [ data, setData ] = useState( [] );
  const [ totalItems, setTotalItems ] = useState( 0 );
  const [ totalPages, setTotalPages ] = useState( 0 );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ error, setError ] = useState( null );
  const abortRef = useRef( null );

  // Depend on stable strings so inline object/array literals at the call
  // site don't retrigger the fetch on every render.
  const extraParamsString = new URLSearchParams( extraParams ).toString();
  const filterFieldsString = filterFields.join( ',' );

  const refresh = useCallback( async () => {
    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;

    setIsLoading( true );

    const params = new URLSearchParams( extraParamsString );
    params.set( 'page', String( view.page ) );
    params.set( 'per_page', String( view.perPage ) );
    params.set( 'order', view.sort?.direction?.toUpperCase() || 'DESC' );
    params.set( 'orderby', view.sort?.field || defaultOrderby );

    if ( view.search ) {
      params.set( 'search', view.search );
    }

    for ( const field of filterFieldsString.split( ',' ).filter( Boolean ) ) {
      const filter = view.filters?.find( ( f ) => f.field === field );
      if ( filter?.value ) {
        params.set( field, filter.value );
      }
    }

    try {
      const response = await apiFetch( {
        path: `${ path }?${ params.toString() }`,
        parse: false,
        signal: controller.signal,
      } );

      // A superseded request must not paint, even if it still resolved.
      if ( controller.signal.aborted ) {
        return;
      }

      setTotalItems(
        parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 )
      );
      setTotalPages(
        parseInt( response.headers.get( 'X-WP-TotalPages' ) || '0', 10 )
      );

      const items = await response.json();

      if ( controller.signal.aborted ) {
        return;
      }

      setData( items );
      setError( null );
    } catch ( err ) {
      // An aborted request was superseded; the newer one owns the state.
      if ( controller.signal.aborted ) {
        return;
      }
      setData( [] );
      setTotalItems( 0 );
      setTotalPages( 0 );
      setError( err );
    } finally {
      if ( ! controller.signal.aborted ) {
        setIsLoading( false );
      }
    }
  }, [
    path,
    defaultOrderby,
    extraParamsString,
    filterFieldsString,
    view.page,
    view.perPage,
    view.sort,
    view.search,
    view.filters,
  ] );

  useEffect( () => {
    refresh();

    return () => abortRef.current?.abort();
  }, [ refresh ] );

  return { data, totalItems, totalPages, isLoading, error, refresh };
}
