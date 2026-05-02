import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';

export const LEVEL_OPTIONS = [
  { value: '', label: __( 'All levels', 'mission-donation-platform' ) },
  { value: 'info', label: __( 'Info', 'mission-donation-platform' ) },
  { value: 'warning', label: __( 'Warnings', 'mission-donation-platform' ) },
  { value: 'error', label: __( 'Errors', 'mission-donation-platform' ) },
];

export const CATEGORY_OPTIONS = [
  { value: '', label: __( 'All categories', 'mission-donation-platform' ) },
  { value: 'payment', label: __( 'Payment', 'mission-donation-platform' ) },
  { value: 'webhook', label: __( 'Webhook', 'mission-donation-platform' ) },
  { value: 'email', label: __( 'Email', 'mission-donation-platform' ) },
  {
    value: 'subscription',
    label: __( 'Subscription', 'mission-donation-platform' ),
  },
  { value: 'system', label: __( 'System', 'mission-donation-platform' ) },
];

const freqLabels = {
  monthly: '/mo',
  weekly: '/wk',
  quarterly: '/qtr',
  annually: '/yr',
};

/**
 * Build a human-readable message string for a log entry.
 *
 * Returns an object with `__html` for use with dangerouslySetInnerHTML,
 * since messages contain <strong> tags.
 *
 * @param {Object} entry Log entry from the API.
 * @return {string} HTML string.
 */
export function buildLogMessage( entry ) {
  const data = entry.data || {};
  const event = entry.event || '';

  switch ( event ) {
    case 'donation_completed': {
      const amt = data.amount ? formatAmount( data.amount ) : '';
      const name = data.donor_name || 'a donor';
      return amt
        ? `Payment of <strong>${ amt }</strong> completed for ${ name }`
        : 'Donation completed';
    }

    case 'recurring_donation_processed': {
      const amt = data.amount ? formatAmount( data.amount ) : '';
      const suffix = freqLabels[ data.frequency ] || '';
      const name = data.donor_name || 'a donor';
      return amt
        ? `Recurring donation of <strong>${ amt }${ suffix }</strong> renewed for ${ name }`
        : 'Recurring donation processed';
    }

    case 'donation_refunded': {
      const amt = data.amount ? formatAmount( data.amount ) : '';
      const name = data.donor_name || 'a donor';
      return amt
        ? `Refund of <strong>${ amt }</strong> processed for ${ name }`
        : 'Refund processed';
    }

    case 'payment_failed': {
      const amt = data.amount ? formatAmount( data.amount ) : '';
      const name = data.donor_name || 'a donor';
      return amt
        ? `Payment of <strong>${ amt }</strong> failed for ${ name }`
        : 'Payment failed';
    }

    case 'subscription_created': {
      const amt = data.amount ? formatAmount( data.amount ) : '';
      const suffix = freqLabels[ data.frequency ] || '';
      const name = data.donor_name || 'a donor';
      return amt
        ? `New <strong>${ amt }${ suffix }</strong> recurring donation started by ${ name }`
        : 'New recurring donation started';
    }

    case 'subscription_cancelled': {
      const name = data.donor_name || 'A donor';
      return `Subscription <strong>cancelled</strong> by ${ name }`;
    }

    case 'subscription_failed': {
      const name = data.donor_name || 'a donor';
      return `Recurring donation <strong>failed</strong> for ${ name }`;
    }

    case 'subscription_amount_increased': {
      const amt = data.new_amount ? formatAmount( data.new_amount ) : '';
      const suffix = freqLabels[ data.frequency ] || '';
      const name = data.donor_name || 'A donor';
      return amt
        ? `${ name } increased recurring donation to <strong>${ amt }${ suffix }</strong>`
        : 'Recurring donation amount increased';
    }

    case 'subscription_amount_decreased': {
      const amt = data.new_amount ? formatAmount( data.new_amount ) : '';
      const suffix = freqLabels[ data.frequency ] || '';
      const name = data.donor_name || 'A donor';
      return amt
        ? `${ name } decreased recurring donation to <strong>${ amt }${ suffix }</strong>`
        : 'Recurring donation amount decreased';
    }

    case 'webhook_received': {
      const type = data.stripe_event_type || 'unknown';
      return `Webhook received: <strong>${ type }</strong>`;
    }

    case 'email_sent': {
      const to = data.recipient || 'unknown';
      return `Email sent to <strong>${ to }</strong>`;
    }

    case 'email_failed': {
      const to = data.recipient || 'unknown';
      return `Failed to send email to <strong>${ to }</strong>`;
    }

    case 'admin_notification_sent': {
      const type = ( data.notification_type || '' ).replace( /_/g, ' ' );
      const count = data.recipient_count || 0;
      return `Admin notification <strong>${ type }</strong> sent to ${ count } recipient${
        count !== 1 ? 's' : ''
      }`;
    }

    case 'campaign_created': {
      const title = data.title || 'Untitled';
      return `New campaign <strong>${ title }</strong> created`;
    }

    case 'campaign_milestone_reached': {
      const title = data.title || 'A campaign';
      const pct = data.percentage || '';
      return pct
        ? `<strong>${ title }</strong> reached ${ pct }% of its goal`
        : `<strong>${ title }</strong> reached a milestone`;
    }

    case 'campaign_goal_reached': {
      const title = data.title || 'A campaign';
      return `<strong>${ title }</strong> reached its goal`;
    }

    case 'plugin_updated': {
      const version = data.new_version || '';
      return version
        ? `Mission plugin updated to <strong>${ version }</strong>`
        : 'Mission plugin updated';
    }

    case 'plugin_installed':
      return `Mission plugin <strong>installed</strong>`;

    case 'plugin_activated':
      return `Mission plugin <strong>activated</strong>`;

    case 'plugin_deactivated':
      return `Mission plugin <strong>deactivated</strong>`;

    case 'settings_updated': {
      const keys = data.changed_keys || [];
      return keys.length
        ? `Settings updated: <strong>${ keys.join( ', ' ) }</strong>`
        : 'Settings updated';
    }

    default:
      return event
        .replace( /_/g, ' ' )
        .replace( /\b\w/g, ( c ) => c.toUpperCase() );
  }
}

/**
 * Format a setting value for display in the activity log.
 *
 * @param {*} value The setting value.
 * @return {string} Human-readable representation.
 */
function formatSettingValue( value ) {
  if ( value === null || value === undefined || value === '' ) {
    return '(empty)';
  }
  if ( value === true ) {
    return 'on';
  }
  if ( value === false ) {
    return 'off';
  }
  if ( typeof value === 'object' ) {
    return JSON.stringify( value );
  }
  return String( value );
}

/**
 * Build detail key-value rows for an expanded log entry.
 *
 * @param {Object} entry Log entry from the API.
 * @return {Array<{label: string, value: string}>} Detail rows for the entry.
 */
export function buildDetailRows( entry ) {
  const data = entry.data || {};
  const rows = [];

  const push = ( label, value ) => {
    if ( value !== undefined && value !== null && value !== '' ) {
      rows.push( { label, value: String( value ) } );
    }
  };

  switch ( entry.event ) {
    case 'donation_completed':
    case 'recurring_donation_processed':
    case 'payment_failed':
    case 'donation_refunded':
      push( 'Amount', data.amount ? formatAmount( data.amount ) : '' );
      push( 'Donor', data.donor_name );
      push( 'Campaign', data.campaign_title );
      if ( data.frequency ) {
        push( 'Frequency', data.frequency );
      }
      if ( data.error ) {
        push( 'Error', data.error );
      }
      break;

    case 'subscription_created':
      push( 'Amount', data.amount ? formatAmount( data.amount ) : '' );
      push( 'Frequency', data.frequency );
      push( 'Donor', data.donor_name );
      push( 'Campaign', data.campaign_title );
      break;

    case 'subscription_cancelled':
    case 'subscription_failed':
      push( 'Amount', data.amount ? formatAmount( data.amount ) : '' );
      push( 'Frequency', data.frequency );
      push( 'Donor', data.donor_name );
      break;

    case 'subscription_amount_increased':
    case 'subscription_amount_decreased':
      push(
        'Previous amount',
        data.old_amount ? formatAmount( data.old_amount ) : ''
      );
      push(
        'New amount',
        data.new_amount ? formatAmount( data.new_amount ) : ''
      );
      push( 'Frequency', data.frequency );
      push( 'Donor', data.donor_name );
      break;

    case 'webhook_received':
      push( 'Event type', data.stripe_event_type );
      push( 'Event ID', data.event_id );
      break;

    case 'email_sent':
    case 'email_failed':
      push( 'Recipient', data.recipient );
      push( 'Subject', data.subject );
      if ( data.error ) {
        push( 'Error', data.error );
      }
      break;

    case 'admin_notification_sent':
      push( 'Type', ( data.notification_type || '' ).replace( /_/g, ' ' ) );
      push( 'Recipients', data.recipient_count );
      break;

    case 'campaign_created':
      push( 'Title', data.title );
      break;

    case 'campaign_milestone_reached':
    case 'campaign_goal_reached':
      push( 'Campaign', data.title );
      push( 'Percentage', data.percentage ? `${ data.percentage }%` : '' );
      push( 'Goal', data.goal_amount ? formatAmount( data.goal_amount ) : '' );
      break;

    case 'plugin_updated':
      push( 'Version', data.new_version );
      break;

    case 'settings_updated':
      if ( data.changes ) {
        Object.entries( data.changes ).forEach( ( [ key, diff ] ) => {
          const from = formatSettingValue( diff.from );
          const to = formatSettingValue( diff.to );
          push( key, `${ from } → ${ to }` );
        } );
      } else {
        push( 'Changed', ( data.changed_keys || [] ).join( ', ' ) );
      }
      break;

    default:
      // Show all data keys for unknown events.
      Object.entries( data ).forEach( ( [ key, value ] ) => {
        push(
          key.replace( /_/g, ' ' ),
          typeof value === 'object' ? JSON.stringify( value ) : value
        );
      } );
  }

  return rows;
}
