import { useState, useRef, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

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
];

const DUPLICATE_STRATEGIES = [
  {
    value: 'skip',
    label: __( 'Skip duplicates', 'mission-donation-platform' ),
    desc: __(
      'If a record with the same email already exists, skip the imported row.',
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
  {
    value: 'create',
    label: __( 'Create new', 'mission-donation-platform' ),
    desc: __(
      'Always create a new record, even if a duplicate may exist.',
      'mission-donation-platform'
    ),
  },
];

const MAX_BYTES = 10 * 1024 * 1024;

function formatBytes( bytes ) {
  if ( bytes < 1024 ) {
    return `${ bytes } B`;
  }
  if ( bytes < 1024 * 1024 ) {
    return `${ ( bytes / 1024 ).toFixed( 1 ) } KB`;
  }
  return `${ ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) } MB`;
}

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

const PROGRESS_STEPS = [
  {
    key: 'reading',
    label: __( 'Reading file', 'mission-donation-platform' ),
    sub: __( 'Reading file…', 'mission-donation-platform' ),
    word: __( 'Reading', 'mission-donation-platform' ),
    mode: 'indeterminate',
  },
  {
    key: 'validating',
    label: __( 'Validating records', 'mission-donation-platform' ),
    sub: __( 'Validating records…', 'mission-donation-platform' ),
    word: __( 'Validating', 'mission-donation-platform' ),
    mode: 'indeterminate',
  },
  {
    key: 'creating',
    label: __( 'Creating donor profiles', 'mission-donation-platform' ),
    sub: __( 'Creating donor profiles…', 'mission-donation-platform' ),
    word: __( 'Importing', 'mission-donation-platform' ),
    mode: 'indeterminate',
  },
  {
    key: 'finalizing',
    label: __( 'Updating donor totals', 'mission-donation-platform' ),
    sub: __( 'Finishing up…', 'mission-donation-platform' ),
    word: __( 'Finalizing', 'mission-donation-platform' ),
    mode: 'finalizing',
  },
];

export default function ImportPanel() {
  const [ dataType, setDataType ] = useState( 'donors' );
  const [ expectedColumns, setExpectedColumns ] = useState( [] );
  const [ uploadState, setUploadState ] = useState( 'upload' ); // 'upload' | 'validation' | 'progress' | 'success'
  const [ isUploading, setIsUploading ] = useState( false );
  const [ uploadError, setUploadError ] = useState( '' );
  const [ validation, setValidation ] = useState( null );
  const [ duplicateStrategy, setDuplicateStrategy ] = useState( 'skip' );
  const [ isDragging, setIsDragging ] = useState( false );
  const [ progressStep, setProgressStep ] = useState( 0 );
  const [ importResult, setImportResult ] = useState( null );
  const [ importError, setImportError ] = useState( '' );
  const fileInputRef = useRef( null );
  const progressTimer = useRef( null );

  const typeLabel =
    DATA_TYPES.find( ( t ) => t.value === dataType )?.label ?? '';

  useEffect( () => {
    apiFetch( {
      path: `/mission-donation-platform/v1/import/columns?type=${ dataType }`,
    } )
      .then( ( data ) => setExpectedColumns( data.columns || [] ) )
      .catch( () => setExpectedColumns( [] ) );
  }, [ dataType ] );

  const resetToUpload = useCallback( () => {
    setUploadState( 'upload' );
    setValidation( null );
    setUploadError( '' );
    setDuplicateStrategy( 'skip' );
    setImportResult( null );
    setImportError( '' );
    setProgressStep( 0 );
    if ( progressTimer.current ) {
      clearInterval( progressTimer.current );
      progressTimer.current = null;
    }
    if ( fileInputRef.current ) {
      fileInputRef.current.value = '';
    }
  }, [] );

  useEffect( () => {
    return () => {
      if ( progressTimer.current ) {
        clearInterval( progressTimer.current );
      }
    };
  }, [] );

  const executeImport = useCallback( () => {
    if ( ! validation?.file_id ) {
      return;
    }

    setUploadState( 'progress' );
    setImportError( '' );
    setImportResult( null );
    setProgressStep( 0 );

    progressTimer.current = setInterval( () => {
      setProgressStep( ( prev ) =>
        prev < PROGRESS_STEPS.length - 2 ? prev + 1 : prev
      );
    }, 900 );

    apiFetch( {
      path: '/mission-donation-platform/v1/import/execute',
      method: 'POST',
      data: {
        file_id: validation.file_id,
        duplicate_strategy: duplicateStrategy,
      },
    } )
      .then( ( data ) => {
        if ( progressTimer.current ) {
          clearInterval( progressTimer.current );
          progressTimer.current = null;
        }
        setProgressStep( PROGRESS_STEPS.length - 1 );
        setTimeout( () => {
          setImportResult( data );
          setUploadState( 'success' );
        }, 450 );
      } )
      .catch( ( err ) => {
        if ( progressTimer.current ) {
          clearInterval( progressTimer.current );
          progressTimer.current = null;
        }
        setImportError(
          err?.message ||
            __(
              'The import could not be completed.',
              'mission-donation-platform'
            )
        );
        setUploadState( 'validation' );
      } );
  }, [ validation, duplicateStrategy ] );

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
              { __( 'Expected columns:', 'mission-donation-platform' ) }{ ' ' }
              { expectedColumns.map( ( col, idx ) => (
                <span key={ col }>
                  <code>{ col }</code>
                  { idx < expectedColumns.length - 1 ? ', ' : '' }
                </span>
              ) ) }
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
    } else if ( 'update' === duplicateStrategy ) {
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
    } else {
      summaryText = sprintf(
        /* translators: 1: total row count, 2: data type */
        __(
          'This will create %1$d %2$s, including any duplicates. This action cannot be undone.',
          'mission-donation-platform'
        ),
        importableRows,
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

          { ( errors.length > 0 || warnings.length > 0 || duplicates > 0 ) && (
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
                    { __(
                      'already exist in your database (matched by email)',
                      'mission-donation-platform'
                    ) }
                  </span>
                </div>
              ) }
            </div>
          ) }
        </div>

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
              { DUPLICATE_STRATEGIES.filter(
                ( option ) => 'create' !== option.value || 'donors' !== dataType
              ).map( ( option ) => (
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
              disabled={ actionCount === 0 || dataType !== 'donors' }
              title={
                dataType !== 'donors'
                  ? __(
                      'Only donor imports are supported right now.',
                      'mission-donation-platform'
                    )
                  : ''
              }
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
    const current = PROGRESS_STEPS[ progressStep ] ?? PROGRESS_STEPS[ 0 ];
    return (
      <div className="mission-settings-panel">
        <div className="mission-settings-card">
          <div className="mission-import-progress">
            <div
              className={ `mission-import-progress__ring mode-${ current.mode }` }
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
                />
                <circle
                  className="mission-import-progress__ring-sweep"
                  cx="60"
                  cy="60"
                  r="52"
                />
              </svg>
              <div className="mission-import-progress__word">
                { current.word }
              </div>
            </div>
            <div className="mission-import-progress__title">
              { sprintf(
                /* translators: %s: data type label */
                __( 'Importing your %s', 'mission-donation-platform' ),
                typeLabel.toLowerCase()
              ) }
            </div>
            <div className="mission-import-progress__sub">{ current.sub }</div>
            <div className="mission-import-progress__steps">
              { PROGRESS_STEPS.map( ( step, idx ) => {
                let cls = '';
                if ( idx < progressStep ) {
                  cls = 'is-done';
                } else if ( idx === progressStep ) {
                  cls = 'is-active';
                }
                return (
                  <div
                    className={ `mission-import-progress__step ${ cls }` }
                    key={ step.key }
                  >
                    <span className="mission-import-progress__step-marker" />
                    <span className="mission-import-progress__step-label">
                      { step.label }
                    </span>
                  </div>
                );
              } ) }
            </div>
            <div className="mission-import-progress__hint">
              { __(
                'Large imports can take a few minutes. Please keep this tab open until the import finishes.',
                'mission-donation-platform'
              ) }
            </div>
          </div>
        </div>
      </div>
    );
  }

  // -----------------
  // Success State
  // -----------------
  if ( 'success' === uploadState && importResult ) {
    const { imported = 0, updated = 0, skipped = 0, errors = 0 } = importResult;

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

    const viewUrl =
      'donors' === dataType
        ? `${
            window.missiondpAdmin?.adminUrl ?? ''
          }admin.php?page=mission-donation-platform-donors`
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
