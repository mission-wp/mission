import { __ } from '@wordpress/i18n';
import { timeAgo } from '@shared/time';
import { formatAmount } from '@shared/currency';
import EmptyState from '../../components/EmptyState';

const HeartIcon = () => (
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
    <path d="M8 14s-5.5-3.5-5.5-7.5C2.5 4 4.5 2.5 6 2.5c1 0 1.7.5 2 1 .3-.5 1-1 2-1 1.5 0 3.5 1.5 3.5 4S8 14 8 14z" />
  </svg>
);

const RefundIcon = () => (
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
    <polyline points="2,6 5,3 8,6" />
    <path d="M5 3v5a4 4 0 0 0 8 0" />
  </svg>
);

const RecurringIcon = () => (
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
    <path d="M12.5 6A5 5 0 0 0 3.5 5" />
    <polyline points="3.5,2 3.5,5.5 7,5.5" />
    <path d="M3.5 10a5 5 0 0 0 9 1" />
    <polyline points="12.5,14 12.5,10.5 9,10.5" />
  </svg>
);

const UpArrowIcon = () => (
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
    <path d="M8 13V3" />
    <path d="M4 7l4-4 4 4" />
  </svg>
);

const DownArrowIcon = () => (
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
    <path d="M8 3v10" />
    <path d="M4 9l4 4 4-4" />
  </svg>
);

const XCircleIcon = () => (
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
    <circle cx="8" cy="8" r="6" />
    <path d="M6 6l4 4M10 6l-4 4" />
  </svg>
);

const FlagIcon = () => (
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
    <path d="M4 15V3l8 4-8 4" />
  </svg>
);

const BullhornIcon = () => (
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
    <path d="M13.5 3v7.5" />
    <path d="M13.5 3S11 5 7.5 5H4a1.5 1.5 0 0 0-1.5 1.5v0A1.5 1.5 0 0 0 4 8h3.5c3.5 0 6 2 6 2" />
    <path d="M5 8v4.5" />
  </svg>
);

const CheckmarkIcon = () => (
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
    <polyline points="3,8 6.5,11.5 13,4.5" />
  </svg>
);

const MissionLogoIcon = () => (
  <svg width="16" height="16" viewBox="0 0 200 200" fill="currentColor">
    <path d="M100 0C44.771 0 0 44.771 0 100c0 55.228 44.771 100 100 100 55.228 0 100-44.772 100-100C200 44.771 155.228 0 100 0zm-24.232 35.247c8.979 0 17.226 3.28 23.61 8.695 6.382-5.415 14.634-8.695 23.61-8.695 20.128 0 36.596 16.47 36.596 36.6v32.688c0 7.145-5.845 12.988-12.988 12.988-7.143 0-12.984-5.845-12.984-12.988V71.847c0-5.792-4.829-10.622-10.622-10.622-5.792 0-10.624 4.83-10.624 10.622v42.834c0 7.142-5.84 12.988-12.988 12.988-7.143 0-12.988-5.846-12.988-12.988V71.847c0-5.792-4.829-10.622-10.622-10.622-5.79 0-10.619 4.83-10.619 10.622v32.688c0 7.145-5.845 12.988-12.988 12.988-7.146 0-12.989-5.845-12.989-12.988V71.847c0-20.128 16.471-36.6 36.598-36.6zm-8.983 100.278c3.323 0 6.646 1.266 9.181 3.801l.135.137c5.902 5.834 14.003 9.448 22.906 9.448h.457c8.903 0 17.003-3.614 22.904-9.448l.134-.137c5.072-5.07 13.295-5.07 18.367 0 5.071 5.072 5.071 13.295 0 18.366-10.613 10.618-25.264 17.195-41.405 17.195h-.456c-16.141 0-30.792-6.577-41.404-17.195-5.072-5.071-5.072-13.294 0-18.366 2.535-2.535 5.858-3.801 9.181-3.801z" />
  </svg>
);

const ActivityIcon = () => (
  <svg
    width="40"
    height="40"
    viewBox="0 0 40 40"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <path d="M8 20h6l3-8 6 16 3-8h6" />
  </svg>
);

/**
 * Map of backend event names to display metadata.
 */
const eventMetaMap = {
  donation_completed: { icon: <HeartIcon />, className: 'is-donation' },
  recurring_donation_processed: {
    icon: <RecurringIcon />,
    className: 'is-recurring',
  },
  subscription_created: { icon: <RecurringIcon />, className: 'is-recurring' },
  subscription_amount_increased: {
    icon: <UpArrowIcon />,
    className: 'is-recurring-increase',
  },
  subscription_amount_decreased: {
    icon: <DownArrowIcon />,
    className: 'is-recurring-decrease',
  },
  subscription_cancelled: {
    icon: <XCircleIcon />,
    className: 'is-recurring-cancel',
  },
  subscription_failed: { icon: <XCircleIcon />, className: 'is-failed' },
  donation_refunded: { icon: <RefundIcon />, className: 'is-refund' },
  campaign_milestone_reached: {
    icon: <FlagIcon />,
    className: 'is-milestone',
  },
  campaign_goal_reached: { icon: <FlagIcon />, className: 'is-milestone' },
  campaign_created: { icon: <BullhornIcon />, className: 'is-campaign' },
  campaign_ended: { icon: <CheckmarkIcon />, className: 'is-campaign-ended' },
  plugin_updated: { icon: <MissionLogoIcon />, className: 'is-system' },
  plugin_installed: { icon: <MissionLogoIcon />, className: 'is-system' },
  plugin_activated: { icon: <MissionLogoIcon />, className: 'is-system' },
  plugin_deactivated: { icon: <MissionLogoIcon />, className: 'is-system' },
};

const defaultMeta = { icon: <MissionLogoIcon />, className: 'is-system' };

/**
 * Determine the icon class and component for an activity event.
 *
 * @param {Object} event Activity event object.
 */
function getEventMeta( event ) {
  return eventMetaMap[ event.event ] || defaultMeta;
}

const { adminUrl } = window.missiondpAdmin || {};

/**
 * Build a link to a donor detail page, or bold text if no ID.
 *
 * @param {string} name Donor display name.
 * @param {number} id   Donor ID.
 */
function donorLink( name, id ) {
  if ( ! name ) {
    return null;
  }
  if ( ! id || ! adminUrl ) {
    return <strong>{ name }</strong>;
  }
  const href = `${ adminUrl }admin.php?page=mission-donation-platform-donors&donor=${ id }`;
  return (
    <a href={ href } className="mission-feed-link">
      { name }
    </a>
  );
}

/**
 * Build a link to a campaign detail page, or bold text if no ID.
 *
 * @param {string} title Campaign title.
 * @param {number} id    Campaign ID.
 */
function campaignLink( title, id ) {
  if ( ! title ) {
    return null;
  }
  if ( ! id || ! adminUrl ) {
    return <strong>{ title }</strong>;
  }
  const href = `${ adminUrl }admin.php?page=mission-donation-platform-campaigns&campaign=${ id }`;
  return (
    <a href={ href } className="mission-feed-link">
      { title }
    </a>
  );
}

const freqLabels = {
  monthly: '/mo',
  weekly: '/wk',
  quarterly: '/qtr',
  annually: '/yr',
};

/**
 * Build a human-readable description from an activity event.
 *
 * Returns JSX with links to donor/campaign detail pages where applicable.
 *
 * @param {Object} event Activity event object.
 */
function getEventText( event ) {
  const data = event.data || {};
  const eventType = event.event || '';

  if (
    eventType === 'donation_completed' ||
    eventType === 'transaction_completed'
  ) {
    const amount = data.amount ? formatAmount( data.amount ) : '';
    const donor = donorLink(
      data.donor_name || data.donor_email,
      data.donor_id
    );
    const campaign = campaignLink( data.campaign_title, data.campaign_id );

    if ( donor && amount && campaign ) {
      return (
        <>
          { donor } donated { amount } to { campaign }
        </>
      );
    }
    if ( donor && amount ) {
      return (
        <>
          { donor } donated { amount }
        </>
      );
    }
    if ( amount ) {
      return `${ amount } donation received`;
    }
    return 'Donation received';
  }

  if ( eventType === 'recurring_donation_processed' ) {
    const donor = donorLink( data.donor_name, data.donor_id );
    const amount = data.amount ? formatAmount( data.amount ) : '';
    const suffix = freqLabels[ data.frequency ] || '';

    if ( donor && amount ) {
      return (
        <>
          { donor }&apos;s { amount }
          { suffix } recurring donation was processed
        </>
      );
    }
    return 'Recurring donation processed';
  }

  if (
    eventType === 'donation_refunded' ||
    eventType === 'transaction_refunded'
  ) {
    const amount = data.amount ? formatAmount( data.amount ) : '';
    const donor = donorLink( data.donor_name, data.donor_id );

    if ( amount && donor ) {
      return (
        <>
          { amount } refund processed for { donor }
        </>
      );
    }
    return amount ? `${ amount } refund processed` : 'Refund processed';
  }

  if ( eventType === 'subscription_created' ) {
    const donor = donorLink(
      data.donor_name || data.donor_email,
      data.donor_id
    );
    const amount = data.amount ? formatAmount( data.amount ) : '';
    const suffix = freqLabels[ data.frequency ] || '';
    const campaign = campaignLink( data.campaign_title, data.campaign_id );

    if ( donor && amount && campaign ) {
      return (
        <>
          { donor } started a { amount }
          { suffix } recurring donation to { campaign }
        </>
      );
    }
    if ( donor && amount ) {
      return (
        <>
          { donor } started a { amount }
          { suffix } recurring donation
        </>
      );
    }
    return 'New recurring donation started';
  }

  if ( eventType === 'subscription_amount_increased' ) {
    const donor = donorLink( data.donor_name, data.donor_id );
    const amount = data.new_amount ? formatAmount( data.new_amount ) : '';
    const suffix = freqLabels[ data.frequency ] || '';

    if ( donor && amount ) {
      return (
        <>
          { donor } increased recurring donation to { amount }
          { suffix }
        </>
      );
    }
    return 'Recurring donation amount increased';
  }

  if ( eventType === 'subscription_amount_decreased' ) {
    const donor = donorLink( data.donor_name, data.donor_id );
    const amount = data.new_amount ? formatAmount( data.new_amount ) : '';
    const suffix = freqLabels[ data.frequency ] || '';

    if ( donor && amount ) {
      return (
        <>
          { donor } decreased recurring donation to { amount }
          { suffix }
        </>
      );
    }
    return 'Recurring donation amount decreased';
  }

  if ( eventType === 'subscription_cancelled' ) {
    const donor = donorLink( data.donor_name, data.donor_id );

    if ( donor ) {
      return <>{ donor } cancelled their recurring donation</>;
    }
    return 'Recurring donation cancelled';
  }

  if ( eventType === 'subscription_failed' ) {
    const donor = donorLink( data.donor_name, data.donor_id );

    if ( donor ) {
      return <>Recurring donation failed for { donor }</>;
    }
    return 'Recurring donation failed';
  }

  if ( eventType === 'campaign_created' ) {
    const title = data.title || data.campaign_title || '';
    const id = data.campaign_id || event.object_id;
    const link = campaignLink( title, id );

    if ( link ) {
      return <>New campaign { link } was created</>;
    }
    return 'New campaign created';
  }

  if ( eventType === 'campaign_ended' ) {
    const title = data.title || '';
    const id = data.campaign_id || event.object_id;
    const link = campaignLink( title, id );

    if ( link ) {
      return <>{ link } campaign has ended</>;
    }
    return 'Campaign has ended';
  }

  if ( eventType === 'campaign_milestone_reached' ) {
    const title = data.title || data.campaign_title || '';
    const id = data.campaign_id || event.object_id;
    const link = campaignLink( title, id );
    const pct = data.percentage || '';

    if ( link && pct ) {
      return (
        <>
          { link } is { pct }% toward its goal
        </>
      );
    }
    return 'Campaign milestone reached';
  }

  if ( eventType === 'campaign_goal_reached' ) {
    const title = data.title || data.campaign_title || '';
    const id = data.campaign_id || event.object_id;
    const link = campaignLink( title, id );
    const gType = data.goal_type || 'amount';

    let goal = '';
    if ( data.goal_amount ) {
      if ( gType === 'amount' ) {
        goal = formatAmount( data.goal_amount );
      } else {
        const unit = gType === 'donors' ? 'donor' : 'donation';
        goal = `${ Number( data.goal_amount ).toLocaleString() } ${ unit }`;
      }
    }

    if ( link && goal ) {
      return (
        <>
          { link } reached its { goal } goal
        </>
      );
    }
    if ( link ) {
      return <>{ link } reached its goal</>;
    }
    return 'Campaign reached its goal';
  }

  if ( eventType === 'plugin_installed' ) {
    return 'Mission plugin installed';
  }

  if ( eventType === 'plugin_activated' ) {
    return 'Mission plugin activated';
  }

  if ( eventType === 'plugin_deactivated' ) {
    return 'Mission plugin deactivated';
  }

  if ( eventType === 'plugin_updated' ) {
    const version = data.new_version || '';
    return version
      ? `Mission plugin updated to ${ version }`
      : 'Mission plugin updated';
  }

  // Fallback: humanize the event name.
  return eventType
    .replace( /_/g, ' ' )
    .replace( /\b\w/g, ( c ) => c.toUpperCase() );
}

export default function ActivityFeed( { activity, isLoading, feedRef } ) {
  return (
    <div className="mission-dashboard-card mission-feed-card" ref={ feedRef }>
      <div className="mission-dashboard-card__header">
        <h2>{ __( 'Recent Activity', 'mission-donation-platform' ) }</h2>
      </div>

      { ! isLoading && ( ! activity || activity.length === 0 ) ? (
        <EmptyState
          icon={ <ActivityIcon /> }
          text={ __( 'No activity yet', 'mission-donation-platform' ) }
          hint={ __(
            'Activity will appear here as donations come in.',
            'mission-donation-platform'
          ) }
        />
      ) : (
        <div className="mission-activity-feed">
          { ( isLoading ? Array( 5 ).fill( null ) : activity ).map(
            ( event, i ) => {
              if ( isLoading ) {
                return (
                  <div key={ i } className="mission-feed-item">
                    <div
                      className="mission-feed-icon is-system mission-skeleton"
                      style={ { background: '#eee9e3' } }
                    />
                    <div className="mission-feed-body">
                      <div
                        className="mission-skeleton"
                        style={ {
                          width: '80%',
                          height: '14px',
                          borderRadius: '4px',
                          background: '#eee9e3',
                          marginBottom: '6px',
                        } }
                      />
                      <div
                        className="mission-skeleton"
                        style={ {
                          width: '50px',
                          height: '12px',
                          borderRadius: '4px',
                          background: '#eee9e3',
                        } }
                      />
                    </div>
                  </div>
                );
              }

              const meta = getEventMeta( event );
              const text = getEventText( event );

              return (
                <div key={ event.id } className="mission-feed-item">
                  <div className={ `mission-feed-icon ${ meta.className }` }>
                    { meta.icon }
                  </div>
                  <div className="mission-feed-body">
                    <div className="mission-feed-text">{ text }</div>
                    <div className="mission-feed-time">
                      { ( () => {
                        const d = new Date( event.date_created + 'Z' );
                        return (
                          <time
                            dateTime={ d.toISOString() }
                            title={ d.toLocaleString( undefined, {
                              weekday: 'long',
                              year: 'numeric',
                              month: 'long',
                              day: 'numeric',
                              hour: 'numeric',
                              minute: '2-digit',
                              second: '2-digit',
                              timeZoneName: 'short',
                            } ) }
                          >
                            { timeAgo( event.date_created ) }
                          </time>
                        );
                      } )() }
                    </div>
                  </div>
                </div>
              );
            }
          ) }
        </div>
      ) }
    </div>
  );
}
