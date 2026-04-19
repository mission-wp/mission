import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import Toast from '../../components/Toast';
import ConfirmationModal from './ConfirmationModal';

const SECTIONS = [
  {
    id: 'cache',
    title: __( 'Cache & Transients', 'missionwp-donation-platform' ),
    description: __(
      "Clear cached data. This is safe and won't remove any donor or transaction records.",
      'missionwp-donation-platform'
    ),
    actions: [
      {
        id: 'clear_dashboard_cache',
        label: __( 'Clear dashboard cache', 'missionwp-donation-platform' ),
        description: __(
          'Refresh cached stats, chart data, and campaign totals on the dashboard.',
          'missionwp-donation-platform'
        ),
        successMessage: __(
          'Dashboard cache cleared.',
          'missionwp-donation-platform'
        ),
      },
      {
        id: 'clear_email_template_cache',
        label: __(
          'Clear email template cache',
          'missionwp-donation-platform'
        ),
        description: __(
          'Force email templates to regenerate. Useful if receipt styles appear outdated.',
          'missionwp-donation-platform'
        ),
        successMessage: __(
          'Email template cache cleared.',
          'missionwp-donation-platform'
        ),
      },
      {
        id: 'clear_stripe_sync_cache',
        label: __( 'Flush Stripe sync cache', 'missionwp-donation-platform' ),
        description: __(
          'Clear cached Stripe data and re-sync subscription statuses on next page load.',
          'missionwp-donation-platform'
        ),
        successMessage: __(
          'Stripe sync cache cleared.',
          'missionwp-donation-platform'
        ),
      },
    ],
  },
  {
    id: 'logs',
    title: __( 'Logs & History', 'missionwp-donation-platform' ),
    description: __(
      'Remove activity history. This does not affect donor or transaction data.',
      'missionwp-donation-platform'
    ),
    actions: [
      {
        id: 'clear_activity_log',
        label: __( 'Clear activity log', 'missionwp-donation-platform' ),
        getDescription: ( stats ) => {
          const count = stats?.activity_log_count;
          if ( count === undefined ) {
            return __(
              'Remove all entries from the activity feed.',
              'missionwp-donation-platform'
            );
          }
          return sprintf(
            /* translators: %s: entry count with HTML markup */
            __(
              'Remove all entries from the activity feed. %s currently stored.',
              'missionwp-donation-platform'
            ),
            `<strong>${ count.toLocaleString() } ${
              count === 1
                ? __( 'entry', 'missionwp-donation-platform' )
                : __( 'entries', 'missionwp-donation-platform' )
            }</strong>`
          );
        },
        getConfirmMessage: ( stats ) =>
          sprintf(
            /* translators: %s: number of entries */
            __(
              'This will permanently delete all %s activity log entries.',
              'missionwp-donation-platform'
            ),
            ( stats?.activity_log_count || 0 ).toLocaleString()
          ),
        successMessage: __(
          'Activity log cleared.',
          'missionwp-donation-platform'
        ),
      },
    ],
  },
  {
    id: 'test-data',
    title: __( 'Test Data', 'missionwp-donation-platform' ),
    description: __(
      'Remove data created while in Stripe test mode. Only test mode data is affected.',
      'missionwp-donation-platform'
    ),
    variant: 'warn',
    actions: [
      {
        id: 'delete_test_transactions',
        label: __( 'Delete test transactions', 'missionwp-donation-platform' ),
        getDescription: ( stats ) => {
          const count = stats?.test_transaction_count;
          if ( count === undefined ) {
            return __(
              'Remove all transactions made with Stripe test mode keys.',
              'missionwp-donation-platform'
            );
          }
          return sprintf(
            /* translators: %s: count with HTML markup */
            __(
              'Remove all transactions made with Stripe test mode keys. %s found.',
              'missionwp-donation-platform'
            ),
            `<strong>${ count.toLocaleString() } ${ __(
              'test transactions',
              'missionwp-donation-platform'
            ) }</strong>`
          );
        },
        getConfirmMessage: ( stats ) =>
          sprintf(
            /* translators: %s: number of test transactions */
            __(
              'This will permanently delete %s test transactions. This cannot be undone.',
              'missionwp-donation-platform'
            ),
            ( stats?.test_transaction_count || 0 ).toLocaleString()
          ),
        successMessage: __(
          'Test transactions deleted.',
          'missionwp-donation-platform'
        ),
      },
      {
        id: 'delete_test_donors',
        label: __( 'Delete test donors', 'missionwp-donation-platform' ),
        getDescription: ( stats ) => {
          const count = stats?.test_donor_count;
          if ( count === undefined ) {
            return __(
              'Remove donors that only have test mode transactions (no live donations).',
              'missionwp-donation-platform'
            );
          }
          return sprintf(
            /* translators: %s: count with HTML markup */
            __(
              'Remove donors that only have test mode transactions (no live donations). %s found.',
              'missionwp-donation-platform'
            ),
            `<strong>${ count.toLocaleString() } ${ __(
              'test-only donors',
              'missionwp-donation-platform'
            ) }</strong>`
          );
        },
        getConfirmMessage: ( stats ) =>
          sprintf(
            /* translators: %s: number of test donors */
            __(
              'This will permanently delete %s test-only donors and their associated data. This cannot be undone.',
              'missionwp-donation-platform'
            ),
            ( stats?.test_donor_count || 0 ).toLocaleString()
          ),
        successMessage: __(
          'Test donors deleted.',
          'missionwp-donation-platform'
        ),
      },
      {
        id: 'delete_all_test_data',
        label: __( 'Delete all test data', 'missionwp-donation-platform' ),
        description: __(
          'Remove all test transactions, test donors, and test subscriptions in one step.',
          'missionwp-donation-platform'
        ),
        getConfirmMessage: ( stats ) =>
          sprintf(
            /* translators: %1$s: transaction count, %2$s: donor count, %3$s: subscription count */
            __(
              'This will permanently delete all test mode data (%1$s transactions, %2$s donors, %3$s subscriptions). This cannot be undone.',
              'missionwp-donation-platform'
            ),
            ( stats?.test_transaction_count || 0 ).toLocaleString(),
            ( stats?.test_donor_count || 0 ).toLocaleString(),
            ( stats?.test_subscription_count || 0 ).toLocaleString()
          ),
        successMessage: __(
          'All test data deleted.',
          'missionwp-donation-platform'
        ),
      },
    ],
  },
  {
    id: 'danger',
    title: __( 'Danger Zone', 'missionwp-donation-platform' ),
    description: __(
      'These actions are irreversible and will permanently delete data.',
      'missionwp-donation-platform'
    ),
    variant: 'danger',
    actions: [
      {
        id: 'reset_all_settings',
        label: __( 'Reset all settings', 'missionwp-donation-platform' ),
        description: __(
          'Restore all MissionWP settings to their defaults. Stripe will be disconnected. Donor and transaction data is preserved.',
          'missionwp-donation-platform'
        ),
        confirmMessage: __(
          'This will reset ALL MissionWP settings to defaults and disconnect Stripe. Your donor and transaction data will NOT be deleted, but you will need to reconfigure the plugin.',
          'missionwp-donation-platform'
        ),
        isDanger: true,
        successMessage: __(
          'All settings reset to defaults.',
          'missionwp-donation-platform'
        ),
      },
      {
        id: 'delete_all_data',
        label: __( 'Delete all MissionWP data', 'missionwp-donation-platform' ),
        description: __(
          'Completely remove all donors, transactions, campaigns, subscriptions, and settings. This is equivalent to a fresh install.',
          'missionwp-donation-platform'
        ),
        confirmMessage: __(
          'This will PERMANENTLY DELETE all MissionWP data including donors, transactions, campaigns, subscriptions, and settings. This action CANNOT be undone.',
          'missionwp-donation-platform'
        ),
        isDanger: true,
        typedConfirm: 'DELETE',
        successMessage: __(
          'All MissionWP data has been deleted.',
          'missionwp-donation-platform'
        ),
        isLast: true,
      },
    ],
  },
];

function SkeletonBar( { width = '60%' } ) {
  return (
    <span
      className="mission-skeleton"
      style={ {
        display: 'inline-block',
        width,
        height: '12px',
        borderRadius: '4px',
        background: '#e2e4e9',
        verticalAlign: 'middle',
      } }
    />
  );
}

function ActionRow( {
  action,
  stats,
  loadingStats,
  isRunning,
  onRun,
  sectionVariant,
} ) {
  // Show skeleton for description if stats are still loading and this action uses dynamic stats.
  const showDescSkeleton = loadingStats && action.getDescription;
  let desc = action.description;
  if ( ! showDescSkeleton && action.getDescription ) {
    desc = action.getDescription( stats );
  }

  const btnVariant = action.buttonVariant || sectionVariant;

  return (
    <div
      className={ `mission-cleanup-action${
        action.isLast ? ' mission-cleanup-action--last' : ''
      }` }
    >
      <div className="mission-cleanup-action__info">
        <div className="mission-cleanup-action__name">{ action.label }</div>
        { showDescSkeleton ? (
          <div className="mission-cleanup-action__desc">
            <SkeletonBar width="75%" />
          </div>
        ) : (
          <div
            className="mission-cleanup-action__desc"
            dangerouslySetInnerHTML={ { __html: desc } }
          />
        ) }
      </div>
      <button
        className={ `mission-btn-secondary mission-cleanup-action__btn${
          btnVariant === 'warn' ? ' mission-cleanup-action__btn--warn' : ''
        }${
          btnVariant === 'danger' || action.isDanger
            ? ' mission-cleanup-action__btn--danger'
            : ''
        }` }
        disabled={ isRunning }
        onClick={ () => onRun( action ) }
      >
        { isRunning
          ? __( 'Running…', 'missionwp-donation-platform' )
          : __( 'Run', 'missionwp-donation-platform' ) }
      </button>
    </div>
  );
}

export default function CleanupPanel() {
  const [ stats, setStats ] = useState( null );
  const [ loadingStats, setLoadingStats ] = useState( true );
  const [ runningAction, setRunningAction ] = useState( null );
  const [ confirmAction, setConfirmAction ] = useState( null );
  const [ toast, setToast ] = useState( null );
  const [ toastKey, setToastKey ] = useState( 0 );

  const fetchStats = useCallback( () => {
    apiFetch( { path: '/mission/v1/cleanup/stats' } )
      .then( ( data ) => {
        setStats( data );
        setLoadingStats( false );
      } )
      .catch( () => setLoadingStats( false ) );
  }, [] );

  useEffect( () => {
    fetchStats();
  }, [ fetchStats ] );

  const executeAction = async ( action, confirmation ) => {
    setRunningAction( action.id );
    setConfirmAction( null );

    try {
      await apiFetch( {
        path: `/mission/v1/cleanup/${ action.id }`,
        method: 'POST',
        data: confirmation ? { confirmation } : undefined,
      } );

      setToastKey( ( k ) => k + 1 );
      setToast( { type: 'success', message: action.successMessage } );
      fetchStats();
    } catch ( err ) {
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'error',
        message:
          err.message ||
          __( 'Something went wrong.', 'missionwp-donation-platform' ),
      } );
    } finally {
      setRunningAction( null );
    }
  };

  const handleRun = ( action ) => {
    const needsConfirm =
      action.confirmMessage || action.getConfirmMessage || action.typedConfirm;
    if ( ! needsConfirm ) {
      executeAction( action );
    } else {
      setConfirmAction( action );
    }
  };

  const getConfirmMessage = ( action ) => {
    if ( action.getConfirmMessage ) {
      return action.getConfirmMessage( stats );
    }
    return action.confirmMessage;
  };

  return (
    <div className="mission-settings-panel">
      { SECTIONS.map( ( section ) => (
        <div
          key={ section.id }
          className={ `mission-settings-card${
            section.variant === 'danger' ? ' mission-cleanup-danger-card' : ''
          }` }
        >
          <div className="mission-settings-card__header">
            <h2
              className={ `mission-settings-card__title${
                section.variant === 'danger'
                  ? ' mission-cleanup-danger-title'
                  : ''
              }` }
            >
              { section.title }
            </h2>
            <p className="mission-settings-card__desc">
              { section.description }
            </p>
          </div>

          { section.actions.map( ( action ) => (
            <ActionRow
              key={ action.id }
              action={ action }
              stats={ stats }
              loadingStats={ loadingStats }
              isRunning={ runningAction === action.id }
              onRun={ handleRun }
              sectionVariant={ section.variant }
            />
          ) ) }
        </div>
      ) ) }

      { confirmAction && (
        <ConfirmationModal
          title={ confirmAction.label }
          message={ getConfirmMessage( confirmAction ) }
          typedConfirm={ confirmAction.typedConfirm }
          isDanger={ confirmAction.isDanger }
          isRunning={ runningAction === confirmAction.id }
          onConfirm={ ( confirmation ) =>
            executeAction( confirmAction, confirmation )
          }
          onCancel={ () => setConfirmAction( null ) }
        />
      ) }

      <Toast
        key={ toastKey }
        notice={ toast }
        onDone={ () => setToast( null ) }
      />
    </div>
  );
}
