import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const PER_PAGE = 25;

function formatTimestamp( dateString ) {
  if ( ! dateString ) {
    return '\u2014';
  }
  const d = new Date( dateString + 'Z' );
  const pad = ( n ) => String( n ).padStart( 2, '0' );
  return (
    `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad(
      d.getDate()
    ) } ` +
    `${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }:${ pad(
      d.getSeconds()
    ) }`
  );
}

function StatusBadge( { status, code } ) {
  if ( status === 'pending' ) {
    return <span className="mission-wh-delivery-badge">Pending</span>;
  }

  if ( status === 'success' ) {
    return (
      <span className="mission-wh-delivery-badge mission-wh-delivery-badge--success">
        { code || '2xx' }
      </span>
    );
  }

  return (
    <span className="mission-wh-delivery-badge mission-wh-delivery-badge--fail">
      { code || __( 'Error', 'mission-donation-platform' ) }
    </span>
  );
}

function DeliveryDetail( { delivery } ) {
  return (
    <tr>
      <td colSpan="6" style={ { padding: 0 } }>
        <div className="mission-wh-delivery-detail">
          { delivery.error_message && (
            <div className="mission-wh-delivery-detail__error">
              <strong>{ __( 'Error:', 'mission-donation-platform' ) }</strong>{ ' ' }
              { delivery.error_message }
            </div>
          ) }
          <div className="mission-wh-delivery-detail__grid">
            <div>
              <div className="mission-wh-delivery-detail__heading">
                { __( 'Request', 'mission-donation-platform' ) }
              </div>
              { delivery.request_headers && (
                <DetailBlock
                  label={ __( 'Headers', 'mission-donation-platform' ) }
                  content={ JSON.stringify(
                    delivery.request_headers,
                    null,
                    2
                  ) }
                />
              ) }
              { delivery.request_body && (
                <DetailBlock
                  label={ __( 'Body', 'mission-donation-platform' ) }
                  content={ JSON.stringify( delivery.request_body, null, 2 ) }
                />
              ) }
            </div>
            <div>
              <div className="mission-wh-delivery-detail__heading">
                { __( 'Response', 'mission-donation-platform' ) }
              </div>
              { delivery.response_headers && (
                <DetailBlock
                  label={ __( 'Headers', 'mission-donation-platform' ) }
                  content={ JSON.stringify(
                    delivery.response_headers,
                    null,
                    2
                  ) }
                />
              ) }
              { delivery.response_body && (
                <DetailBlock
                  label={ __( 'Body', 'mission-donation-platform' ) }
                  content={
                    typeof delivery.response_body === 'string'
                      ? delivery.response_body
                      : JSON.stringify( delivery.response_body, null, 2 )
                  }
                />
              ) }
            </div>
          </div>
        </div>
      </td>
    </tr>
  );
}

function DetailBlock( { label, content } ) {
  return (
    <div style={ { marginBottom: '8px' } }>
      <div className="mission-wh-delivery-detail__label">{ label }</div>
      <pre className="mission-wh-delivery-detail__pre">{ content }</pre>
    </div>
  );
}

export default function WebhookDeliveryLog( { webhook, onBack } ) {
  const [ deliveries, setDeliveries ] = useState( [] );
  const [ total, setTotal ] = useState( 0 );
  const [ page, setPage ] = useState( 1 );
  const [ hasMore, setHasMore ] = useState( false );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ isLoadingMore, setIsLoadingMore ] = useState( false );
  const [ expandedId, setExpandedId ] = useState( null );

  const fetchDeliveries = useCallback(
    async ( pageNum = 1, append = false ) => {
      if ( pageNum === 1 ) {
        setIsLoading( true );
      } else {
        setIsLoadingMore( true );
      }

      try {
        const response = await apiFetch( {
          path: `/mission-donation-platform/v1/outgoing-webhooks/${ webhook.id }/deliveries?page=${ pageNum }&per_page=${ PER_PAGE }`,
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
        setDeliveries( ( prev ) => ( append ? [ ...prev, ...data ] : data ) );
        setPage( pageNum );
      } catch {
        if ( ! append ) {
          setDeliveries( [] );
        }
      } finally {
        setIsLoading( false );
        setIsLoadingMore( false );
      }
    },
    [ webhook.id ]
  );

  useEffect( () => {
    fetchDeliveries();
  }, [ fetchDeliveries ] );

  const handleLoadMore = () => {
    fetchDeliveries( page + 1, true );
  };

  return (
    <>
      { /* Back link */ }
      <div style={ { marginBottom: '16px' } }>
        { /* eslint-disable-next-line jsx-a11y/anchor-is-valid */ }
        <a
          href="#"
          className="mission-back-link"
          onClick={ ( e ) => {
            e.preventDefault();
            onBack();
          } }
        >
          <svg
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path d="M19 12H5M12 19l-7-7 7-7" />
          </svg>
          { __( 'Back to Webhooks', 'mission-donation-platform' ) }
        </a>
      </div>

      <div className="mission-settings-card">
        <div style={ { marginBottom: '16px' } }>
          <h3
            style={ {
              fontSize: '15px',
              fontWeight: 700,
              margin: '0 0 4px',
              color: '#1e1e1e',
            } }
          >
            { __( 'Delivery Log', 'mission-donation-platform' ) }
          </h3>
          <p
            style={ {
              fontSize: '13px',
              color: '#9b9ba8',
              margin: 0,
            } }
          >
            { webhook.name }
            <code className="mission-wh-delivery-url-badge">
              { webhook.url }
            </code>
            { ! isLoading && total > 0 && (
              <span style={ { marginLeft: '8px' } }>
                &mdash; { total }{ ' ' }
                { total === 1
                  ? __( 'delivery', 'mission-donation-platform' )
                  : __( 'deliveries', 'mission-donation-platform' ) }
              </span>
            ) }
          </p>
        </div>

        { isLoading && (
          <div
            style={ {
              display: 'flex',
              flexDirection: 'column',
              gap: '8px',
            } }
          >
            { [ 1, 2, 3 ].map( ( i ) => (
              <div
                key={ i }
                className="mission-skeleton"
                style={ {
                  height: '40px',
                  borderRadius: '6px',
                  background: '#e2e4e9',
                } }
              />
            ) ) }
          </div>
        ) }

        { ! isLoading && deliveries.length === 0 && (
          <p className="mission-wh-delivery-empty">
            { __( 'No deliveries recorded yet.', 'mission-donation-platform' ) }
          </p>
        ) }

        { ! isLoading && deliveries.length > 0 && (
          <>
            <table className="mission-wh-table">
              <thead>
                <tr>
                  <th>{ __( 'Event', 'mission-donation-platform' ) }</th>
                  <th>{ __( 'Status', 'mission-donation-platform' ) }</th>
                  <th>{ __( 'Duration', 'mission-donation-platform' ) }</th>
                  <th>{ __( 'Attempt', 'mission-donation-platform' ) }</th>
                  <th>{ __( 'Date', 'mission-donation-platform' ) }</th>
                  <th style={ { width: '32px' } } />
                </tr>
              </thead>
              <tbody>
                { deliveries.map( ( delivery ) => (
                  <DeliveryRow
                    key={ delivery.id }
                    delivery={ delivery }
                    isExpanded={ expandedId === delivery.id }
                    onToggle={ () =>
                      setExpandedId(
                        expandedId === delivery.id ? null : delivery.id
                      )
                    }
                  />
                ) ) }
              </tbody>
            </table>

            { hasMore && (
              <div className="mission-logs-load-more">
                <button
                  type="button"
                  className="mission-logs-load-more__btn"
                  onClick={ handleLoadMore }
                  disabled={ isLoadingMore }
                >
                  { isLoadingMore
                    ? __( 'Loading\u2026', 'mission-donation-platform' )
                    : __( 'Load More', 'mission-donation-platform' ) }
                </button>
              </div>
            ) }
          </>
        ) }
      </div>
    </>
  );
}

function DeliveryRow( { delivery, isExpanded, onToggle } ) {
  return (
    <>
      { /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-noninteractive-element-interactions */ }
      <tr onClick={ onToggle } style={ { cursor: 'pointer' } }>
        <td>
          <code style={ { fontSize: '12px' } }>{ delivery.event }</code>
        </td>
        <td>
          <StatusBadge
            status={ delivery.status }
            code={ delivery.response_code }
          />
        </td>
        <td style={ { fontSize: '12px', color: '#9b9ba8' } }>
          { delivery.duration_ms !== null && delivery.duration_ms !== undefined
            ? `${ delivery.duration_ms }ms`
            : '\u2014' }
        </td>
        <td style={ { fontSize: '12px' } }>{ delivery.attempt }/5</td>
        <td style={ { fontSize: '12px', color: '#9b9ba8' } }>
          { formatTimestamp( delivery.date_created ) }
        </td>
        <td>
          <svg
            width="14"
            height="14"
            viewBox="0 0 14 14"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            style={ {
              transform: isExpanded ? 'rotate(90deg)' : 'none',
              transition: 'transform 0.15s',
              color: '#9b9ba8',
            } }
          >
            <path d="M5 3l4 4-4 4" />
          </svg>
        </td>
      </tr>
      { isExpanded && <DeliveryDetail delivery={ delivery } /> }
    </>
  );
}
