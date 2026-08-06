import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export default function WebhookModal( { webhook, onClose, onSave } ) {
  const isEditing = !! webhook;

  const [ url, setUrl ] = useState( webhook?.url ?? '' );
  const [ name, setName ] = useState( webhook?.name ?? '' );
  const [ selectedEvents, setSelectedEvents ] = useState(
    webhook?.events ?? []
  );
  const [ secret, setSecret ] = useState( webhook?.secret ?? '' );
  const [ availableEvents, setAvailableEvents ] = useState( null );
  const [ isSaving, setIsSaving ] = useState( false );
  const [ urlError, setUrlError ] = useState( false );
  const [ searchValue, setSearchValue ] = useState( '' );
  const [ dropdownOpen, setDropdownOpen ] = useState( false );
  const [ copied, setCopied ] = useState( false );
  const [ secretFlash, setSecretFlash ] = useState( false );

  const wrapRef = useRef();
  const searchRef = useRef();
  const urlRef = useRef();

  useEffect( () => {
    apiFetch( {
      path: '/mission-donation-platform/v1/outgoing-webhooks/events',
    } )
      .then( setAvailableEvents )
      .catch( () => setAvailableEvents( {} ) );
  }, [] );

  useEffect( () => {
    const handleKey = ( e ) => {
      if ( e.key === 'Escape' ) {
        onClose();
      }
    };
    document.addEventListener( 'keydown', handleKey );
    return () => document.removeEventListener( 'keydown', handleKey );
  }, [ onClose ] );

  useEffect( () => {
    if ( ! dropdownOpen ) {
      return;
    }
    const close = ( e ) => {
      if ( wrapRef.current && ! wrapRef.current.contains( e.target ) ) {
        setDropdownOpen( false );
      }
    };
    document.addEventListener( 'click', close, true );
    return () => document.removeEventListener( 'click', close, true );
  }, [ dropdownOpen ] );

  useEffect( () => {
    const timer = setTimeout( () => urlRef.current?.focus(), 100 );
    return () => clearTimeout( timer );
  }, [] );

  const allEvents = availableEvents
    ? Object.values( availableEvents ).flatMap( ( cat ) =>
        Object.entries( cat.events ).map( ( [ key, info ] ) => ( {
          key,
          label: info.label,
          group: cat.label,
        } ) )
      )
    : [];

  const filteredGroups = {};
  allEvents.forEach( ( evt ) => {
    if (
      searchValue &&
      ! evt.label.toLowerCase().includes( searchValue.toLowerCase() )
    ) {
      return;
    }
    if ( ! filteredGroups[ evt.group ] ) {
      filteredGroups[ evt.group ] = [];
    }
    filteredGroups[ evt.group ].push( evt );
  } );

  const allEventKeys = allEvents.map( ( e ) => e.key );
  const allSelected =
    allEventKeys.length > 0 &&
    allEventKeys.every( ( k ) => selectedEvents.includes( k ) );

  const handleSelectAll = () => {
    if ( allSelected ) {
      setSelectedEvents( [] );
    } else {
      setSelectedEvents( [ ...allEventKeys ] );
    }
    setSearchValue( '' );
    searchRef.current?.focus();
  };

  const handleToggleEvent = ( key ) => {
    setSelectedEvents( ( prev ) =>
      prev.includes( key )
        ? prev.filter( ( k ) => k !== key )
        : [ ...prev, key ]
    );
    setSearchValue( '' );
    searchRef.current?.focus();
  };

  const handleRemoveEvent = ( key ) => {
    setSelectedEvents( ( prev ) => prev.filter( ( k ) => k !== key ) );
  };

  const handleSearchKeyDown = ( e ) => {
    if (
      e.key === 'Backspace' &&
      searchValue === '' &&
      selectedEvents.length > 0
    ) {
      setSelectedEvents( ( prev ) => prev.slice( 0, -1 ) );
    }
  };

  const handleCopySecret = async () => {
    try {
      await window.navigator.clipboard.writeText( secret );
      setCopied( true );
      setTimeout( () => setCopied( false ), 1500 );
    } catch {}
  };

  const handleRegenerateSecret = async () => {
    if ( ! isEditing ) {
      return;
    }
    try {
      const data = await apiFetch( {
        path: `/mission-donation-platform/v1/outgoing-webhooks/${ webhook.id }`,
        method: 'PATCH',
        data: { regenerate_secret: true },
      } );
      setSecret( data.secret );
      setSecretFlash( true );
      setTimeout( () => setSecretFlash( false ), 1500 );
    } catch {}
  };

  const handleSave = async () => {
    setUrlError( false );

    if ( ! url.trim() ) {
      setUrlError( true );
      urlRef.current?.focus();
      return;
    }

    setIsSaving( true );
    try {
      if ( isEditing ) {
        await apiFetch( {
          path: `/mission-donation-platform/v1/outgoing-webhooks/${ webhook.id }`,
          method: 'PATCH',
          data: { name, url, events: selectedEvents },
        } );
      } else {
        await apiFetch( {
          path: '/mission-donation-platform/v1/outgoing-webhooks',
          method: 'POST',
          data: { name: name || url, url, events: selectedEvents },
        } );
      }
      onSave();
    } catch {
      setUrlError( true );
    } finally {
      setIsSaving( false );
    }
  };

  return (
    <div className="mission-wh-modal-overlay">
      <div className="mission-wh-modal">
        <div className="mission-wh-modal-header">
          <h3 className="mission-wh-modal-title">
            { isEditing
              ? __( 'Edit Webhook', 'mission-donation-platform' )
              : __( 'Add Webhook', 'mission-donation-platform' ) }
          </h3>
          <button
            type="button"
            className="mission-wh-modal-close"
            onClick={ onClose }
          >
            <svg
              width="16"
              height="16"
              viewBox="0 0 16 16"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
            >
              <path d="M4 4l8 8M12 4l-8 8" />
            </svg>
          </button>
        </div>

        <div className="mission-wh-modal-body">
          { /* Endpoint URL */ }
          <div className="mission-wh-field">
            <label className="mission-wh-field-label" htmlFor="wh-url">
              { __( 'Endpoint URL', 'mission-donation-platform' ) }
            </label>
            <input
              ref={ urlRef }
              id="wh-url"
              type="url"
              className={ `mission-wh-field-input${
                urlError ? ' mission-wh-field-input--error' : ''
              }` }
              value={ url }
              onChange={ ( e ) => {
                setUrl( e.target.value );
                setUrlError( false );
              } }
              placeholder="https://example.com/webhook"
            />
            <p className="mission-wh-field-hint">
              { __(
                'The URL that will receive POST requests when events occur.',
                'mission-donation-platform'
              ) }
            </p>
          </div>

          { /* Description */ }
          <div className="mission-wh-field">
            <label className="mission-wh-field-label" htmlFor="wh-desc">
              { __( 'Description', 'mission-donation-platform' ) }
            </label>
            <input
              id="wh-desc"
              type="text"
              className="mission-wh-field-input"
              value={ name }
              onChange={ ( e ) => setName( e.target.value ) }
              placeholder={ __(
                'e.g. Zapier integration, CRM sync',
                'mission-donation-platform'
              ) }
            />
          </div>

          { /* Events multi-select */ }
          <div className="mission-wh-field">
            { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
            <label className="mission-wh-field-label">
              { __( 'Events', 'mission-donation-platform' ) }
            </label>
            <div className="mission-wh-events-wrap" ref={ wrapRef }>
              { /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
              <div
                className={ `mission-wh-events-field${
                  dropdownOpen ? ' mission-wh-events-field--focused' : ''
                }` }
                onClick={ () => {
                  searchRef.current?.focus();
                  setDropdownOpen( true );
                } }
              >
                { selectedEvents.map( ( key ) => {
                  const evt = allEvents.find( ( e ) => e.key === key );
                  if ( ! evt ) {
                    return null;
                  }
                  return (
                    <span key={ key } className="mission-wh-event-tag">
                      { evt.label }
                      <button
                        type="button"
                        className="mission-wh-event-tag-remove"
                        onClick={ ( e ) => {
                          e.stopPropagation();
                          handleRemoveEvent( key );
                        } }
                      >
                        &times;
                      </button>
                    </span>
                  );
                } ) }
                <input
                  ref={ searchRef }
                  type="text"
                  className="mission-wh-events-search"
                  value={ searchValue }
                  onChange={ ( e ) => {
                    setSearchValue( e.target.value );
                    setDropdownOpen( true );
                  } }
                  onFocus={ () => setDropdownOpen( true ) }
                  onKeyDown={ handleSearchKeyDown }
                  placeholder={
                    selectedEvents.length === 0
                      ? __( 'Search events…', 'mission-donation-platform' )
                      : ''
                  }
                  autoComplete="off"
                />
              </div>

              { dropdownOpen && (
                <div className="mission-wh-events-dropdown">
                  { Object.keys( filteredGroups ).length === 0 ? (
                    <div className="mission-wh-events-empty">
                      { __(
                        'No matching events',
                        'mission-donation-platform'
                      ) }
                    </div>
                  ) : (
                    <>
                      { ! searchValue && (
                        // eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
                        <div
                          className={ `mission-wh-event-option mission-wh-event-option--select-all${
                            allSelected
                              ? ' mission-wh-event-option--selected'
                              : ''
                          }` }
                          onClick={ handleSelectAll }
                        >
                          { allSelected
                            ? __( 'Deselect All', 'mission-donation-platform' )
                            : __( 'Select All', 'mission-donation-platform' ) }
                        </div>
                      ) }
                      { Object.entries( filteredGroups ).map(
                        ( [ group, events ] ) => (
                          <div key={ group }>
                            <div className="mission-wh-events-group-label">
                              { group }
                            </div>
                            { events.map( ( evt ) => {
                              const isSelected = selectedEvents.includes(
                                evt.key
                              );
                              return (
                                // eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
                                <div
                                  key={ evt.key }
                                  className={ `mission-wh-event-option${
                                    isSelected
                                      ? ' mission-wh-event-option--selected'
                                      : ''
                                  }` }
                                  onClick={ () => handleToggleEvent( evt.key ) }
                                >
                                  { evt.label }
                                </div>
                              );
                            } ) }
                          </div>
                        )
                      ) }
                    </>
                  ) }
                </div>
              ) }
            </div>
            <p className="mission-wh-field-hint">
              { __(
                'Choose which events trigger this webhook.',
                'mission-donation-platform'
              ) }
            </p>
          </div>

          { /* Signing Secret (edit only) */ }
          { isEditing && (
            <div className="mission-wh-modal-secret">
              { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
              <label className="mission-wh-field-label">
                { __( 'Signing Secret', 'mission-donation-platform' ) }
              </label>
              <div className="mission-wh-modal-secret-row">
                <code
                  className="mission-wh-modal-secret-value"
                  style={
                    secretFlash
                      ? {
                          background: 'rgba(47, 163, 107, 0.08)',
                          borderColor: '#2fa36b',
                        }
                      : undefined
                  }
                >
                  { secret }
                </code>
                <button
                  type="button"
                  className="mission-wh-secret-copy"
                  onClick={ handleCopySecret }
                >
                  { copied
                    ? __( 'Copied!', 'mission-donation-platform' )
                    : __( 'Copy', 'mission-donation-platform' ) }
                </button>
                <button
                  type="button"
                  className="mission-wh-secret-regen"
                  onClick={ handleRegenerateSecret }
                >
                  { __( 'Regenerate', 'mission-donation-platform' ) }
                </button>
              </div>
              <p className="mission-wh-field-hint">
                { __(
                  'Used to verify webhook payloads. Regenerating will invalidate the current secret.',
                  'mission-donation-platform'
                ) }
              </p>
            </div>
          ) }
        </div>

        <div className="mission-wh-modal-footer">
          <button
            type="button"
            className="components-button is-secondary"
            onClick={ onClose }
          >
            { __( 'Cancel', 'mission-donation-platform' ) }
          </button>
          <button
            type="button"
            className="components-button is-primary"
            onClick={ handleSave }
            disabled={ isSaving }
          >
            { isSaving && __( 'Saving…', 'mission-donation-platform' ) }
            { ! isSaving &&
              isEditing &&
              __( 'Save Changes', 'mission-donation-platform' ) }
            { ! isSaving &&
              ! isEditing &&
              __( 'Save Webhook', 'mission-donation-platform' ) }
          </button>
        </div>
      </div>
    </div>
  );
}
