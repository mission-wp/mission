import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import LogsFilterBar from './LogsFilterBar';
import LogEntry from './LogEntry';
import ConfirmationModal from './ConfirmationModal';

const PER_PAGE = 25;

export default function LogsPanel() {
  const [ entries, setEntries ] = useState( [] );
  const [ total, setTotal ] = useState( 0 );
  const [ page, setPage ] = useState( 1 );
  const [ hasMore, setHasMore ] = useState( false );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ isLoadingMore, setIsLoadingMore ] = useState( false );
  const [ expandedId, setExpandedId ] = useState( null );
  const [ showClearModal, setShowClearModal ] = useState( false );
  const [ isClearing, setIsClearing ] = useState( false );
  const [ filters, setFilters ] = useState( {
    level: '',
    category: '',
    search: '',
  } );

  const fetchEntries = useCallback(
    async ( pageNum = 1, append = false ) => {
      if ( pageNum === 1 ) {
        setIsLoading( true );
      } else {
        setIsLoadingMore( true );
      }

      const params = new URLSearchParams( {
        page: pageNum,
        per_page: PER_PAGE,
      } );

      if ( filters.level ) {
        params.set( 'level', filters.level );
      }
      if ( filters.category ) {
        params.set( 'category', filters.category );
      }
      if ( filters.search ) {
        params.set( 'search', filters.search );
      }

      try {
        const response = await apiFetch( {
          path: `/mission/v1/activity?${ params }`,
          parse: false,
        } );

        const totalCount = parseInt( response.headers.get( 'X-WP-Total' ), 10 );
        const totalPages = parseInt(
          response.headers.get( 'X-WP-TotalPages' ),
          10
        );
        const data = await response.json();

        setTotal( totalCount );
        setHasMore( pageNum < totalPages );
        setEntries( ( prev ) => ( append ? [ ...prev, ...data ] : data ) );
      } catch {
        // Silently fail — entries stay as they were.
      } finally {
        setIsLoading( false );
        setIsLoadingMore( false );
      }
    },
    [ filters ]
  );

  // Fetch on mount and when filters change.
  useEffect( () => {
    setPage( 1 );
    setExpandedId( null );
    fetchEntries( 1 );
  }, [ fetchEntries ] );

  const handleLoadMore = () => {
    const nextPage = page + 1;
    setPage( nextPage );
    fetchEntries( nextPage, true );
  };

  const handleFilterChange = ( update ) => {
    setFilters( ( prev ) => ( { ...prev, ...update } ) );
  };

  const handleClear = async () => {
    setIsClearing( true );
    try {
      await apiFetch( {
        path: '/mission/v1/activity',
        method: 'DELETE',
      } );
      setEntries( [] );
      setTotal( 0 );
      setHasMore( false );
      setPage( 1 );
      setExpandedId( null );
    } catch {
      // Silently fail.
    } finally {
      setIsClearing( false );
      setShowClearModal( false );
    }
  };

  const handleToggle = ( id ) => {
    setExpandedId( ( prev ) => ( prev === id ? null : id ) );
  };

  // Skeleton loading rows.
  if ( isLoading ) {
    return (
      <div className="mission-settings-panel">
        <div className="mission-settings-card">
          <div className="mission-logs-filters">
            <div className="mission-logs-filters__left">
              <div
                className="mission-skeleton"
                style={ { width: 130, height: 36, borderRadius: 6 } }
              />
              <div
                className="mission-skeleton"
                style={ { width: 150, height: 36, borderRadius: 6 } }
              />
              <div
                className="mission-skeleton"
                style={ { width: 200, height: 36, borderRadius: 6 } }
              />
            </div>
          </div>
        </div>
        <div className="mission-settings-card" style={ { padding: 0 } }>
          { Array.from( { length: 8 } ).map( ( _, i ) => (
            <div className="mission-logs-entry is-skeleton" key={ i }>
              <span
                className="mission-skeleton"
                style={ {
                  width: 8,
                  height: 8,
                  borderRadius: '50%',
                  flexShrink: 0,
                } }
              />
              <span className="mission-logs-body">
                <span
                  className="mission-skeleton"
                  style={ {
                    width: `${ 50 + Math.random() * 40 }%`,
                    height: 14,
                    borderRadius: 4,
                  } }
                />
                <span className="mission-logs-meta">
                  <span
                    className="mission-skeleton"
                    style={ { width: 120, height: 12, borderRadius: 4 } }
                  />
                  <span
                    className="mission-skeleton"
                    style={ { width: 60, height: 18, borderRadius: 9 } }
                  />
                </span>
              </span>
            </div>
          ) ) }
        </div>
      </div>
    );
  }

  return (
    <div className="mission-settings-panel">
      <div className="mission-settings-card">
        <LogsFilterBar
          filters={ filters }
          onFilterChange={ handleFilterChange }
          total={ total }
          onClear={ () => setShowClearModal( true ) }
          isClearDisabled={ total === 0 }
        />
      </div>

      <div className="mission-settings-card" style={ { padding: 0 } }>
        { entries.length === 0 ? (
          <div className="mission-logs-empty">
            { filters.level || filters.category || filters.search
              ? __( 'No log entries match your filters.', 'mission' )
              : __( 'No log entries yet.', 'mission' ) }
          </div>
        ) : (
          <div className="mission-logs-list">
            { entries.map( ( entry ) => (
              <LogEntry
                key={ entry.id }
                entry={ entry }
                isExpanded={ expandedId === entry.id }
                onToggle={ () => handleToggle( entry.id ) }
              />
            ) ) }
          </div>
        ) }

        { hasMore && (
          <div className="mission-logs-load-more">
            <button
              type="button"
              className="mission-logs-load-more__btn"
              onClick={ handleLoadMore }
              disabled={ isLoadingMore }
            >
              { isLoadingMore
                ? __( 'Loading…', 'mission' )
                : sprintf(
                    // translators: %s: number of remaining log entries.
                    __( 'Load more (%s remaining)', 'mission' ),
                    ( total - entries.length ).toLocaleString()
                  ) }
            </button>
          </div>
        ) }
      </div>

      { showClearModal && (
        <ConfirmationModal
          title={ __( 'Clear all logs', 'mission' ) }
          message={ sprintf(
            // translators: %s: total number of log entries to delete.
            __( 'This will permanently delete all %s log entries.', 'mission' ),
            total.toLocaleString()
          ) }
          confirmLabel={ __( 'Clear logs', 'mission' ) }
          isRunning={ isClearing }
          onConfirm={ handleClear }
          onCancel={ () => setShowClearModal( false ) }
        />
      ) }
    </div>
  );
}
