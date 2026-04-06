import { useState, useCallback, useRef, useMemo } from '@wordpress/element';

const PERSISTED_KEYS = [ 'type', 'fields', 'perPage', 'sort' ];
const STORAGE_PREFIX = 'mission_view_';

function getPersistedFields( view ) {
  const persisted = {};
  for ( const key of PERSISTED_KEYS ) {
    if ( view[ key ] !== undefined ) {
      persisted[ key ] = view[ key ];
    }
  }
  if ( view.layout?.density !== undefined ) {
    persisted.density = view.layout.density;
  }
  return persisted;
}

function loadFromStorage( storageKey ) {
  try {
    const raw = localStorage.getItem( STORAGE_PREFIX + storageKey );
    if ( raw ) {
      return JSON.parse( raw );
    }
  } catch {
    // Ignore corrupted data.
  }
  return null;
}

function buildInitialView( defaultView, stored ) {
  if ( ! stored ) {
    return defaultView;
  }

  const view = { ...defaultView };

  for ( const key of PERSISTED_KEYS ) {
    if ( stored[ key ] !== undefined ) {
      view[ key ] = stored[ key ];
    }
  }

  if ( stored.density !== undefined ) {
    view.layout = { ...view.layout, density: stored.density };
  }

  return view;
}

export function usePersistedView( storageKey, defaultView ) {
  const [ view, setViewState ] = useState( () =>
    buildInitialView( defaultView, loadFromStorage( storageKey ) )
  );
  const timerRef = useRef( null );

  const setView = useCallback(
    ( nextView ) => {
      const resolved =
        typeof nextView === 'function' ? nextView( view ) : nextView;
      setViewState( resolved );

      clearTimeout( timerRef.current );
      timerRef.current = setTimeout( () => {
        const persisted = getPersistedFields( resolved );
        try {
          localStorage.setItem(
            STORAGE_PREFIX + storageKey,
            JSON.stringify( persisted )
          );
        } catch {
          // Storage full — silently ignore.
        }
      }, 300 );
    },
    [ storageKey, view ]
  );

  const isModified = useMemo( () => {
    const currentPersisted = getPersistedFields( view );
    const defaultPersisted = getPersistedFields( defaultView );
    return (
      JSON.stringify( currentPersisted ) !== JSON.stringify( defaultPersisted )
    );
  }, [ view, defaultView ] );

  const resetToDefault = useCallback( () => {
    localStorage.removeItem( STORAGE_PREFIX + storageKey );
    setViewState( {
      ...defaultView,
      search: view.search,
      filters: view.filters,
      page: view.page,
    } );
  }, [ storageKey, defaultView, view.search, view.filters, view.page ] );

  return { view, setView, isModified, resetToDefault };
}
