import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const DATA_TYPES = [
  { value: 'donors', label: __( 'Donors', 'mission' ) },
  { value: 'transactions', label: __( 'Transactions', 'mission' ) },
  { value: 'campaigns', label: __( 'Campaigns', 'mission' ) },
  { value: 'subscriptions', label: __( 'Subscriptions', 'mission' ) },
  { value: 'tributes', label: __( 'Dedications', 'mission' ) },
];

function SkeletonBar( { width = '60%', height = '16px' } ) {
  return (
    <span
      className="mission-skeleton"
      style={ {
        display: 'block',
        width,
        height,
        borderRadius: '4px',
        background: '#e2e4e9',
      } }
    />
  );
}

function buildQueryString( params ) {
  return Object.entries( params )
    .filter( ( [ , v ] ) => v )
    .map(
      ( [ k, v ] ) =>
        `${ encodeURIComponent( k ) }=${ encodeURIComponent( v ) }`
    )
    .join( '&' );
}

export default function ExportPanel() {
  const [ dataType, setDataType ] = useState( 'donors' );
  const [ format, setFormat ] = useState( 'csv' );
  const [ dateFrom, setDateFrom ] = useState( '' );
  const [ dateTo, setDateTo ] = useState( '' );
  const [ notifyMethod, setNotifyMethod ] = useState( '' );
  const [ notificationStatus, setNotificationStatus ] = useState( '' );

  const [ count, setCount ] = useState( null );
  const [ preview, setPreview ] = useState( null );
  const [ isLoadingCount, setIsLoadingCount ] = useState( true );
  const [ isLoadingPreview, setIsLoadingPreview ] = useState( true );

  const typeLabel =
    DATA_TYPES.find( ( t ) => t.value === dataType )?.label ?? '';

  // Fetch count and preview when filters change.
  const fetchData = useCallback( () => {
    setIsLoadingCount( true );
    setIsLoadingPreview( true );

    const qs = buildQueryString( {
      type: dataType,
      date_from: dateFrom,
      date_to: dateTo,
      notify_method: notifyMethod,
      notification_status: notificationStatus,
    } );

    apiFetch( { path: `/mission/v1/export/count?${ qs }` } )
      .then( ( data ) => setCount( data.count ) )
      .catch( () => setCount( 0 ) )
      .finally( () => setIsLoadingCount( false ) );

    apiFetch( { path: `/mission/v1/export/preview?${ qs }` } )
      .then( ( data ) => setPreview( data ) )
      .catch( () => setPreview( { columns: [], rows: [] } ) )
      .finally( () => setIsLoadingPreview( false ) );
  }, [ dataType, dateFrom, dateTo, notifyMethod, notificationStatus ] );

  useEffect( () => {
    fetchData();
  }, [ fetchData ] );

  // Clear type-specific filters when switching data types.
  const handleTypeChange = ( newType ) => {
    if ( newType !== dataType ) {
      setDateFrom( '' );
      setDateTo( '' );
      setNotifyMethod( '' );
      setNotificationStatus( '' );
    }
    setDataType( newType );
  };

  const handleDownload = () => {
    const qs = buildQueryString( {
      type: dataType,
      format,
      date_from: dateFrom,
      date_to: dateTo,
      notify_method: notifyMethod,
      notification_status: notificationStatus,
      _wpnonce: window.missionAdmin?.restNonce,
    } );
    window.open(
      `${ window.missionAdmin?.restUrl }export/download?${ qs }`,
      '_blank'
    );
  };

  const handleDownloadAll = () => {
    const qs = buildQueryString( {
      format,
      _wpnonce: window.missionAdmin?.restNonce,
    } );
    window.open(
      `${ window.missionAdmin?.restUrl }export/download-all?${ qs }`,
      '_blank'
    );
  };

  // Skeleton widths per column for visual variety.
  const skeletonWidths = [ '50%', '65%', '40%', '55%', '45%', '60%' ];

  return (
    <div className="mission-settings-panel">
      { /* Export Data */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Export Data', 'mission' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __( 'Download your Mission data as a file.', 'mission' ) }
          </p>
        </div>

        <div className="mission-tools-field-row">
          <div className="mission-settings-field">
            <label
              className="mission-settings-field__label"
              htmlFor="export-type"
            >
              { __( 'Data type', 'mission' ) }
            </label>
            <select
              id="export-type"
              className="mission-settings-field__select"
              value={ dataType }
              onChange={ ( e ) => handleTypeChange( e.target.value ) }
            >
              { DATA_TYPES.map( ( type ) => (
                <option key={ type.value } value={ type.value }>
                  { type.label }
                </option>
              ) ) }
            </select>
          </div>
          <div className="mission-settings-field">
            <label
              className="mission-settings-field__label"
              htmlFor="export-format"
            >
              { __( 'File format', 'mission' ) }
            </label>
            <select
              id="export-format"
              className="mission-settings-field__select"
              value={ format }
              onChange={ ( e ) => setFormat( e.target.value ) }
            >
              <option value="csv">CSV</option>
              <option value="json">JSON</option>
            </select>
          </div>
        </div>

        { ( dataType === 'transactions' || dataType === 'tributes' ) && (
          <div className="mission-tools-date-row">
            <div className="mission-settings-field">
              <label
                className="mission-settings-field__label"
                htmlFor="export-from"
              >
                { __( 'From', 'mission' ) }
              </label>
              <input
                type="date"
                id="export-from"
                className="mission-settings-field__input"
                value={ dateFrom }
                onChange={ ( e ) => setDateFrom( e.target.value ) }
              />
            </div>
            <div className="mission-settings-field">
              <label
                className="mission-settings-field__label"
                htmlFor="export-to"
              >
                { __( 'To', 'mission' ) }
              </label>
              <input
                type="date"
                id="export-to"
                className="mission-settings-field__input"
                value={ dateTo }
                onChange={ ( e ) => setDateTo( e.target.value ) }
              />
              <span className="mission-settings-field__hint">
                { __( 'Leave blank to export all.', 'mission' ) }
              </span>
            </div>
          </div>
        ) }

        { dataType === 'tributes' && (
          <div className="mission-tools-date-row">
            <div className="mission-settings-field">
              <label
                className="mission-settings-field__label"
                htmlFor="export-notify-method"
              >
                { __( 'Notify method', 'mission' ) }
              </label>
              <select
                id="export-notify-method"
                className="mission-settings-field__select"
                value={ notifyMethod }
                onChange={ ( e ) => setNotifyMethod( e.target.value ) }
              >
                <option value="">{ __( 'All', 'mission' ) }</option>
                <option value="email">{ __( 'Email', 'mission' ) }</option>
                <option value="mail">{ __( 'Mail', 'mission' ) }</option>
              </select>
            </div>
            <div className="mission-settings-field">
              <label
                className="mission-settings-field__label"
                htmlFor="export-notification-status"
              >
                { __( 'Notification status', 'mission' ) }
              </label>
              <select
                id="export-notification-status"
                className="mission-settings-field__select"
                value={ notificationStatus }
                onChange={ ( e ) => setNotificationStatus( e.target.value ) }
              >
                <option value="">{ __( 'All', 'mission' ) }</option>
                <option value="pending">{ __( 'Pending', 'mission' ) }</option>
                <option value="sent">{ __( 'Sent', 'mission' ) }</option>
              </select>
            </div>
          </div>
        ) }

        <div className="mission-tools-record-count">
          <svg
            width="14"
            height="14"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <rect x="2" y="2" width="12" height="12" rx="2" />
            <path d="M6 6h4M6 8.5h4M6 11h2" />
          </svg>
          { isLoadingCount ? (
            <SkeletonBar width="140px" />
          ) : (
            <span>
              { ( count ?? 0 ).toLocaleString() } { dataType }{ ' ' }
              { __( 'will be exported', 'mission' ) }
            </span>
          ) }
        </div>

        <div className="mission-tools-actions">
          <button
            className="mission-settings-save-bar__btn"
            type="button"
            onClick={ handleDownload }
            disabled={ count === 0 }
          >
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
              <path d="M14 10v3a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 2 13v-3" />
              <polyline points="5 7 8 10 11 7" />
              <line x1="8" y1="10" x2="8" y2="2" />
            </svg>
            { __( 'Export', 'mission' ) + ' ' + typeLabel }
          </button>
        </div>
      </div>

      { /* Preview */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Preview', 'mission' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'First 5 records that will be included in your export.',
              'mission'
            ) }
          </p>
        </div>
        <div className="mission-tools-table-wrap">
          <table className="mission-tools-preview-table">
            <thead>
              <tr>
                { isLoadingPreview
                  ? Array.from( { length: 5 }, ( _, i ) => (
                      <th key={ i }>
                        <SkeletonBar
                          width={ skeletonWidths[ i ] }
                          height="12px"
                        />
                      </th>
                    ) )
                  : ( preview?.columns ?? [] ).map( ( col ) => (
                      <th key={ col }>{ col }</th>
                    ) ) }
              </tr>
            </thead>
            <tbody>
              { isLoadingPreview
                ? Array.from( { length: 5 }, ( _, rowIdx ) => (
                    <tr key={ rowIdx }>
                      { Array.from( { length: 5 }, ( _unused, colIdx ) => (
                        <td key={ colIdx }>
                          <SkeletonBar
                            width={
                              skeletonWidths[
                                ( rowIdx + colIdx ) % skeletonWidths.length
                              ]
                            }
                          />
                        </td>
                      ) ) }
                    </tr>
                  ) )
                : ( preview?.rows ?? [] ).map( ( row, i ) => (
                    <tr key={ i }>
                      { row.map( ( cell, j ) => (
                        <td
                          key={ j }
                          className={
                            j === 0 ? 'mission-tools-preview-table__strong' : ''
                          }
                        >
                          { cell }
                        </td>
                      ) ) }
                    </tr>
                  ) ) }
            </tbody>
          </table>
        </div>
      </div>

      { /* Export All */ }
      <div className="mission-settings-card">
        <div className="mission-tools-export-all">
          <div className="mission-tools-export-all__icon">
            <svg
              width="22"
              height="22"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.6"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M21 8v13H3V8" />
              <path d="M1 3h22v5H1z" />
              <path d="M10 12h4" />
            </svg>
          </div>
          <div className="mission-tools-export-all__text">
            <div className="mission-tools-export-all__title">
              { __( 'Export All Data', 'mission' ) }
            </div>
            <div className="mission-tools-export-all__desc">
              { __(
                'Download all donors, transactions, campaigns, and subscriptions as a single ZIP file.',
                'mission'
              ) }
            </div>
          </div>
          <button
            className="mission-settings-secondary-btn"
            type="button"
            onClick={ handleDownloadAll }
          >
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
              <path d="M14 10v3a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 2 13v-3" />
              <polyline points="5 7 8 10 11 7" />
              <line x1="8" y1="10" x2="8" y2="2" />
            </svg>
            { __( 'Download ZIP', 'mission' ) }
          </button>
        </div>
      </div>
    </div>
  );
}
