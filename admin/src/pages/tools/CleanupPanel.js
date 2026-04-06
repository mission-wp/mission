import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import Toast from '../../components/Toast';
import ConfirmationModal from './ConfirmationModal';

const SECTIONS = [
  {
    id: 'cache',
    title: __( 'Cache & Transients', 'mission' ),
    description: __(
      "Clear cached data. This is safe and won't remove any donor or transaction records.",
      'mission'
    ),
    actions: [
      {
        id: 'clear_dashboard_cache',
        label: __( 'Clear dashboard cache', 'mission' ),
        description: __(
          'Refresh cached stats, chart data, and campaign totals on the dashboard.',
          'mission'
        ),
        successMessage: __( 'Dashboard cache cleared.', 'mission' ),
      },
      {
        id: 'clear_email_template_cache',
        label: __( 'Clear email template cache', 'mission' ),
        description: __(
          'Force email templates to regenerate. Useful if receipt styles appear outdated.',
          'mission'
        ),
        successMessage: __( 'Email template cache cleared.', 'mission' ),
      },
      {
        id: 'clear_stripe_sync_cache',
        label: __( 'Flush Stripe sync cache', 'mission' ),
        description: __(
          'Clear cached Stripe data and re-sync subscription statuses on next page load.',
          'mission'
        ),
        successMessage: __( 'Stripe sync cache cleared.', 'mission' ),
      },
    ],
  },
  {
    id: 'logs',
    title: __( 'Logs & History', 'mission' ),
    description: __(
      'Remove activity history. This does not affect donor or transaction data.',
      'mission'
    ),
    actions: [
      {
        id: 'clear_activity_log',
        label: __( 'Clear activity log', 'mission' ),
        getDescription: ( stats ) => {
          const count = stats?.activity_log_count;
          if ( count === undefined ) {
            return __(
              'Remove all entries from the activity feed.',
              'mission'
            );
          }
          return sprintf(
            /* translators: %s: entry count with HTML markup */
            __(
              'Remove all entries from the activity feed. %s currently stored.',
              'mission'
            ),
            `<strong>${ count.toLocaleString() } ${
              count === 1
                ? __( 'entry', 'mission' )
                : __( 'entries', 'mission' )
            }</strong>`
          );
        },
        getConfirmMessage: ( stats ) =>
          sprintf(
            /* translators: %s: number of entries */
            __(
              'This will permanently delete all %s activity log entries.',
              'mission'
            ),
            ( stats?.activity_log_count || 0 ).toLocaleString()
          ),
        successMessage: __( 'Activity log cleared.', 'mission' ),
      },
    ],
  },
  {
    id: 'test-data',
    title: __( 'Test Data', 'mission' ),
    description: __(
      'Remove data created while in Stripe test mode. Only test mode data is affected.',
      'mission'
    ),
    variant: 'warn',
    actions: [
      {
        id: 'delete_test_transactions',
        label: __( 'Delete test transactions', 'mission' ),
        getDescription: ( stats ) => {
          const count = stats?.test_transaction_count;
          if ( count === undefined ) {
            return __(
              'Remove all transactions made with Stripe test mode keys.',
              'mission'
            );
          }
          return sprintf(
            /* translators: %s: count with HTML markup */
            __(
              'Remove all transactions made with Stripe test mode keys. %s found.',
              'mission'
            ),
            `<strong>${ count.toLocaleString() } ${ __(
              'test transactions',
              'mission'
            ) }</strong>`
          );
        },
        getConfirmMessage: ( stats ) =>
          sprintf(
            /* translators: %s: number of test transactions */
            __(
              'This will permanently delete %s test transactions. This cannot be undone.',
              'mission'
            ),
            ( stats?.test_transaction_count || 0 ).toLocaleString()
          ),
        successMessage: __( 'Test transactions deleted.', 'mission' ),
      },
      {
        id: 'delete_test_donors',
        label: __( 'Delete test donors', 'mission' ),
        getDescription: ( stats ) => {
          const count = stats?.test_donor_count;
          if ( count === undefined ) {
            return __(
              'Remove donors that only have test mode transactions (no live donations).',
              'mission'
            );
          }
          return sprintf(
            /* translators: %s: count with HTML markup */
            __(
              'Remove donors that only have test mode transactions (no live donations). %s found.',
              'mission'
            ),
            `<strong>${ count.toLocaleString() } ${ __(
              'test-only donors',
              'mission'
            ) }</strong>`
          );
        },
        getConfirmMessage: ( stats ) =>
          sprintf(
            /* translators: %s: number of test donors */
            __(
              'This will permanently delete %s test-only donors and their associated data. This cannot be undone.',
              'mission'
            ),
            ( stats?.test_donor_count || 0 ).toLocaleString()
          ),
        successMessage: __( 'Test donors deleted.', 'mission' ),
      },
      {
        id: 'delete_all_test_data',
        label: __( 'Delete all test data', 'mission' ),
        description: __(
          'Remove all test transactions, test donors, and test subscriptions in one step.',
          'mission'
        ),
        getConfirmMessage: ( stats ) =>
          sprintf(
            /* translators: %1$s: transaction count, %2$s: donor count, %3$s: subscription count */
            __(
              'This will permanently delete all test mode data (%1$s transactions, %2$s donors, %3$s subscriptions). This cannot be undone.',
              'mission'
            ),
            ( stats?.test_transaction_count || 0 ).toLocaleString(),
            ( stats?.test_donor_count || 0 ).toLocaleString(),
            ( stats?.test_subscription_count || 0 ).toLocaleString()
          ),
        successMessage: __( 'All test data deleted.', 'mission' ),
      },
    ],
  },
  {
    id: 'danger',
    title: __( 'Danger Zone', 'mission' ),
    description: __(
      'These actions are irreversible and will permanently delete data.',
      'mission'
    ),
    variant: 'danger',
    actions: [
      {
        id: 'reset_all_settings',
        label: __( 'Reset all settings', 'mission' ),
        description: __(
          'Restore all Mission settings to their defaults. Stripe will be disconnected. Donor and transaction data is preserved.',
          'mission'
        ),
        confirmMessage: __(
          'This will reset ALL Mission settings to defaults and disconnect Stripe. Your donor and transaction data will NOT be deleted, but you will need to reconfigure the plugin.',
          'mission'
        ),
        isDanger: true,
        successMessage: __( 'All settings reset to defaults.', 'mission' ),
      },
      {
        id: 'delete_all_data',
        label: __( 'Delete all Mission data', 'mission' ),
        description: __(
          'Completely remove all donors, transactions, campaigns, subscriptions, and settings. This is equivalent to a fresh install.',
          'mission'
        ),
        confirmMessage: __(
          'This will PERMANENTLY DELETE all Mission data including donors, transactions, campaigns, subscriptions, and settings. This action CANNOT be undone.',
          'mission'
        ),
        isDanger: true,
        typedConfirm: 'DELETE',
        successMessage: __( 'All Mission data has been deleted.', 'mission' ),
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
        { isRunning ? __( 'Running…', 'mission' ) : __( 'Run', 'mission' ) }
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
        message: err.message || __( 'Something went wrong.', 'mission' ),
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
