import { __, sprintf } from '@wordpress/i18n';
import { formatDateTime } from '@shared/date';
import { formatAmount } from '@shared/currency';
import ActivityTimelineCard from '../../components/ActivityTimelineCard';
import { FREQUENCY_SUFFIXES, TRANSACTION_STATUS } from '../../constants';

const EVENT_LABELS = {
  subscription_created: __(
    'Subscription created',
    'mission-donation-platform'
  ),
  subscription_cancelled: __(
    'Subscription cancelled',
    'mission-donation-platform'
  ),
  subscription_paused: __( 'Subscription paused', 'mission-donation-platform' ),
  subscription_resumed: __(
    'Subscription resumed',
    'mission-donation-platform'
  ),
  subscription_renewed: __( 'Payment received', 'mission-donation-platform' ),
  subscription_payment_method_updated: __(
    'Payment method updated',
    'mission-donation-platform'
  ),
  payment_completed: __( 'Payment completed', 'mission-donation-platform' ),
  payment_failed: __( 'Payment failed', 'mission-donation-platform' ),
  status_changed: __( 'Status changed', 'mission-donation-platform' ),
};

const DOT_CLASSES = {
  subscription_created: 'is-success',
  subscription_cancelled: 'is-cancelled',
  subscription_paused: 'is-pending',
  subscription_resumed: 'is-success',
  subscription_renewed: 'is-success',
  subscription_amount_increased: 'is-success',
  subscription_amount_decreased: 'is-pending',
  subscription_payment_method_updated: 'is-success',
  payment_completed: 'is-success',
  payment_failed: 'is-negative',
  status_changed: 'is-pending',
};

function getEventLabel( entry ) {
  if (
    entry.event === 'status_changed' &&
    entry.data?.old_status &&
    entry.data?.new_status
  ) {
    const oldLabel =
      entry.data.old_status.charAt( 0 ).toUpperCase() +
      entry.data.old_status.slice( 1 );
    const newLabel =
      entry.data.new_status.charAt( 0 ).toUpperCase() +
      entry.data.new_status.slice( 1 );
    return `${ oldLabel } \u2192 ${ newLabel }`;
  }

  if ( entry.event === 'subscription_amount_increased' && entry.data ) {
    const suffix = FREQUENCY_SUFFIXES[ entry.data.frequency ] || '';
    const from = formatAmount( entry.data.old_amount ) + suffix;
    const to = formatAmount( entry.data.new_amount ) + suffix;
    return sprintf(
      /* translators: 1: old amount with frequency, 2: new amount with frequency */
      __( 'Amount increased from %1$s to %2$s', 'mission-donation-platform' ),
      from,
      to
    );
  }

  if ( entry.event === 'subscription_amount_decreased' && entry.data ) {
    const suffix = FREQUENCY_SUFFIXES[ entry.data.frequency ] || '';
    const from = formatAmount( entry.data.old_amount ) + suffix;
    const to = formatAmount( entry.data.new_amount ) + suffix;
    return sprintf(
      /* translators: 1: old amount with frequency, 2: new amount with frequency */
      __( 'Amount decreased from %1$s to %2$s', 'mission-donation-platform' ),
      from,
      to
    );
  }

  return EVENT_LABELS[ entry.event ] || entry.event;
}

function deriveFallbackEvents( subscription ) {
  const events = [];

  if ( subscription.date_cancelled ) {
    events.push( {
      title: __( 'Subscription cancelled', 'mission-donation-platform' ),
      date: formatDateTime( subscription.date_cancelled ),
      dotClass: 'is-cancelled',
    } );
  }

  // Add completed transaction events.
  const transactions = subscription.transactions || [];
  transactions.forEach( ( txn ) => {
    if ( txn.status === TRANSACTION_STATUS.COMPLETED && txn.date_completed ) {
      events.push( {
        title: __( 'Payment completed', 'mission-donation-platform' ),
        date: formatDateTime( txn.date_completed ),
        dotClass: 'is-success',
      } );
    }
  } );

  events.push( {
    title: __( 'Subscription created', 'mission-donation-platform' ),
    date: formatDateTime( subscription.date_created ),
    dotClass: 'is-success',
  } );

  return events;
}

export default function SubscriptionActivityCard( { subscription } ) {
  const id = subscription?.id;

  return (
    <ActivityTimelineCard
      path={
        id
          ? `/mission-donation-platform/v1/activity?object_type=subscription&object_id=${ id }&per_page=25`
          : null
      }
      mapEntry={ ( entry ) => ( {
        title: getEventLabel( entry ),
        date: formatDateTime( entry.date_created ),
        dotClass: DOT_CLASSES[ entry.event ] || '',
      } ) }
      fallbackEvents={ deriveFallbackEvents( subscription ) }
    />
  );
}
