import { useState, useRef, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import Toast from '../../components/Toast';
import ConfirmationModal from './ConfirmationModal';
import SourceList from './migration/SourceList';
import MigrationStepper from './migration/MigrationStepper';
import IntroStage from './migration/IntroStage';
import ScanResults from './migration/ScanResults';
import ProgressTimeline from './migration/ProgressTimeline';
import MigrationReport from './migration/MigrationReport';

const POLL_INTERVAL_MS = 2000;
const TERMINAL_STATUSES = [ 'completed', 'failed', 'cancelled' ];

function SkeletonCard() {
  return (
    <div className="mission-settings-card">
      <div className="mission-migration-skeleton">
        <span
          className="mission-migration-skeleton__line"
          style={ { width: '40%' } }
        />
        <span
          className="mission-migration-skeleton__line"
          style={ { width: '70%' } }
        />
        <span className="mission-migration-skeleton__row" />
        <span className="mission-migration-skeleton__row" />
        <span className="mission-migration-skeleton__row" />
      </div>
    </div>
  );
}

export default function MigrationPanel( { onSwitchTab } ) {
  const [ view, setView ] = useState( 'loading' ); // loading | select | intro | results | progress | report
  const [ sources, setSources ] = useState( [] );
  const [ selected, setSelected ] = useState( null );
  const [ scan, setScan ] = useState( null );
  const [ isScanning, setIsScanning ] = useState( false );
  const [ scanError, setScanError ] = useState( '' );
  const [ includeTest, setIncludeTest ] = useState( false );
  const [ job, setJob ] = useState( null );
  const [ startError, setStartError ] = useState( '' );
  const [ isCancelling, setIsCancelling ] = useState( false );
  const [ showRollbackConfirm, setShowRollbackConfirm ] = useState( false );
  const [ isRollingBack, setIsRollingBack ] = useState( false );
  const [ toast, setToast ] = useState( null );
  const pollTimer = useRef( null );

  const isRollbackJob = 'rollback' === job?.job_type;

  const sourceFor = useCallback(
    ( sourceId ) =>
      sources.find( ( source ) => source.id === sourceId ) ?? {
        id: sourceId,
        name: sourceId,
      },
    [ sources ]
  );

  const stopPolling = useCallback( () => {
    if ( pollTimer.current ) {
      clearInterval( pollTimer.current );
      pollTimer.current = null;
    }
  }, [] );

  useEffect( () => () => stopPolling(), [ stopPolling ] );

  // Prefetch sources and any existing run in parallel, then route to the
  // right view: resume a running job, show the latest receipt, or start fresh.
  useEffect( () => {
    let cancelled = false;

    Promise.allSettled( [
      apiFetch( { path: '/mission-donation-platform/v1/migration/sources' } ),
      apiFetch( { path: '/mission-donation-platform/v1/migration/status' } ),
    ] ).then( ( [ sourcesResult, statusResult ] ) => {
      if ( cancelled ) {
        return;
      }

      const loadedSources =
        'fulfilled' === sourcesResult.status ? sourcesResult.value : [];
      setSources( loadedSources );

      const status =
        'fulfilled' === statusResult.status ? statusResult.value : null;

      if ( ! status ) {
        setView( 'select' );
        return;
      }

      const sourceMatch = loadedSources.find(
        ( source ) => source.id === status.source
      );
      if ( sourceMatch ) {
        setSelected( sourceMatch );
      }

      if ( ! TERMINAL_STATUSES.includes( status.status ) ) {
        setJob( status );
        setView( 'progress' );
        return;
      }

      if ( 'migrate' === status.job_type ) {
        setJob( status );
        setView( 'report' );
        return;
      }

      // A finished rollback leaves nothing to show.
      setView( 'select' );
    } );

    return () => {
      cancelled = true;
    };
  }, [] );

  // Poll status while a job is in flight.
  const jobId = job?.job_id;
  const jobStatus = job?.status;
  useEffect( () => {
    if ( ! jobId || TERMINAL_STATUSES.includes( jobStatus ) ) {
      stopPolling();
      return undefined;
    }
    const tick = () => {
      apiFetch( {
        path: `/mission-donation-platform/v1/migration/status?job_id=${ encodeURIComponent(
          jobId
        ) }`,
      } )
        .then( ( data ) => setJob( data ) )
        .catch( () => {
          // Network blip — keep polling.
        } );
    };
    tick();
    pollTimer.current = setInterval( tick, POLL_INTERVAL_MS );
    return () => stopPolling();
  }, [ jobId, jobStatus, stopPolling ] );

  // Route to the right view when a job reaches a terminal state.
  useEffect( () => {
    if ( ! jobStatus || ! TERMINAL_STATUSES.includes( jobStatus ) ) {
      return;
    }

    if ( isRollbackJob ) {
      setJob( null );
      setView( 'select' );
      setToast(
        'completed' === jobStatus
          ? {
              type: 'success',
              message: __(
                'Migrated data has been removed.',
                'mission-donation-platform'
              ),
            }
          : {
              type: 'error',
              message: __(
                'The reset did not finish. Try again from the receipt.',
                'mission-donation-platform'
              ),
            }
      );
      return;
    }

    if ( 'progress' === view ) {
      setView( 'report' );
    }
  }, [ jobStatus, isRollbackJob, view ] );

  const handleSelect = ( source ) => {
    setSelected( source );
    setScan( null );
    setScanError( '' );
    setView( 'intro' );
  };

  const runScan = useCallback(
    ( { andShowResults = true } = {} ) => {
      if ( ! selected || isScanning ) {
        return;
      }
      setIsScanning( true );
      setScanError( '' );
      apiFetch( {
        path: '/mission-donation-platform/v1/migration/scan',
        method: 'POST',
        data: { source: selected.id },
      } )
        .then( ( data ) => {
          setScan( data );
          if ( andShowResults ) {
            setView( 'results' );
          }
        } )
        .catch( ( err ) => {
          setScanError(
            err?.message ||
              __(
                'The scan could not be completed.',
                'mission-donation-platform'
              )
          );
        } )
        .finally( () => setIsScanning( false ) );
    },
    [ selected, isScanning ]
  );

  const startMigration = useCallback( () => {
    if ( ! selected ) {
      return;
    }
    setStartError( '' );
    apiFetch( {
      path: '/mission-donation-platform/v1/migration/start',
      method: 'POST',
      data: { source: selected.id, include_test: includeTest },
    } )
      .then( ( data ) => {
        setJob( data );
        setView( 'progress' );
      } )
      .catch( ( err ) => {
        // A run already exists — resume into it instead of erroring.
        if ( 'migration_in_progress' === err?.code ) {
          apiFetch( {
            path: '/mission-donation-platform/v1/migration/status',
          } ).then( ( data ) => {
            setJob( data );
            setView( 'progress' );
          } );
          return;
        }
        setStartError(
          err?.message ||
            __(
              'The migration could not be started.',
              'mission-donation-platform'
            )
        );
      } );
  }, [ selected, includeTest ] );

  const cancelMigration = useCallback( () => {
    if ( ! jobId || isCancelling ) {
      return;
    }
    setIsCancelling( true );
    apiFetch( {
      path: '/mission-donation-platform/v1/migration/cancel',
      method: 'POST',
      data: { job_id: jobId },
    } )
      .then( ( data ) => setJob( data ) )
      .catch( () => {
        // Polling will catch up eventually.
      } )
      .finally( () => setIsCancelling( false ) );
  }, [ jobId, isCancelling ] );

  const startRollback = useCallback( () => {
    if ( ! jobId || isRollingBack ) {
      return;
    }
    setIsRollingBack( true );
    apiFetch( {
      path: '/mission-donation-platform/v1/migration/rollback',
      method: 'POST',
      data: { job_id: jobId },
    } )
      .then( ( data ) => {
        setShowRollbackConfirm( false );
        setJob( data );
        setView( 'progress' );
      } )
      .catch( ( err ) => {
        setShowRollbackConfirm( false );
        setToast( {
          type: 'error',
          message:
            err?.message ||
            __(
              'The reset could not be started.',
              'mission-donation-platform'
            ),
        } );
      } )
      .finally( () => setIsRollingBack( false ) );
  }, [ jobId, isRollingBack ] );

  const backToSelect = () => {
    setJob( null );
    setScan( null );
    setIncludeTest( false );
    setStartError( '' );
    setView( 'select' );
  };

  const sourceName = selected?.name ?? sourceFor( job?.source ?? '' ).name;

  const stepForView = { intro: 1, results: 1, progress: 2, report: 3 };

  return (
    <div className="mission-settings-panel">
      { 'loading' === view && <SkeletonCard /> }

      { 'select' === view && (
        <SourceList
          sources={ sources }
          onSelect={ handleSelect }
          onSwitchTab={ onSwitchTab }
        />
      ) }

      { stepForView[ view ] && (
        <MigrationStepper current={ isRollbackJob ? 2 : stepForView[ view ] } />
      ) }

      { 'intro' === view && selected && (
        <IntroStage
          source={ selected }
          isScanning={ isScanning }
          scanError={ scanError }
          onScan={ () => runScan() }
          onChooseOther={ backToSelect }
        />
      ) }

      { 'results' === view && selected && scan && (
        <ScanResults
          source={ selected }
          scan={ scan }
          includeTest={ includeTest }
          onIncludeTestChange={ setIncludeTest }
          isRescanning={ isScanning }
          onRescan={ () => runScan( { andShowResults: false } ) }
          startError={ startError }
          onBack={ () => setView( 'intro' ) }
          onStart={ startMigration }
        />
      ) }

      { 'progress' === view && (
        <ProgressTimeline
          job={ job }
          isRollback={ isRollbackJob }
          isCancelling={ isCancelling }
          onCancel={ cancelMigration }
        />
      ) }

      { 'report' === view && job && (
        <MigrationReport
          job={ job }
          sourceName={ sourceName }
          onRollback={ () => setShowRollbackConfirm( true ) }
          onMigrateAnother={ backToSelect }
        />
      ) }

      { showRollbackConfirm && (
        <ConfirmationModal
          title={ __( 'Reset migration', 'mission-donation-platform' ) }
          message={ sprintf(
            /* translators: %s: source plugin name. */
            __(
              'This permanently deletes every donor, transaction, campaign, and subscription this migration created in Mission. Records that existed in Mission before the migration are kept, and your %s data is not touched.',
              'mission-donation-platform'
            ),
            sourceName
          ) }
          confirmLabel={ __(
            'Delete migrated data',
            'mission-donation-platform'
          ) }
          typedConfirm="DELETE"
          isDanger
          isRunning={ isRollingBack }
          onConfirm={ startRollback }
          onCancel={ () => setShowRollbackConfirm( false ) }
        />
      ) }

      <Toast notice={ toast } onDone={ () => setToast( null ) } />
    </div>
  );
}
