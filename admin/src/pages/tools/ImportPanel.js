import { useState, useRef, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
  IMPORT_JOB_STATUS,
  IMPORT_MAX_BYTES as MAX_BYTES,
  IMPORT_TERMINAL_STATUSES as TERMINAL_STATUSES,
} from '../../constants';
import {
  POLL_INTERVAL_MS,
  computeMinDuration,
  formatBytes,
  scaleCounter,
} from './import-utils';

const DATA_TYPES = [
  { value: 'donors', label: __( 'Donors', 'mission-donation-platform' ) },
  {
    value: 'transactions',
    label: __( 'Transactions', 'mission-donation-platform' ),
  },
  {
    value: 'campaigns',
    label: __( 'Campaigns', 'mission-donation-platform' ),
  },
  {
    value: 'subscriptions',
    label: __( 'Subscriptions', 'mission-donation-platform' ),
  },
  {
    value: 'tributes',
    label: __( 'Dedications', 'mission-donation-platform' ),
  },
];

const DUPLICATE_STRATEGIES = [
  {
    value: 'skip',
    label: __( 'Skip duplicates', 'mission-donation-platform' ),
    desc: __(
      'If a matching record already exists, skip the imported row.',
      'mission-donation-platform'
    ),
  },
  {
    value: 'update',
    label: __( 'Update existing', 'mission-donation-platform' ),
    desc: __(
      'If a match is found, update the existing record with the imported data.',
      'mission-donation-platform'
    ),
  },
];

function InfoIcon() {
  return (
    <svg
      width="16"
      height="16"
      viewBox="0 0 16 16"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <circle cx="8" cy="8" r="6.5" />
      <path d="M8 10.5V8M8 5.5h.005" />
    </svg>
  );
}

function WarningIcon() {
  return (
    <svg
      width="14"
      height="14"
      viewBox="0 0 14 14"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M7 1.2L1 12.5h12L7 1.2z" />
      <path d="M7 5.5v3M7 10.5h.005" />
    </svg>
  );
}

function CheckIcon() {
  return (
    <svg
      width="14"
      height="14"
      viewBox="0 0 14 14"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <polyline points="3,7 6,10 11,4" />
    </svg>
  );
}

function DownloadIcon() {
  return (
    <svg
      width="12"
      height="12"
      viewBox="0 0 16 16"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M14 10v3.5a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 2 13.5V10" />
      <polyline points="5 7 8 10 11 7" />
      <line x1="8" y1="10" x2="8" y2="2" />
    </svg>
  );
}

export default function ImportPanel() {
  const [ dataType, setDataType ] = useState( 'donors' );
  const [ expectedColumns, setExpectedColumns ] = useState( [] );
  const [ requiredColumns, setRequiredColumns ] = useState( [] );
  const [ uploadState, setUploadState ] = useState( 'upload' ); // 'upload' | 'validation' | 'progress' | 'success' | 'failed'
  const [ isUploading, setIsUploading ] = useState( false );
  const [ uploadError, setUploadError ] = useState( '' );
  const [ validation, setValidation ] = useState( null );
  const [ duplicateStrategy, setDuplicateStrategy ] = useState( 'skip' );
  const [ isDragging, setIsDragging ] = useState( false );
  const [ jobId, setJobId ] = useState( '' );
  const [ jobStatus, setJobStatus ] = useState( null );
  const [ displayedPercent, setDisplayedPercent ] = useState( 0 );
  const [ isCancelling, setIsCancelling ] = useState( false );
  const [ importError, setImportError ] = useState( '' );
  const fileInputRef = useRef( null );
  const progressStartedAt = useRef( 0 );
  const animationFrame = useRef( null );
  const pollTimer = useRef( null );

  const typeLabel =
    DATA_TYPES.find( ( t ) => t.value === dataType )?.label ?? '';

  useEffect( () => {
    apiFetch( {
      path: `/mission-donation-platform/v1/import/columns?type=${ dataType }`,
    } )
      .then( ( data ) => {
        setExpectedColumns( data.columns || [] );
        setRequiredColumns( data.required || [] );
      } )
      .catch( () => {
        setExpectedColumns( [] );
        setRequiredColumns( [] );
      } );
  }, [ dataType ] );

  const stopPolling = useCallback( () => {
    if ( pollTimer.current ) {
      clearInterval( pollTimer.current );
      pollTimer.current = null;
    }
  }, [] );

  const stopAnimation = useCallback( () => {
    if ( animationFrame.current ) {
      window.cancelAnimationFrame( animationFrame.current );
      animationFrame.current = null;
    }
  }, [] );

  const resetToUpload = useCallback( () => {
    stopPolling();
    stopAnimation();
    setUploadState( 'upload' );
    setValidation( null );
    setUploadError( '' );
    setDuplicateStrategy( 'skip' );
    setJobId( '' );
    setJobStatus( null );
    setDisplayedPercent( 0 );
    setIsCancelling( false );
    setImportError( '' );
    progressStartedAt.current = 0;
    if ( fileInputRef.current ) {
      fileInputRef.current.value = '';
    }
  }, [ stopPolling, stopAnimation ] );

  useEffect( () => {
    return () => {
      stopPolling();
      stopAnimation();
    };
  }, [ stopPolling, stopAnimation ] );

  // On mount or when dataType changes, look up an in-flight job for the user
  // so navigating away and coming back resumes into the progress state.
  useEffect( () => {
    let cancelled = false;
    apiFetch( {
      path: `/mission-donation-platform/v1/import/active?type=${ dataType }`,
    } )
      .then( ( data ) => {
        if ( cancelled || ! data?.job ) {
          return;
        }
        const active = data.job;
        if ( TERMINAL_STATUSES.includes( active.status ) ) {
          return;
        }
        setJobId( active.job_id );
        setJobStatus( active );
        setUploadState( 'progress' );
        progressStartedAt.current = Date.now();
      } )
      .catch( () => {
        // Silent — no active job is the normal case.
      } );
    return () => {
      cancelled = true;
    };
  }, [ dataType ] );

  const jobStatusStatus = jobStatus?.status;
  useEffect( () => {
    if ( ! jobId ) {
      stopPolling();
      return undefined;
    }
    if ( jobStatusStatus && TERMINAL_STATUSES.includes( jobStatusStatus ) ) {
      stopPolling();
      return undefined;
    }
    const tick = () => {
      apiFetch( {
        path: `/mission-donation-platform/v1/import/status?job_id=${ encodeURIComponent(
          jobId
        ) }`,
      } )
        .then( ( data ) => setJobStatus( data ) )
        .catch( () => {
          // Network blip — keep polling.
        } );
    };
    tick();
    pollTimer.current = setInterval( tick, POLL_INTERVAL_MS );
    return () => stopPolling();
  }, [ jobId, jobStatusStatus, stopPolling ] );

  // Animate displayedPercent toward the real backend percent on a time-shaped
  // ramp so tiny imports don't jump 0→100% between polls.
  useEffect( () => {
    if ( ! jobStatus ) {
      return undefined;
    }
    const realPercent = Math.max(
      0,
      Math.min( 100, jobStatus.percentage ?? 0 )
    );
    const minDuration = computeMinDuration( jobStatus.total_rows );
    const isTerminal = TERMINAL_STATUSES.includes( jobStatus.status );
    stopAnimation();
    const step = () => {
      setDisplayedPercent( ( current ) => {
        const elapsed = progressStartedAt.current
          ? Date.now() - progressStartedAt.current
          : minDuration;
        const timeDriven = Math.min( 100, ( elapsed / minDuration ) * 100 );
        // Once terminal, let the ramp finish past the real percent.
        const ceiling = isTerminal
          ? Math.max( realPercent, timeDriven )
          : Math.min( realPercent, timeDriven );
        if ( current >= ceiling ) {
          return ceiling;
        }
        const next = current + 0.6;
        return next >= ceiling ? ceiling : next;
      } );
      animationFrame.current = window.requestAnimationFrame( step );
    };
    animationFrame.current = window.requestAnimationFrame( step );
    return () => stopAnimation();
  }, [ jobStatus, stopAnimation ] );

  // Transition into terminal UI states once both the backend is terminal AND
  // the minimum on-screen progress duration has been met.
  const jobTotalRows = jobStatus?.total_rows;
  useEffect( () => {
    if ( ! jobStatusStatus ) {
      return undefined;
    }
    if ( ! TERMINAL_STATUSES.includes( jobStatusStatus ) ) {
      return undefined;
    }
    const minDuration = computeMinDuration( jobTotalRows );
    const elapsed = progressStartedAt.current
      ? Date.now() - progressStartedAt.current
      : minDuration;
    const wait = Math.max( 0, minDuration - elapsed );
    const timeout = setTimeout( () => {
      if ( IMPORT_JOB_STATUS.COMPLETED === jobStatusStatus ) {
        setUploadState( 'success' );
      } else if ( IMPORT_JOB_STATUS.FAILED === jobStatusStatus ) {
        setUploadState( 'failed' );
      } else if ( IMPORT_JOB_STATUS.CANCELLED === jobStatusStatus ) {
        resetToUpload();
      }
    }, wait );
    return () => clearTimeout( timeout );
  }, [ jobStatusStatus, jobTotalRows, resetToUpload ] );

  const executeImport = useCallback( () => {
    if ( ! validation?.file_id ) {
      return;
    }

    setUploadState( 'progress' );
    setImportError( '' );
    setJobStatus( null );
    setDisplayedPercent( 0 );
    progressStartedAt.current = Date.now();

    apiFetch( {
      path: '/mission-donation-platform/v1/import/start',
      method: 'POST',
      data: {
        file_id: validation.file_id,
        duplicate_strategy: duplicateStrategy,
      },
    } )
      .then( ( data ) => {
        setJobId( data.job_id );
        setJobStatus( data );
      } )
      .catch( ( err ) => {
        // 409 conflict surfaces an existing job — resume into it instead of erroring.
        if ( err?.code === 'import_in_progress' && err?.data?.job ) {
          setJobId( err.data.job.job_id );
          setJobStatus( err.data.job );
          return;
        }
        setImportError(
          err?.message ||
            __(
              'The import could not be started.',
              'mission-donation-platform'
            )
        );
        setUploadState( 'validation' );
      } );
  }, [ validation, duplicateStrategy ] );

  const cancelImport = useCallback( () => {
    if ( ! jobId || isCancelling ) {
      return;
    }
    setIsCancelling( true );
    apiFetch( {
      path: '/mission-donation-platform/v1/import/cancel',
      method: 'POST',
      data: { job_id: jobId },
    } )
      .then( ( data ) => setJobStatus( data ) )
      .catch( () => {
        // Polling will catch up eventually.
      } )
      .finally( () => setIsCancelling( false ) );
  }, [ jobId, isCancelling ] );

  const handleTypeChange = ( newType ) => {
    if ( newType !== dataType ) {
      resetToUpload();
    }
    setDataType( newType );
  };

  const uploadFile = useCallback(
    ( file ) => {
      setUploadError( '' );

      if ( ! file ) {
        return;
      }

      const extension = file.name.split( '.' ).pop().toLowerCase();
      if ( ! [ 'csv', 'json' ].includes( extension ) ) {
        setUploadError(
          __(
            'Only .csv and .json files are supported.',
            'mission-donation-platform'
          )
        );
        return;
      }

      if ( file.size > MAX_BYTES ) {
        setUploadError(
          __( 'File exceeds the 10 MB limit.', 'mission-donation-platform' )
        );
        return;
      }

      const body = new FormData();
      body.append( 'file', file );
      body.append( 'type', dataType );

      setIsUploading( true );

      apiFetch( {
        path: '/mission-donation-platform/v1/import/validate',
        method: 'POST',
        body,
      } )
        .then( ( data ) => {
          setValidation( data );
          setUploadState( 'validation' );
        } )
        .catch( ( err ) => {
          setUploadError(
            err?.message ||
              __( 'Could not validate the file.', 'mission-donation-platform' )
          );
        } )
        .finally( () => setIsUploading( false ) );
    },
    [ dataType ]
  );

  const handleFileChange = ( event ) => {
    const file = event.target.files?.[ 0 ];
    if ( file ) {
      uploadFile( file );
    }
  };

  const handleDrop = ( event ) => {
    event.preventDefault();
    setIsDragging( false );
    const file = event.dataTransfer.files?.[ 0 ];
    if ( file ) {
      uploadFile( file );
    }
  };

  const handleDownloadTemplate = ( templateType ) => {
    const qs = new URLSearchParams( {
      type: templateType,
      _wpnonce: window.missiondpAdmin?.restNonce,
    } );
    window.open(
      `${ window.missiondpAdmin?.restUrl }import/template?${ qs.toString() }`,
      '_blank'
    );
  };

  // -----------------
  // Upload State
  // -----------------
  if ( 'upload' === uploadState ) {
    return (
      <div className="mission-settings-panel">
        <div className="mission-settings-card">
          <div className="mission-settings-card__header">
            <h2 className="mission-settings-card__title">
              { __( 'Import Data', 'mission-donation-platform' ) }
            </h2>
            <p className="mission-settings-card__desc">
              { __(
                'Upload a CSV or JSON file to import data into Mission.',
                'mission-donation-platform'
              ) }
            </p>
          </div>

          { uploadError && (
            <div className="mission-import-error" role="alert">
              <WarningIcon />
              <span>{ uploadError }</span>
            </div>
          ) }

          <div
            className="mission-settings-field"
            style={ { marginBottom: '20px' } }
          >
            <label
              className="mission-settings-field__label"
              htmlFor="import-type"
            >
              { __( 'Data type', 'mission-donation-platform' ) }
            </label>
            <select
              id="import-type"
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

          <div className="mission-import-callout">
            <div className="mission-import-callout__icon">
              <InfoIcon />
            </div>
            <div className="mission-import-callout__text">
              <div>
                { __( 'Supported columns:', 'mission-donation-platform' ) }{ ' ' }
                { expectedColumns.map( ( col, idx ) => (
                  <span key={ col }>
                    <code>{ col }</code>
                    { idx < expectedColumns.length - 1 ? ', ' : '' }
                  </span>
                ) ) }
              </div>
              { requiredColumns.length > 0 && (
                <div className="mission-import-callout__required">
                  { __( 'Required:', 'mission-donation-platform' ) }{ ' ' }
                  { requiredColumns.map( ( col, idx ) => (
                    <span key={ col }>
                      <code>{ col }</code>
                      { idx < requiredColumns.length - 1 ? ', ' : '' }
                    </span>
                  ) ) }
                  { requiredColumns.includes( 'donor_email' ) && (
                    <>
                      { ' ' }
                      { __(
                        '(donor_id may be used instead of donor_email)',
                        'mission-donation-platform'
                      ) }
                    </>
                  ) }
                  { requiredColumns.includes( 'transaction_id' ) && (
                    <>
                      { ' ' }
                      { __(
                        '(gateway_transaction_id / Charge ID may be used instead of transaction_id)',
                        'mission-donation-platform'
                      ) }
                    </>
                  ) }
                </div>
              ) }
            </div>
          </div>

          <div
            className={ `mission-import-drop-zone${
              isDragging ? ' is-dragging' : ''
            }${ isUploading ? ' is-uploading' : '' }` }
            onDragOver={ ( e ) => {
              e.preventDefault();
              setIsDragging( true );
            } }
            onDragLeave={ () => setIsDragging( false ) }
            onDrop={ handleDrop }
            onClick={ () => fileInputRef.current?.click() }
            role="button"
            tabIndex={ 0 }
            onKeyDown={ ( e ) => {
              if ( e.key === 'Enter' || e.key === ' ' ) {
                e.preventDefault();
                fileInputRef.current?.click();
              }
            } }
          >
            <div className="mission-import-drop-zone__icon">
              <svg
                width="40"
                height="40"
                viewBox="0 0 40 40"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.4"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <path d="M26 26l-6-6-6 6" />
                <line x1="20" y1="20" x2="20" y2="34" />
                <path d="M34 28.3A8 8 0 0 0 28 14h-1.26A12 12 0 1 0 6 24c0 1.7.4 3.4 1 5" />
              </svg>
            </div>
            <div className="mission-import-drop-zone__text">
              { isUploading
                ? __( 'Reading file…', 'mission-donation-platform' )
                : __(
                    'Drag and drop your file here',
                    'mission-donation-platform'
                  ) }
            </div>
            { ! isUploading && (
              <>
                <div className="mission-import-drop-zone__or">
                  { __( 'or', 'mission-donation-platform' ) }
                </div>
                <button
                  className="mission-settings-secondary-btn"
                  type="button"
                  onClick={ ( e ) => {
                    e.stopPropagation();
                    fileInputRef.current?.click();
                  } }
                >
                  { __( 'Browse files', 'mission-donation-platform' ) }
                </button>
              </>
            ) }
            <div className="mission-import-drop-zone__hint">
              { __(
                'Supports .csv and .json files up to 10MB',
                'mission-donation-platform'
              ) }
            </div>
            <input
              ref={ fileInputRef }
              type="file"
              accept=".csv,.json"
              style={ { display: 'none' } }
              onChange={ handleFileChange }
            />
          </div>
        </div>

        <div className="mission-settings-card">
          <div className="mission-settings-card__header">
            <h2 className="mission-settings-card__title">
              { __( 'Download Templates', 'mission-donation-platform' ) }
            </h2>
            <p className="mission-settings-card__desc">
              { __(
                'Need a template? Download a blank CSV with the correct column headers.',
                'mission-donation-platform'
              ) }
            </p>
          </div>
          <div className="mission-import-template-links">
            { DATA_TYPES.map( ( type ) => (
              <button
                key={ type.value }
                className="mission-import-template-tag"
                type="button"
                onClick={ () => handleDownloadTemplate( type.value ) }
              >
                <DownloadIcon />
                { type.label }
              </button>
            ) ) }
          </div>
        </div>
      </div>
    );
  }

  // -----------------
  // Validation State
  // -----------------
  if ( 'validation' === uploadState && validation ) {
    const warningRows = new Set( validation.warning_rows || [] );
    const skippedRows = new Set( validation.skipped_row_numbers || [] );
    const totalRows = validation.rows_detected || 0;
    const rowsSkipped = validation.rows_skipped || 0;
    const importableRows = validation.rows_importable ?? totalRows;
    const duplicates = validation.duplicates || 0;
    const rowsWithoutGatewayId = validation.rows_without_gateway_id || 0;
    const newRows = Math.max( importableRows - duplicates, 0 );
    const allIssues = validation.warnings || [];
    const errors = allIssues.filter( ( w ) => w.severity === 'error' );
    const warnings = allIssues.filter( ( w ) => w.severity !== 'error' );

    let issueStatClass = 'is-success';
    if ( errors.length ) {
      issueStatClass = 'is-error';
    } else if ( warnings.length ) {
      issueStatClass = 'is-warning';
    }

    let actionCount;
    if ( 'skip' === duplicateStrategy ) {
      actionCount = newRows;
    } else if ( 'update' === duplicateStrategy && 0 === newRows ) {
      actionCount = duplicates;
    } else {
      actionCount = importableRows;
    }

    let summaryText;
    if ( 'skip' === duplicateStrategy ) {
      summaryText = sprintf(
        /* translators: 1: new row count, 2: duplicate count, 3: data type */
        __(
          'This will import %1$d new %3$s and skip %2$d duplicates. This action cannot be undone.',
          'mission-donation-platform'
        ),
        newRows,
        duplicates,
        typeLabel.toLowerCase()
      );
    } else {
      summaryText = sprintf(
        /* translators: 1: new row count, 2: duplicate count, 3: data type */
        __(
          'This will import %1$d new %3$s and update %2$d existing records. This action cannot be undone.',
          'mission-donation-platform'
        ),
        newRows,
        duplicates,
        typeLabel.toLowerCase()
      );
    }

    if ( rowsSkipped > 0 ) {
      summaryText +=
        ' ' +
        sprintf(
          /* translators: %d: number of rows being skipped due to errors */
          __(
            '%d row(s) with errors will be skipped.',
            'mission-donation-platform'
          ),
          rowsSkipped
        );
    }

    return (
      <div className="mission-settings-panel">
        { importError && (
          <div className="mission-import-error" role="alert">
            <WarningIcon />
            <span>{ importError }</span>
          </div>
        ) }
        <div className="mission-settings-card">
          <div className="mission-settings-card__header">
            <h2 className="mission-settings-card__title">
              { __( 'File Summary', 'mission-donation-platform' ) }
            </h2>
            <p className="mission-settings-card__desc">
              { __(
                'Review the data before importing.',
                'mission-donation-platform'
              ) }
            </p>
          </div>

          <div className="mission-import-file-info">
            <div className="mission-import-file-info__icon">
              <svg
                width="18"
                height="18"
                viewBox="0 0 18 18"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <path d="M10 1.5H4.5A1.5 1.5 0 0 0 3 3v12a1.5 1.5 0 0 0 1.5 1.5h9A1.5 1.5 0 0 0 15 15V6.5L10 1.5z" />
                <polyline points="10 1.5 10 6.5 15 6.5" />
              </svg>
            </div>
            <div className="mission-import-file-info__details">
              <div className="mission-import-file-info__name">
                { validation.filename }{ ' ' }
                <span className="mission-import-file-info__size">
                  { formatBytes( validation.filesize ) }
                </span>
              </div>
            </div>
            <button
              className="mission-import-file-info__remove"
              type="button"
              onClick={ resetToUpload }
            >
              { __( 'Remove', 'mission-donation-platform' ) }
            </button>
          </div>

          <div className="mission-import-stats">
            <div className="mission-import-stat">
              <div className="mission-import-stat__icon is-success">
                <CheckIcon />
              </div>
              <div>
                <div className="mission-import-stat__value">{ totalRows }</div>
                <div className="mission-import-stat__label">
                  { __( 'rows detected', 'mission-donation-platform' ) }
                </div>
              </div>
            </div>
            <div className="mission-import-stat">
              <div className="mission-import-stat__icon is-success">
                <CheckIcon />
              </div>
              <div>
                <div className="mission-import-stat__value">
                  { validation.columns_matched }
                </div>
                <div className="mission-import-stat__label">
                  { __( 'columns matched', 'mission-donation-platform' ) }
                </div>
              </div>
            </div>
            <div className="mission-import-stat">
              <div
                className={ `mission-import-stat__icon ${ issueStatClass }` }
              >
                { errors.length || warnings.length ? (
                  <WarningIcon />
                ) : (
                  <CheckIcon />
                ) }
              </div>
              <div>
                <div className="mission-import-stat__value">
                  { errors.length + warnings.length }
                </div>
                <div className="mission-import-stat__label">
                  { errors.length > 0
                    ? sprintf(
                        /* translators: 1: error count, 2: warning count */
                        __(
                          '%1$d error(s), %2$d warning(s)',
                          'mission-donation-platform'
                        ),
                        errors.length,
                        warnings.length
                      )
                    : __( 'warnings', 'mission-donation-platform' ) }
                </div>
              </div>
            </div>
          </div>

          { ( errors.length > 0 ||
            warnings.length > 0 ||
            duplicates > 0 ||
            rowsWithoutGatewayId > 0 ) && (
            <div className="mission-import-warnings">
              { errors.slice( 0, 10 ).map( ( w, idx ) => (
                <div
                  className="mission-import-warning-item is-error"
                  key={ `err-${ idx }` }
                >
                  <WarningIcon />
                  <span>
                    <strong>
                      { sprintf(
                        /* translators: %d: row number */
                        __( 'Row %d:', 'mission-donation-platform' ),
                        w.row
                      ) }
                    </strong>{ ' ' }
                    { w.message }
                  </span>
                </div>
              ) ) }
              { errors.length > 10 && (
                <div className="mission-import-warning-item is-error">
                  <WarningIcon />
                  <span>
                    { sprintf(
                      /* translators: %d: number of additional errors */
                      __( 'And %d more errors.', 'mission-donation-platform' ),
                      errors.length - 10
                    ) }
                  </span>
                </div>
              ) }
              { warnings.slice( 0, 10 ).map( ( w, idx ) => (
                <div
                  className="mission-import-warning-item"
                  key={ `warn-${ idx }` }
                >
                  <WarningIcon />
                  <span>
                    <strong>
                      { sprintf(
                        /* translators: %d: row number */
                        __( 'Row %d:', 'mission-donation-platform' ),
                        w.row
                      ) }
                    </strong>{ ' ' }
                    { w.message }
                  </span>
                </div>
              ) ) }
              { warnings.length > 10 && (
                <div className="mission-import-warning-item">
                  <WarningIcon />
                  <span>
                    { sprintf(
                      /* translators: %d: number of additional warnings */
                      __(
                        'And %d more warnings.',
                        'mission-donation-platform'
                      ),
                      warnings.length - 10
                    ) }
                  </span>
                </div>
              ) }
              { duplicates > 0 && (
                <div className="mission-import-warning-item">
                  <WarningIcon />
                  <span>
                    <strong>
                      { sprintf(
                        /* translators: 1: count, 2: type label */
                        __( '%1$d %2$s', 'mission-donation-platform' ),
                        duplicates,
                        typeLabel.toLowerCase()
                      ) }
                    </strong>{ ' ' }
                    { 'transactions' === dataType &&
                      __(
                        'already exist in your database (matched by Charge ID)',
                        'mission-donation-platform'
                      ) }
                    { 'campaigns' === dataType &&
                      __(
                        'already exist in your database (matched by title)',
                        'mission-donation-platform'
                      ) }
                    { 'subscriptions' === dataType &&
                      __(
                        'already exist in your database (matched by Subscription ID)',
                        'mission-donation-platform'
                      ) }
                    { 'tributes' === dataType &&
                      __(
                        'already exist in your database (matched by transaction)',
                        'mission-donation-platform'
                      ) }
                    { 'donors' === dataType &&
                      __(
                        'already exist in your database (matched by email)',
                        'mission-donation-platform'
                      ) }
                  </span>
                </div>
              ) }
              { 'transactions' === dataType && rowsWithoutGatewayId > 0 && (
                <div className="mission-import-warning-item">
                  <WarningIcon />
                  <span>
                    <strong>
                      { sprintf(
                        /* translators: %d: number of rows */
                        __(
                          '%d rows have no Charge ID',
                          'mission-donation-platform'
                        ),
                        rowsWithoutGatewayId
                      ) }
                    </strong>{ ' ' }
                    { __(
                      "and will be imported as new transactions every time. Remove these rows from your CSV before re-running if you don't want duplicates.",
                      'mission-donation-platform'
                    ) }
                  </span>
                </div>
              ) }
              { 'subscriptions' === dataType && rowsWithoutGatewayId > 0 && (
                <div className="mission-import-warning-item">
                  <WarningIcon />
                  <span>
                    <strong>
                      { sprintf(
                        /* translators: %d: number of rows */
                        __(
                          '%d rows have no Subscription ID',
                          'mission-donation-platform'
                        ),
                        rowsWithoutGatewayId
                      ) }
                    </strong>{ ' ' }
                    { __(
                      "and will be imported as new subscriptions every time. Remove these rows from your CSV before re-running if you don't want duplicates.",
                      'mission-donation-platform'
                    ) }
                  </span>
                </div>
              ) }
            </div>
          ) }
        </div>

        { 'campaigns' === dataType && (
          <div className="mission-import-callout">
            <div className="mission-import-callout__icon">
              <InfoIcon />
            </div>
            <div className="mission-import-callout__text">
              { __(
                'Each campaign creates its own campaign page. Raised totals, donor counts, and transaction counts start at zero and update automatically as you import or record transactions.',
                'mission-donation-platform'
              ) }
            </div>
          </div>
        ) }

        { 'subscriptions' === dataType && (
          <div className="mission-import-callout">
            <div className="mission-import-callout__icon">
              <InfoIcon />
            </div>
            <div className="mission-import-callout__text">
              { __(
                'Imported subscriptions are records only. No payments are charged, and renewal counts start at zero. A subscription only renews automatically if its Subscription ID matches a live subscription at your payment gateway.',
                'mission-donation-platform'
              ) }
            </div>
          </div>
        ) }

        { 'tributes' === dataType && (
          <div className="mission-import-callout">
            <div className="mission-import-callout__icon">
              <InfoIcon />
            </div>
            <div className="mission-import-callout__text">
              { __(
                'Each dedication attaches to an existing transaction, matched by Charge ID or transaction ID. Import transactions first; rows whose transaction cannot be found are skipped. No notification emails are sent when dedications are imported.',
                'mission-donation-platform'
              ) }
            </div>
          </div>
        ) }

        { duplicates > 0 && (
          <div className="mission-settings-card">
            <div className="mission-settings-card__header">
              <h2 className="mission-settings-card__title">
                { __( 'Import Options', 'mission-donation-platform' ) }
              </h2>
              <p className="mission-settings-card__desc">
                { __(
                  'Choose how to handle records that already exist.',
                  'mission-donation-platform'
                ) }
              </p>
            </div>
            <div className="mission-import-radio-group">
              { DUPLICATE_STRATEGIES.map( ( option ) => (
                <button
                  key={ option.value }
                  type="button"
                  className={ `mission-import-radio${
                    duplicateStrategy === option.value ? ' is-active' : ''
                  }` }
                  onClick={ () => setDuplicateStrategy( option.value ) }
                >
                  <span className="mission-import-radio__dot" />
                  <span>
                    <span className="mission-import-radio__label">
                      { option.label }
                    </span>
                    <span className="mission-import-radio__desc">
                      { option.desc }
                    </span>
                  </span>
                </button>
              ) ) }
            </div>
          </div>
        ) }

        <div className="mission-settings-card">
          <div className="mission-settings-card__header">
            <h2 className="mission-settings-card__title">
              { __( 'Data Preview', 'mission-donation-platform' ) }
            </h2>
            <p className="mission-settings-card__desc">
              { __(
                'First 5 rows from your uploaded file.',
                'mission-donation-platform'
              ) }
            </p>
          </div>
          <div className="mission-tools-table-wrap">
            <table className="mission-tools-preview-table mission-import-preview-table">
              <thead>
                <tr>
                  { ( validation.preview_headers ?? [] ).map( ( col ) => (
                    <th key={ col }>{ col }</th>
                  ) ) }
                </tr>
              </thead>
              <tbody>
                { ( validation.preview_rows ?? [] ).map( ( row, rowIdx ) => {
                  const rowNum = rowIdx + 1;
                  let cls = '';
                  if ( skippedRows.has( rowNum ) ) {
                    cls = 'is-error-row';
                  } else if ( warningRows.has( rowNum ) ) {
                    cls = 'is-warning-row';
                  }
                  return (
                    <tr key={ rowIdx } className={ cls }>
                      { row.map( ( cell, colIdx ) => (
                        <td
                          key={ colIdx }
                          className={
                            colIdx === 0
                              ? 'mission-tools-preview-table__strong'
                              : ''
                          }
                        >
                          { cell }
                        </td>
                      ) ) }
                    </tr>
                  );
                } ) }
              </tbody>
            </table>
          </div>
        </div>

        <div className="mission-import-actions">
          <div className="mission-import-actions__warning">
            <WarningIcon />
            <span>{ summaryText }</span>
          </div>
          <div className="mission-import-actions__buttons">
            <button
              className="mission-settings-secondary-btn"
              type="button"
              onClick={ resetToUpload }
            >
              { __( 'Cancel', 'mission-donation-platform' ) }
            </button>
            <button
              className="mission-settings-save-bar__btn"
              type="button"
              onClick={ executeImport }
              disabled={ actionCount === 0 }
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
                <polyline points="11 5 8 2 5 5" />
                <line x1="8" y1="2" x2="8" y2="10" />
              </svg>
              { 'update' === duplicateStrategy && 0 === newRows
                ? sprintf(
                    /* translators: 1: number, 2: type label */
                    __( 'Update %1$d %2$s', 'mission-donation-platform' ),
                    actionCount,
                    typeLabel
                  )
                : sprintf(
                    /* translators: 1: number, 2: type label */
                    __( 'Import %1$d %2$s', 'mission-donation-platform' ),
                    actionCount,
                    typeLabel
                  ) }
            </button>
          </div>
        </div>
      </div>
    );
  }

  // -----------------
  // Progress State
  // -----------------
  if ( 'progress' === uploadState ) {
    const total = jobStatus?.total_rows ?? 0;
    const realProcessed = jobStatus?.processed_rows ?? 0;
    const realImported = jobStatus?.imported ?? 0;
    const realSkipped = jobStatus?.skipped ?? 0;
    const realUpdated = jobStatus?.updated ?? 0;
    const realErrors = jobStatus?.errors ?? 0;
    const percentInt = Math.round( displayedPercent );

    // Scale counters by the displayed ramp so they count up alongside the bar
    // instead of jumping to final values on the first poll.
    const displayedFraction = total > 0 ? displayedPercent / 100 : 0;
    const processed = Math.min(
      realProcessed,
      Math.floor( total * displayedFraction )
    );
    const scale = ( value ) => scaleCounter( value, processed, realProcessed );
    const imported = scale( realImported );
    const updated = scale( realUpdated );
    const skipped = scale( realSkipped );
    const errs = scale( realErrors );
    const ringMode =
      jobStatus && TERMINAL_STATUSES.includes( jobStatus.status )
        ? 'finalizing'
        : 'determinate';
    const circumference = 2 * Math.PI * 52;
    const dashOffset = circumference * ( 1 - displayedPercent / 100 );

    return (
      <div className="mission-settings-panel">
        <div className="mission-settings-card">
          <div className="mission-import-progress">
            <div
              className={ `mission-import-progress__ring mode-${ ringMode }` }
            >
              <svg viewBox="0 0 120 120">
                <circle
                  className="mission-import-progress__ring-track"
                  cx="60"
                  cy="60"
                  r="52"
                />
                <circle
                  className="mission-import-progress__ring-fill"
                  cx="60"
                  cy="60"
                  r="52"
                  style={ {
                    strokeDasharray: circumference,
                    strokeDashoffset: dashOffset,
                  } }
                />
              </svg>
              <div className="mission-import-progress__word">
                { `${ percentInt }%` }
              </div>
            </div>
            <div className="mission-import-progress__title">
              { IMPORT_JOB_STATUS.CANCELLED === jobStatus?.status
                ? __( 'Cancelling…', 'mission-donation-platform' )
                : sprintf(
                    /* translators: %s: data type label */
                    __( 'Importing your %s', 'mission-donation-platform' ),
                    typeLabel.toLowerCase()
                  ) }
            </div>
            <div className="mission-import-progress__sub">
              { total > 0
                ? sprintf(
                    /* translators: 1: processed rows, 2: total rows */
                    __(
                      '%1$d of %2$d rows processed',
                      'mission-donation-platform'
                    ),
                    processed,
                    total
                  )
                : __( 'Starting up…', 'mission-donation-platform' ) }
            </div>
            <div className="mission-import-progress__counters">
              <div className="mission-import-progress__counter">
                <span className="mission-import-progress__counter-value">
                  { imported }
                </span>
                <span className="mission-import-progress__counter-label">
                  { __( 'imported', 'mission-donation-platform' ) }
                </span>
              </div>
              <div className="mission-import-progress__counter">
                <span className="mission-import-progress__counter-value">
                  { updated }
                </span>
                <span className="mission-import-progress__counter-label">
                  { __( 'updated', 'mission-donation-platform' ) }
                </span>
              </div>
              <div className="mission-import-progress__counter">
                <span className="mission-import-progress__counter-value">
                  { skipped }
                </span>
                <span className="mission-import-progress__counter-label">
                  { __( 'skipped', 'mission-donation-platform' ) }
                </span>
              </div>
              { errs > 0 && (
                <div className="mission-import-progress__counter is-error">
                  <span className="mission-import-progress__counter-value">
                    { errs }
                  </span>
                  <span className="mission-import-progress__counter-label">
                    { __( 'errors', 'mission-donation-platform' ) }
                  </span>
                </div>
              ) }
            </div>
            <div className="mission-import-progress__hint">
              { __(
                'You can safely leave this page — the import keeps running in the background. Come back any time to check progress.',
                'mission-donation-platform'
              ) }
            </div>
            <div className="mission-import-progress__actions">
              <button
                className="mission-settings-secondary-btn"
                type="button"
                onClick={ cancelImport }
                disabled={
                  isCancelling ||
                  ( jobStatus &&
                    TERMINAL_STATUSES.includes( jobStatus.status ) )
                }
              >
                { isCancelling
                  ? __( 'Cancelling…', 'mission-donation-platform' )
                  : __( 'Cancel import', 'mission-donation-platform' ) }
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  // -----------------
  // Failed State
  // -----------------
  if ( 'failed' === uploadState ) {
    return (
      <div className="mission-settings-panel">
        <div className="mission-settings-card">
          <div className="mission-import-success mission-import-failed">
            <div className="mission-import-success__icon is-error">
              <WarningIcon />
            </div>
            <div className="mission-import-success__title">
              { __( 'Import failed', 'mission-donation-platform' ) }
            </div>
            <div className="mission-import-success__text">
              { jobStatus?.last_error ||
                __(
                  'Something went wrong while running the import.',
                  'mission-donation-platform'
                ) }
            </div>
            { ( jobStatus?.imported > 0 ||
              jobStatus?.updated > 0 ||
              jobStatus?.skipped > 0 ) && (
              <div className="mission-import-success__text">
                { sprintf(
                  /* translators: 1: imported, 2: updated, 3: skipped */
                  __(
                    '%1$d imported, %2$d updated, %3$d skipped before the failure.',
                    'mission-donation-platform'
                  ),
                  jobStatus?.imported ?? 0,
                  jobStatus?.updated ?? 0,
                  jobStatus?.skipped ?? 0
                ) }
              </div>
            ) }
            <div className="mission-import-success__buttons">
              <button
                className="mission-settings-save-bar__btn"
                type="button"
                onClick={ resetToUpload }
              >
                { __( 'Try again', 'mission-donation-platform' ) }
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  // -----------------
  // Success State
  // -----------------
  if ( 'success' === uploadState && jobStatus ) {
    const importResult = jobStatus;
    const { imported = 0, updated = 0, skipped = 0, errors = 0 } = jobStatus;

    const lines = [];
    if ( imported > 0 ) {
      lines.push(
        sprintf(
          /* translators: 1: count, 2: data type */
          __(
            '%1$d %2$s were successfully imported.',
            'mission-donation-platform'
          ),
          imported,
          typeLabel.toLowerCase()
        )
      );
    }
    if ( updated > 0 ) {
      lines.push(
        sprintf(
          /* translators: %d: count */
          __(
            '%d existing records were updated.',
            'mission-donation-platform'
          ),
          updated
        )
      );
    }
    if ( skipped > 0 ) {
      lines.push(
        sprintf(
          /* translators: %d: count */
          __( '%d duplicates were skipped.', 'mission-donation-platform' ),
          skipped
        )
      );
    }
    if ( errors > 0 ) {
      lines.push(
        sprintf(
          /* translators: %d: count */
          __( '%d rows failed to import.', 'mission-donation-platform' ),
          errors
        )
      );
    }

    const viewPageSlug = {
      donors: 'mission-donation-platform-donors',
      transactions: 'mission-donation-platform-transactions',
      campaigns: 'mission-donation-platform-campaigns',
      subscriptions: 'mission-donation-platform-subscriptions',
    }[ dataType ];

    const viewUrl = viewPageSlug
      ? `${
          window.missiondpAdmin?.adminUrl ?? ''
        }admin.php?page=${ viewPageSlug }`
      : '';

    return (
      <div className="mission-settings-panel">
        <div className="mission-settings-card">
          <div className="mission-import-success">
            <div className="mission-import-success__icon">
              <svg
                width="28"
                height="28"
                viewBox="0 0 32 32"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <polyline points="8,16 14,22 24,10" />
              </svg>
            </div>
            <div className="mission-import-success__title">
              { __( 'Import complete', 'mission-donation-platform' ) }
            </div>
            <div className="mission-import-success__text">
              { lines.join( ' ' ) }
            </div>
            { errors > 0 && importResult.error_details?.length > 0 && (
              <details className="mission-import-success__errors">
                <summary>
                  { __( 'Show error details', 'mission-donation-platform' ) }
                </summary>
                <ul>
                  { importResult.error_details.map( ( e, idx ) => (
                    <li key={ idx }>
                      <strong>
                        { sprintf(
                          /* translators: %d: row number */
                          __( 'Row %d:', 'mission-donation-platform' ),
                          e.row
                        ) }
                      </strong>{ ' ' }
                      { e.message }
                    </li>
                  ) ) }
                </ul>
              </details>
            ) }
            <div className="mission-import-success__buttons">
              { viewUrl && (
                <a className="mission-settings-save-bar__btn" href={ viewUrl }>
                  { sprintf(
                    /* translators: %s: data type label */
                    __( 'View %s', 'mission-donation-platform' ),
                    typeLabel
                  ) }
                </a>
              ) }
              <button
                className="mission-settings-secondary-btn"
                type="button"
                onClick={ resetToUpload }
              >
                { __( 'Import more data', 'mission-donation-platform' ) }
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  return null;
}
