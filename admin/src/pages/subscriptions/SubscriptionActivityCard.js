import { useState, useEffect } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { formatDateTime } from '@shared/date';
import { formatAmount } from '@shared/currency';

const FREQ_SUFFIXES = {
  monthly: '/mo',
  weekly: '/wk',
  quarterly: '/qtr',
  annually: '/yr',
};

const EVENT_LABELS = {
  subscription_created: __( 'Subscription created', 'mission' ),
  subscription_cancelled: __( 'Subscription cancelled', 'mission' ),
  subscription_paused: __( 'Subscription paused', 'mission' ),
  subscription_resumed: __( 'Subscription resumed', 'mission' ),
  subscription_renewed: __( 'Payment received', 'mission' ),
  subscription_payment_method_updated: __(
    'Payment method updated',
    'mission'
  ),
  payment_completed: __( 'Payment completed', 'mission' ),
  payment_failed: __( 'Payment failed', 'mission' ),
  status_changed: __( 'Status changed', 'mission' ),
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
    const suffix = FREQ_SUFFIXES[ entry.data.frequency ] || '';
    const from = formatAmount( entry.data.old_amount ) + suffix;
    const to = formatAmount( entry.data.new_amount ) + suffix;
    return sprintf(
      /* translators: 1: old amount with frequency, 2: new amount with frequency */
      __( 'Amount increased from %1$s to %2$s', 'mission' ),
      from,
      to
    );
  }

  if ( entry.event === 'subscription_amount_decreased' && entry.data ) {
    const suffix = FREQ_SUFFIXES[ entry.data.frequency ] || '';
    const from = formatAmount( entry.data.old_amount ) + suffix;
    const to = formatAmount( entry.data.new_amount ) + suffix;
    return sprintf(
      /* translators: 1: old amount with frequency, 2: new amount with frequency */
      __( 'Amount decreased from %1$s to %2$s', 'mission' ),
      from,
      to
    );
  }

  return EVENT_LABELS[ entry.event ] || entry.event;
}

function TimelineItem( { title, date, dotClass } ) {
  return (
    <div className="mission-timeline__item is-reached">
      <div className={ `mission-timeline__dot ${ dotClass }` } />
      <div className="mission-timeline__title">{ title }</div>
      <div className="mission-timeline__date">{ date }</div>
    </div>
  );
}

function deriveFallbackEvents( subscription ) {
  const events = [];

  if ( subscription.date_cancelled ) {
    events.push( {
      title: __( 'Subscription cancelled', 'mission' ),
      date: formatDateTime( subscription.date_cancelled ),
      dotClass: 'is-cancelled',
    } );
  }

  // Add completed transaction events.
  const transactions = subscription.transactions || [];
  transactions.forEach( ( txn ) => {
    if ( txn.status === 'completed' && txn.date_completed ) {
      events.push( {
        title: __( 'Payment completed', 'mission' ),
        date: formatDateTime( txn.date_completed ),
        dotClass: 'is-success',
      } );
    }
  } );

  events.push( {
    title: __( 'Subscription created', 'mission' ),
    date: formatDateTime( subscription.date_created ),
    dotClass: 'is-success',
  } );

  return events;
}

export default function SubscriptionActivityCard( { subscription } ) {
  const [ entries, setEntries ] = useState( null );
  const [ isLoading, setIsLoading ] = useState( true );

  const id = subscription?.id;

  useEffect( () => {
    if ( ! id ) {
      setIsLoading( false );
      return;
    }

    apiFetch( {
      path: `/mission/v1/activity?object_type=subscription&object_id=${ id }&per_page=25`,
    } )
      .then( ( data ) => setEntries( data ) )
      .catch( () => setEntries( null ) )
      .finally( () => setIsLoading( false ) );
  }, [ id ] );

  const useFallback = ! isLoading && ( ! entries || entries.length === 0 );
  const events = useFallback
    ? deriveFallbackEvents( subscription )
    : ( entries || [] ).map( ( entry ) => ( {
        title: getEventLabel( entry ),
        date: formatDateTime( entry.date_created ),
        dotClass: DOT_CLASSES[ entry.event ] || '',
      } ) );

  return (
    <div className="mission-card" style={ { padding: 0 } }>
      <h2 className="mission-card__heading">{ __( 'Activity', 'mission' ) }</h2>
      { isLoading ? (
        <div style={ { padding: '24px', textAlign: 'center' } }>
          <Spinner />
        </div>
      ) : (
        <div className="mission-timeline">
          { events.map( ( event, index ) => (
            <TimelineItem
              key={ index }
              title={ event.title }
              date={ event.date }
              dotClass={ event.dotClass }
            />
          ) ) }
        </div>
      ) }
    </div>
  );
}
