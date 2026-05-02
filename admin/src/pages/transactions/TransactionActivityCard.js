import { useState, useEffect } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { formatDateTime } from '@shared/date';

const EVENT_LABELS = {
  payment_initiated: __( 'Payment initiated', 'mission-donation-platform' ),
  payment_completed: __( 'Payment completed', 'mission-donation-platform' ),
  payment_failed: __( 'Payment failed', 'mission-donation-platform' ),
  refund_initiated: __( 'Refund initiated', 'mission-donation-platform' ),
  refund_completed: __( 'Refund completed', 'mission-donation-platform' ),
  partial_refund: __( 'Partial refund', 'mission-donation-platform' ),
  receipt_sent: __( 'Receipt sent', 'mission-donation-platform' ),
  status_changed: __( 'Status changed', 'mission-donation-platform' ),
};

const DOT_CLASSES = {
  payment_initiated: 'is-pending',
  payment_completed: 'is-success',
  payment_failed: 'is-negative',
  refund_initiated: 'is-refunded',
  refund_completed: 'is-refunded',
  partial_refund: 'is-refunded',
  receipt_sent: 'is-success',
};

const STATUS_DOT_CLASSES = {
  completed: 'is-success',
  pending: 'is-pending',
  failed: 'is-negative',
  refunded: 'is-refunded',
  cancelled: 'is-cancelled',
};

function getEventLabel( entry ) {
  if (
    entry.event_type === 'status_changed' &&
    entry.context?.old_status &&
    entry.context?.new_status
  ) {
    const oldLabel =
      entry.context.old_status.charAt( 0 ).toUpperCase() +
      entry.context.old_status.slice( 1 );
    const newLabel =
      entry.context.new_status.charAt( 0 ).toUpperCase() +
      entry.context.new_status.slice( 1 );
    return `${ oldLabel } \u2192 ${ newLabel }`;
  }
  return EVENT_LABELS[ entry.event_type ] || entry.event_type;
}

function getEventSubtitle( entry, currency ) {
  const ctx = entry.context || {};

  switch ( entry.event_type ) {
    case 'payment_initiated':
      return ctx.payment_method || null;

    case 'payment_completed':
      return null;

    case 'receipt_sent':
      if ( ctx.email ) {
        return `${ __( 'Sent to', 'mission-donation-platform' ) } ${
          ctx.email
        }`;
      }
      return null;

    case 'refund_initiated':
      if ( entry.actor_type === 'user' && entry.actor_name ) {
        return `${ __( 'By', 'mission-donation-platform' ) } ${
          entry.actor_name
        }`;
      }
      return null;

    case 'refund_completed':
      if ( ctx.amount && ctx.payment_method ) {
        return `${ formatAmount( ctx.amount, currency ) } ${ __(
          'returned to',
          'mission-donation-platform'
        ) } ${ ctx.payment_method }`;
      }
      if ( ctx.amount ) {
        return `${ formatAmount( ctx.amount, currency ) } ${ __(
          'returned',
          'mission-donation-platform'
        ) }`;
      }
      return null;

    case 'partial_refund':
      if ( ctx.amount ) {
        return `${ formatAmount( ctx.amount, currency ) } ${ __(
          'returned',
          'mission-donation-platform'
        ) }`;
      }
      return null;

    case 'payment_failed':
      return ctx.reason || null;

    case 'status_changed':
      if ( entry.actor_type === 'user' && entry.actor_name ) {
        return `${ __( 'By', 'mission-donation-platform' ) } ${
          entry.actor_name
        }`;
      }
      return null;

    default:
      return null;
  }
}

function TimelineItem( { title, subtitle, date, dotClass } ) {
  return (
    <div className="mission-timeline__item is-reached">
      <div className={ `mission-timeline__dot ${ dotClass }` } />
      <div className="mission-timeline__title">{ title }</div>
      <div className="mission-timeline__date">{ date }</div>
      { subtitle && (
        <div className="mission-timeline__subtitle">{ subtitle }</div>
      ) }
    </div>
  );
}

function deriveFallbackEvents( transaction ) {
  const events = [];

  if ( transaction.date_refunded ) {
    events.push( {
      title: __( 'Refund completed', 'mission-donation-platform' ),
      date: formatDateTime( transaction.date_refunded ),
      dotClass: 'is-refunded',
    } );
  }

  if ( transaction.date_completed ) {
    events.push( {
      title: __( 'Payment completed', 'mission-donation-platform' ),
      date: formatDateTime( transaction.date_completed ),
      dotClass: 'is-success',
    } );
  }

  events.push( {
    title: __( 'Payment initiated', 'mission-donation-platform' ),
    date: formatDateTime( transaction.date_created ),
    dotClass: 'is-pending',
  } );

  return events;
}

export default function TransactionActivityCard( {
  transaction,
  transactionId,
} ) {
  const [ entries, setEntries ] = useState( null );
  const [ isLoading, setIsLoading ] = useState( true );

  const id = transactionId || transaction?.id;
  const currency = transaction?.currency || 'usd';

  useEffect( () => {
    if ( ! id ) {
      setIsLoading( false );
      return;
    }

    apiFetch( {
      path: `/mission-donation-platform/v1/transactions/${ id }/history`,
    } )
      .then( ( data ) => {
        setEntries( data );
      } )
      .catch( () => {
        setEntries( null );
      } )
      .finally( () => setIsLoading( false ) );
  }, [ id ] );

  const useFallback = ! isLoading && ( ! entries || entries.length === 0 );
  const events = useFallback
    ? deriveFallbackEvents( transaction )
    : ( entries || [] ).map( ( entry ) => ( {
        title: getEventLabel( entry ),
        subtitle: getEventSubtitle( entry, currency ),
        date: formatDateTime( entry.created_at ),
        dotClass:
          entry.event_type === 'status_changed'
            ? STATUS_DOT_CLASSES[ entry.context?.new_status ] || ''
            : DOT_CLASSES[ entry.event_type ] || '',
      } ) );

  return (
    <div className="mission-card" style={ { padding: 0 } }>
      <h2 className="mission-card__heading">
        { __( 'Activity', 'mission-donation-platform' ) }
      </h2>
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
              subtitle={ event.subtitle }
              date={ event.date }
              dotClass={ event.dotClass }
            />
          ) ) }
        </div>
      ) }
    </div>
  );
}
