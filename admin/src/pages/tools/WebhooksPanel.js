import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import ConfirmationModal from './ConfirmationModal';
import WebhookDeliveryLog from './WebhookDeliveryLog';
import WebhookModal from './WebhookModal';

const PING_FADE_DELAY = 3000;

export default function WebhooksPanel() {
  const [ webhooks, setWebhooks ] = useState( [] );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ modalWebhook, setModalWebhook ] = useState( null );
  const [ showModal, setShowModal ] = useState( false );
  const [ deleteTarget, setDeleteTarget ] = useState( null );
  const [ isDeleting, setIsDeleting ] = useState( false );
  const [ deliveryWebhook, setDeliveryWebhook ] = useState( null );

  const fetchWebhooks = useCallback( async () => {
    setIsLoading( true );
    try {
      const data = await apiFetch( {
        path: '/mission-donation-platform/v1/outgoing-webhooks?per_page=100',
      } );
      setWebhooks( data );
    } catch {
      setWebhooks( [] );
    } finally {
      setIsLoading( false );
    }
  }, [] );

  useEffect( () => {
    fetchWebhooks();
  }, [ fetchWebhooks ] );

  const handleAdd = () => {
    setModalWebhook( null );
    setShowModal( true );
  };

  const handleEdit = ( webhook ) => {
    setModalWebhook( webhook );
    setShowModal( true );
  };

  const handleModalClose = () => {
    setShowModal( false );
    setModalWebhook( null );
  };

  const handleModalSave = () => {
    handleModalClose();
    fetchWebhooks();
  };

  const handleToggleStatus = async ( webhook ) => {
    const newStatus = webhook.status === 'active' ? 'paused' : 'active';
    try {
      await apiFetch( {
        path: `/mission-donation-platform/v1/outgoing-webhooks/${ webhook.id }`,
        method: 'PATCH',
        data: { status: newStatus },
      } );
      setWebhooks( ( prev ) =>
        prev.map( ( w ) =>
          w.id === webhook.id ? { ...w, status: newStatus } : w
        )
      );
    } catch {}
  };

  const handleDelete = async () => {
    if ( ! deleteTarget ) {
      return;
    }
    setIsDeleting( true );
    try {
      await apiFetch( {
        path: `/mission-donation-platform/v1/outgoing-webhooks/${ deleteTarget.id }`,
        method: 'DELETE',
      } );
      setWebhooks( ( prev ) =>
        prev.filter( ( w ) => w.id !== deleteTarget.id )
      );
    } catch {}
    setIsDeleting( false );
    setDeleteTarget( null );
  };

  if ( deliveryWebhook ) {
    return (
      <WebhookDeliveryLog
        webhook={ deliveryWebhook }
        onBack={ () => setDeliveryWebhook( null ) }
      />
    );
  }

  return (
    <div className="mission-settings-card">
      { isLoading && <LoadingSkeleton /> }

      { ! isLoading && webhooks.length === 0 && (
        <EmptyState onAdd={ handleAdd } />
      ) }

      { ! isLoading && webhooks.length > 0 && (
        <>
          <div className="mission-wh-toolbar">
            <span className="mission-wh-toolbar-count">
              { webhooks.length }{ ' ' }
              { webhooks.length === 1
                ? __( 'webhook', 'mission-donation-platform' )
                : __( 'webhooks', 'mission-donation-platform' ) }
            </span>
            <button
              type="button"
              className="components-button is-primary"
              onClick={ handleAdd }
              style={ { fontSize: '13px', padding: '7px 14px' } }
            >
              <svg
                width="13"
                height="13"
                viewBox="0 0 14 14"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                style={ { marginRight: '4px' } }
              >
                <path d="M7 2v10M2 7h10" />
              </svg>
              { __( 'Add Webhook', 'mission-donation-platform' ) }
            </button>
          </div>
          <table className="mission-wh-table">
            <thead>
              <tr>
                <th style={ { width: '52px' } }>
                  { __( 'Status', 'mission-donation-platform' ) }
                </th>
                <th>{ __( 'Endpoint', 'mission-donation-platform' ) }</th>
                <th>{ __( 'Events', 'mission-donation-platform' ) }</th>
                <th style={ { textAlign: 'right' } }>
                  { __( 'Actions', 'mission-donation-platform' ) }
                </th>
              </tr>
            </thead>
            <tbody>
              { webhooks.map( ( webhook ) => (
                <WebhookRow
                  key={ webhook.id }
                  webhook={ webhook }
                  onToggle={ handleToggleStatus }
                  onEdit={ handleEdit }
                  onDelete={ setDeleteTarget }
                  onViewDeliveries={ setDeliveryWebhook }
                />
              ) ) }
            </tbody>
          </table>
        </>
      ) }

      { showModal && (
        <WebhookModal
          webhook={ modalWebhook }
          onClose={ handleModalClose }
          onSave={ handleModalSave }
        />
      ) }

      { deleteTarget && (
        <ConfirmationModal
          title={ __( 'Delete Webhook', 'mission-donation-platform' ) }
          message={
            __( 'Delete the webhook', 'mission-donation-platform' ) +
            ` \u201c${ deleteTarget.name }\u201d? ` +
            __( 'This action cannot be undone.', 'mission-donation-platform' )
          }
          confirmLabel={ __( 'Delete', 'mission-donation-platform' ) }
          isDanger
          isRunning={ isDeleting }
          onConfirm={ handleDelete }
          onCancel={ () => setDeleteTarget( null ) }
        />
      ) }
    </div>
  );
}

function WebhookRow( {
  webhook,
  onToggle,
  onEdit,
  onDelete,
  onViewDeliveries,
} ) {
  const [ showSecret, setShowSecret ] = useState( false );
  const [ pingState, setPingState ] = useState( null ); // null | 'loading' | 'success' | 'fail'
  const [ openMenu, setOpenMenu ] = useState( false );
  const [ expandedUrl, setExpandedUrl ] = useState( false );
  const [ copied, setCopied ] = useState( false );
  const menuRef = useRef();
  const urlInputRef = useRef();

  // Close dropdown on outside click.
  useEffect( () => {
    if ( ! openMenu ) {
      return;
    }
    const close = ( e ) => {
      if ( menuRef.current && ! menuRef.current.contains( e.target ) ) {
        setOpenMenu( false );
      }
    };
    document.addEventListener( 'click', close, true );
    return () => document.removeEventListener( 'click', close, true );
  }, [ openMenu ] );

  // Auto-select URL input on expand.
  useEffect( () => {
    if ( expandedUrl && urlInputRef.current ) {
      urlInputRef.current.focus();
      urlInputRef.current.select();
    }
  }, [ expandedUrl ] );

  const handlePing = async () => {
    setOpenMenu( false );
    setPingState( 'loading' );
    try {
      const result = await apiFetch( {
        path: `/mission-donation-platform/v1/outgoing-webhooks/${ webhook.id }/ping`,
        method: 'POST',
      } );
      setPingState( result.status === 'success' ? 'success' : 'fail' );
    } catch {
      setPingState( 'fail' );
    }
    setTimeout( () => setPingState( 'fade-out' ), PING_FADE_DELAY );
    setTimeout( () => setPingState( null ), PING_FADE_DELAY + 400 );
  };

  const handleToggleSecret = () => {
    setOpenMenu( false );
    setShowSecret( ! showSecret );
  };

  const handleCopySecret = async () => {
    try {
      await window.navigator.clipboard.writeText( webhook.secret );
      setCopied( true );
      setTimeout( () => setCopied( false ), 1500 );
    } catch {}
  };

  const eventCount = webhook.events.includes( '*' )
    ? __( 'All events', 'mission-donation-platform' )
    : `${ webhook.events.length } ${
        webhook.events.length === 1
          ? __( 'event', 'mission-donation-platform' )
          : __( 'events', 'mission-donation-platform' )
      }`;

  return (
    <tr>
      { /* Status toggle */ }
      <td>
        { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
        <label className="mission-toggle-sm" style={ { margin: 0 } }>
          <input
            type="checkbox"
            checked={ webhook.status === 'active' }
            onChange={ () => onToggle( webhook ) }
          />
          <span className="mission-toggle-sm__slider" />
        </label>
      </td>

      { /* Endpoint: description + URL */ }
      <td className="mission-wh-endpoint-cell">
        <div className="mission-wh-endpoint-desc">
          { webhook.name || webhook.url }
        </div>
        { expandedUrl ? (
          <input
            ref={ urlInputRef }
            type="text"
            className="mission-wh-endpoint-url-input"
            value={ webhook.url }
            readOnly
            onBlur={ () => setExpandedUrl( false ) }
          />
        ) : (
          /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */
          <div
            className="mission-wh-endpoint-url"
            onClick={ () => setExpandedUrl( true ) }
            title={ __(
              'Click to reveal full URL',
              'mission-donation-platform'
            ) }
          >
            { webhook.url }
          </div>
        ) }
        { showSecret && (
          <div className="mission-wh-secret-row">
            <span className="mission-wh-secret-label">
              { __( 'Secret', 'mission-donation-platform' ) }
            </span>
            <code className="mission-wh-secret-value">{ webhook.secret }</code>
            <button
              type="button"
              className="mission-wh-secret-copy"
              onClick={ handleCopySecret }
            >
              { copied
                ? __( 'Copied!', 'mission-donation-platform' )
                : __( 'Copy', 'mission-donation-platform' ) }
            </button>
          </div>
        ) }
      </td>

      { /* Events count */ }
      <td>
        <span className="mission-wh-events-count">{ eventCount }</span>
      </td>

      { /* Actions dropdown */ }
      <td style={ { textAlign: 'right' } }>
        <div className="mission-dropdown" ref={ menuRef }>
          <button
            className="mission-dropdown__toggle"
            onClick={ () => setOpenMenu( ! openMenu ) }
          >
            { __( 'Actions', 'mission-donation-platform' ) }
            <svg
              width="10"
              height="6"
              viewBox="0 0 10 6"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M1 1l4 4 4-4" />
            </svg>
          </button>
          { openMenu && (
            <div className="mission-dropdown__menu">
              <button
                className="mission-dropdown__item"
                onClick={ () => {
                  setOpenMenu( false );
                  onEdit( webhook );
                } }
              >
                <svg
                  width="14"
                  height="14"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                  <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                </svg>
                { __( 'Edit', 'mission-donation-platform' ) }
              </button>
              <button
                className="mission-dropdown__item"
                onClick={ handleToggleSecret }
              >
                <svg
                  width="14"
                  height="14"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                  <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                </svg>
                { __( 'Signing Secret', 'mission-donation-platform' ) }
              </button>
              <button className="mission-dropdown__item" onClick={ handlePing }>
                <svg
                  width="14"
                  height="14"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d="M22 2L11 13" />
                  <polygon points="22 2 15 22 11 13 2 9 22 2" />
                </svg>
                { __( 'Send Test Ping', 'mission-donation-platform' ) }
              </button>
              <button
                className="mission-dropdown__item"
                onClick={ () => {
                  setOpenMenu( false );
                  onViewDeliveries( webhook );
                } }
              >
                <svg
                  width="14"
                  height="14"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                  <polyline points="14 2 14 8 20 8" />
                  <line x1="16" y1="13" x2="8" y2="13" />
                  <line x1="16" y1="17" x2="8" y2="17" />
                </svg>
                { __( 'Delivery Log', 'mission-donation-platform' ) }
              </button>
              <div className="mission-dropdown__divider" />
              <button
                className="mission-dropdown__item mission-dropdown__item--danger"
                onClick={ () => {
                  setOpenMenu( false );
                  onDelete( webhook );
                } }
              >
                <svg
                  width="14"
                  height="14"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <polyline points="3 6 5 6 21 6" />
                  <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                  <path d="M10 11v6" />
                  <path d="M14 11v6" />
                </svg>
                { __( 'Delete', 'mission-donation-platform' ) }
              </button>
            </div>
          ) }
        </div>
        <PingResult state={ pingState } />
      </td>
    </tr>
  );
}

function PingResult( { state } ) {
  if ( ! state ) {
    return null;
  }

  if ( state === 'loading' ) {
    return (
      <div style={ { marginTop: '6px' } }>
        <span className="mission-wh-ping-result mission-wh-ping-result--loading">
          <svg
            width="12"
            height="12"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="mission-wh-spin"
          >
            <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" />
          </svg>
          { __( 'Sending…', 'mission-donation-platform' ) }
        </span>
      </div>
    );
  }

  const isSuccess = state === 'success';
  const isFading = state === 'fade-out';

  return (
    <div style={ { marginTop: '6px' } }>
      <span
        className={ `mission-wh-ping-result ${
          isSuccess || state === 'fade-out'
            ? 'mission-wh-ping-result--success'
            : 'mission-wh-ping-result--fail'
        }${ isFading ? ' mission-wh-ping-result--fade-out' : '' }` }
      >
        { isSuccess || isFading ? (
          <>
            <svg
              width="12"
              height="12"
              viewBox="0 0 16 16"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <polyline points="3,8 7,12 13,4" />
            </svg>
            { __( 'Ping successful', 'mission-donation-platform' ) }
          </>
        ) : (
          <>
            <svg
              width="12"
              height="12"
              viewBox="0 0 16 16"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M4 4l8 8M12 4l-8 8" />
            </svg>
            { __( 'Ping failed', 'mission-donation-platform' ) }
          </>
        ) }
      </span>
    </div>
  );
}

function LoadingSkeleton() {
  return (
    <div style={ { display: 'flex', flexDirection: 'column', gap: '12px' } }>
      { [ 1, 2, 3 ].map( ( i ) => (
        <div
          key={ i }
          className="mission-skeleton"
          style={ {
            height: '52px',
            borderRadius: '6px',
            background: '#e2e4e9',
          } }
        />
      ) ) }
    </div>
  );
}

function EmptyState( { onAdd } ) {
  return (
    <div className="mission-wh-empty">
      <div className="mission-wh-empty-icon">
        <svg
          width="26"
          height="26"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
        </svg>
      </div>
      <h3 className="mission-wh-empty-title">
        { __( 'No webhooks configured yet', 'mission-donation-platform' ) }
      </h3>
      <p className="mission-wh-empty-desc">
        { __(
          'Webhooks send real-time notifications to external services when events happen in Mission, like a new donation or a canceled subscription.',
          'mission-donation-platform'
        ) }
      </p>
      <button
        type="button"
        className="components-button is-primary"
        onClick={ onAdd }
      >
        <svg
          width="14"
          height="14"
          viewBox="0 0 14 14"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          style={ { marginRight: '4px' } }
        >
          <path d="M7 2v10M2 7h10" />
        </svg>
        { __( 'Add Webhook', 'mission-donation-platform' ) }
      </button>
    </div>
  );
}
