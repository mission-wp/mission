/**
 * Single source of truth for domain values shared across the admin UI.
 *
 * Value objects mirror the PHP model constants (Transaction::STATUS_*,
 * Subscription::STATUS_*, ImportJob::STATUS_*, Frequency::*). Lists and
 * limits come from the server via `missiondpAdmin.constants` (localized in
 * AdminModule from those same PHP constants), with local fallbacks so this
 * module also works outside a localized page (e.g. unit tests). Labels and
 * badge colors are presentation concerns and live here, keyed by the
 * canonical values.
 */
import { __ } from '@wordpress/i18n';

const serverConstants = window.missiondpAdmin?.constants || {};

export const TRANSACTION_STATUS = {
  PENDING: 'pending',
  COMPLETED: 'completed',
  REFUNDED: 'refunded',
  CANCELLED: 'cancelled',
  FAILED: 'failed',
};

export const SUBSCRIPTION_STATUS = {
  PENDING: 'pending',
  ACTIVE: 'active',
  PAST_DUE: 'past_due',
  PAUSED: 'paused',
  CANCELLED: 'cancelled',
};

export const IMPORT_JOB_STATUS = {
  QUEUED: 'queued',
  PROCESSING: 'processing',
  COMPLETED: 'completed',
  FAILED: 'failed',
  CANCELLED: 'cancelled',
};

export const FREQUENCY = {
  ONE_TIME: 'one_time',
  WEEKLY: 'weekly',
  MONTHLY: 'monthly',
  QUARTERLY: 'quarterly',
  ANNUALLY: 'annually',
};

export const RECURRING_FREQUENCIES = serverConstants.recurringFrequencies || [
  FREQUENCY.WEEKLY,
  FREQUENCY.MONTHLY,
  FREQUENCY.QUARTERLY,
  FREQUENCY.ANNUALLY,
];

export const IMPORT_TERMINAL_STATUSES =
  serverConstants.importTerminalStatuses || [
    IMPORT_JOB_STATUS.COMPLETED,
    IMPORT_JOB_STATUS.FAILED,
    IMPORT_JOB_STATUS.CANCELLED,
  ];

export const IMPORT_MAX_BYTES =
  serverConstants.importMaxBytes || 10 * 1024 * 1024;

/**
 * Whether a transaction type is a recurring frequency (vs one-time).
 *
 * @param {string} type Transaction type / frequency value.
 * @return {boolean} True for recurring types.
 */
export function isRecurringType( type ) {
  return RECURRING_FREQUENCIES.includes( type );
}

export const FREQUENCY_LABELS = {
  [ FREQUENCY.WEEKLY ]: __( 'Weekly', 'mission-donation-platform' ),
  [ FREQUENCY.MONTHLY ]: __( 'Monthly', 'mission-donation-platform' ),
  [ FREQUENCY.QUARTERLY ]: __( 'Quarterly', 'mission-donation-platform' ),
  [ FREQUENCY.ANNUALLY ]: __( 'Annually', 'mission-donation-platform' ),
};

export const FREQUENCY_SUFFIXES = {
  [ FREQUENCY.WEEKLY ]: __( '/wk', 'mission-donation-platform' ),
  [ FREQUENCY.MONTHLY ]: __( '/mo', 'mission-donation-platform' ),
  [ FREQUENCY.QUARTERLY ]: __( '/qtr', 'mission-donation-platform' ),
  [ FREQUENCY.ANNUALLY ]: __( '/yr', 'mission-donation-platform' ),
};

export const TRANSACTION_STATUS_OPTIONS = [
  {
    value: TRANSACTION_STATUS.COMPLETED,
    label: __( 'Completed', 'mission-donation-platform' ),
    backgroundColor: 'rgba(47, 163, 107, 0.12)',
    color: '#278f5c',
  },
  {
    value: TRANSACTION_STATUS.PENDING,
    label: __( 'Pending', 'mission-donation-platform' ),
    backgroundColor: '#e4eff5',
    color: '#4a7a9b',
  },
  {
    value: TRANSACTION_STATUS.REFUNDED,
    label: __( 'Refunded', 'mission-donation-platform' ),
    backgroundColor: '#f5e8e8',
    color: '#b85c5c',
  },
  {
    value: TRANSACTION_STATUS.CANCELLED,
    label: __( 'Cancelled', 'mission-donation-platform' ),
    backgroundColor: '#f0eeeb',
    color: '#8a7e72',
  },
  {
    value: TRANSACTION_STATUS.FAILED,
    label: __( 'Failed', 'mission-donation-platform' ),
    backgroundColor: '#fce8e8',
    color: '#c0392b',
  },
];

export const SUBSCRIPTION_STATUS_OPTIONS = [
  {
    value: SUBSCRIPTION_STATUS.ACTIVE,
    label: __( 'Active', 'mission-donation-platform' ),
    backgroundColor: 'rgba(47, 163, 107, 0.12)',
    color: '#278f5c',
  },
  {
    value: SUBSCRIPTION_STATUS.PENDING,
    label: __( 'Pending', 'mission-donation-platform' ),
    backgroundColor: '#e4eff5',
    color: '#4a7a9b',
  },
  {
    value: SUBSCRIPTION_STATUS.PAST_DUE,
    label: __( 'Past Due', 'mission-donation-platform' ),
    backgroundColor: '#fdf8ef',
    color: '#b8860b',
  },
  {
    value: SUBSCRIPTION_STATUS.PAUSED,
    label: __( 'Paused', 'mission-donation-platform' ),
    backgroundColor: '#ebebed',
    color: '#82828c',
  },
  {
    value: SUBSCRIPTION_STATUS.CANCELLED,
    label: __( 'Cancelled', 'mission-donation-platform' ),
    backgroundColor: '#f0eeeb',
    color: '#8a7e72',
  },
];

const toLabelMap = ( options ) =>
  Object.fromEntries( options.map( ( o ) => [ o.value, o.label ] ) );

export const TRANSACTION_STATUS_LABELS = toLabelMap(
  TRANSACTION_STATUS_OPTIONS
);

export const SUBSCRIPTION_STATUS_LABELS = toLabelMap(
  SUBSCRIPTION_STATUS_OPTIONS
);
