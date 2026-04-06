import { useState, useRef, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { LEVEL_OPTIONS, CATEGORY_OPTIONS } from './logs-utils';

const searchIcon = (
  <svg
    width="14"
    height="14"
    viewBox="0 0 16 16"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.8"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <circle cx="7" cy="7" r="5" />
    <path d="M16 16l-3.5-3.5" />
  </svg>
);

export default function LogsFilterBar( {
  filters,
  onFilterChange,
  total,
  onClear,
  isClearDisabled,
} ) {
  const [ searchValue, setSearchValue ] = useState( filters.search || '' );
  const debounceRef = useRef( null );

  // Debounce search input.
  useEffect( () => {
    clearTimeout( debounceRef.current );
    debounceRef.current = setTimeout( () => {
      if ( searchValue !== filters.search ) {
        onFilterChange( { search: searchValue } );
      }
    }, 300 );
    return () => clearTimeout( debounceRef.current );
  }, [ searchValue ] ); // eslint-disable-line react-hooks/exhaustive-deps

  const handleSelect = ( key ) => ( e ) => {
    onFilterChange( { [ key ]: e.target.value } );
  };

  const countText =
    total === 1
      ? __( '1 entry', 'mission' )
      : // translators: %s: number of log entries.
        sprintf( __( '%s entries', 'mission' ), total.toLocaleString() );

  return (
    <div className="mission-logs-filters">
      <div className="mission-logs-filters__left">
        <select
          className="mission-logs-select"
          value={ filters.level || '' }
          onChange={ handleSelect( 'level' ) }
        >
          { LEVEL_OPTIONS.map( ( opt ) => (
            <option key={ opt.value } value={ opt.value }>
              { opt.label }
            </option>
          ) ) }
        </select>

        <select
          className="mission-logs-select"
          value={ filters.category || '' }
          onChange={ handleSelect( 'category' ) }
        >
          { CATEGORY_OPTIONS.map( ( opt ) => (
            <option key={ opt.value } value={ opt.value }>
              { opt.label }
            </option>
          ) ) }
        </select>

        <div className="mission-logs-search">
          { searchIcon }
          <input
            type="text"
            className="mission-logs-search__input"
            placeholder={ __( 'Search logs…', 'mission' ) }
            value={ searchValue }
            onChange={ ( e ) => setSearchValue( e.target.value ) }
          />
        </div>
      </div>

      <div className="mission-logs-filters__right">
        <span className="mission-logs-count">{ countText }</span>
        <button
          type="button"
          className="mission-logs-clear-btn"
          onClick={ onClear }
          disabled={ isClearDisabled }
        >
          { __( 'Clear logs', 'mission' ) }
        </button>
      </div>
    </div>
  );
}
