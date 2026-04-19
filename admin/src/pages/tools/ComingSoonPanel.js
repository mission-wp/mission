import { __ } from '@wordpress/i18n';
import { useState, useRef, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const API_URL = 'https://api.missionwp.com/v1/feature-signup';

const COMING_SOON_DATA = {
  import: {
    icon: (
      <svg
        width="30"
        height="30"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
        <polyline points="17 8 12 3 7 8" />
        <line x1="12" y1="3" x2="12" y2="15" />
      </svg>
    ),
    iconStyle: { background: '#e2f4eb', color: '#2fa36b' },
    title: __( 'Bring your data home', 'missionwp-donation-platform' ),
    desc: __(
      'Import donors, transactions, campaigns, and subscriptions from a CSV or JSON file.',
      'missionwp-donation-platform'
    ),
    features: [
      __(
        'Automatic column detection and field mapping',
        'missionwp-donation-platform'
      ),
      __(
        'Duplicate detection to avoid double-counting donors',
        'missionwp-donation-platform'
      ),
      __(
        'Preview your import before anything changes',
        'missionwp-donation-platform'
      ),
      __(
        'Downloadable CSV templates for each data type',
        'missionwp-donation-platform'
      ),
    ],
  },
  migration: {
    icon: (
      <svg
        width="30"
        height="30"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <polyline points="15 3 21 3 21 9" />
        <path d="M21 3l-7 7" />
        <polyline points="9 21 3 21 3 15" />
        <path d="M3 21l7-7" />
      </svg>
    ),
    iconStyle: { background: '#ede8f5', color: '#7a5a8e' },
    title: __( 'Switch from another plugin', 'missionwp-donation-platform' ),
    desc: __(
      'Already using a different donation plugin? The migration tool moves your donors, transactions, campaigns, and subscriptions over automatically.',
      'missionwp-donation-platform'
    ),
    features: [
      __(
        'One-click migration from GiveWP, Charitable, and more',
        'missionwp-donation-platform'
      ),
      __(
        'Moves donors, transactions, campaigns, and subscriptions',
        'missionwp-donation-platform'
      ),
      __(
        'Automatic field mapping between plugins',
        'missionwp-donation-platform'
      ),
      __( 'Your old data stays untouched', 'missionwp-donation-platform' ),
    ],
  },
  features: {
    icon: (
      <svg
        width="30"
        height="30"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <rect x="3" y="3" width="7" height="7" rx="1" />
        <rect x="14" y="3" width="7" height="7" rx="1" />
        <rect x="3" y="14" width="7" height="7" rx="1" />
        <rect x="14" y="14" width="7" height="7" rx="1" />
      </svg>
    ),
    iconStyle: { background: '#fef3e2', color: '#c07d20' },
    title: __(
      'No add-ons. Everything is free.',
      'missionwp-donation-platform'
    ),
    desc: __(
      'Other donation plugins charge for recurring donations, donor management, and custom fields. MissionWP includes all of that in one plugin at no cost. Bigger features are on the way, and they will be free too.',
      'missionwp-donation-platform'
    ),
    features: [
      __( 'Peer-to-peer fundraising', 'missionwp-donation-platform' ),
      __( 'Designated funds', 'missionwp-donation-platform' ),
      __( 'Ticketed events', 'missionwp-donation-platform' ),
    ],
    cta: __( 'Get notified when they ship', 'missionwp-donation-platform' ),
  },
};

const checkIcon = (
  <svg
    width="18"
    height="18"
    viewBox="0 0 18 18"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.8"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <polyline points="13.5 4.5 7.5 10.5 4.5 7.5" />
  </svg>
);

const mailIcon = (
  <svg
    width="16"
    height="16"
    viewBox="0 0 16 16"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.8"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <rect x="2" y="4" width="12" height="9" rx="2" />
    <polyline points="2 6 8 10 14 6" />
  </svg>
);

const subscribedCheckIcon = (
  <svg
    width="18"
    height="18"
    viewBox="0 0 18 18"
    fill="none"
    stroke="currentColor"
    strokeWidth="2"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <polyline points="13.5 4.5 7.5 10.5 4.5 7.5" />
  </svg>
);

function saveUserMeta( feature, value ) {
  apiFetch( {
    path: '/wp/v2/users/me',
    method: 'POST',
    data: { meta: { [ `mission_feature_signup_${ feature }` ]: value } },
  } ).catch( () => {} );
}

function deleteUserMeta( feature ) {
  saveUserMeta( feature, '' );
}

function NotifySection( { tabId, ctaText } ) {
  const existingEmail = window.missionAdmin?.featureSignups?.[ tabId ] || '';

  // 'cta' | 'input' | 'subscribed'
  const [ view, setView ] = useState( existingEmail ? 'subscribed' : 'cta' );
  const [ email, setEmail ] = useState(
    existingEmail || window.missionAdmin?.adminEmail || ''
  );
  const [ error, setError ] = useState( false );
  const [ submitting, setSubmitting ] = useState( false );
  const [ subscribedEmail, setSubscribedEmail ] = useState( existingEmail );
  const inputRef = useRef( null );

  useEffect( () => {
    if ( view === 'input' && inputRef.current ) {
      inputRef.current.focus();
    }
  }, [ view ] );

  const handleSubmit = async () => {
    if ( ! email || ! email.includes( '@' ) ) {
      setError( true );
      return;
    }

    setError( false );
    setSubmitting( true );

    try {
      const response = await fetch( API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify( { email, feature: tabId } ),
      } );

      if ( ! response.ok ) {
        setError( true );
        return;
      }

      setSubscribedEmail( email );
      setView( 'subscribed' );

      // Persist signup in user meta so it survives page reloads.
      saveUserMeta( tabId, email );
    } catch {
      setError( true );
    } finally {
      setSubmitting( false );
    }
  };

  const handleUnsubscribe = async () => {
    try {
      await fetch( API_URL, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify( {
          email: subscribedEmail,
          feature: tabId,
        } ),
      } );
    } catch {
      // Silently fail — worst case they stay subscribed.
    }

    setSubscribedEmail( '' );
    setView( 'cta' );

    // Remove from user meta.
    deleteUserMeta( tabId );
  };

  if ( view === 'subscribed' ) {
    return (
      <div className="mission-notify-subscribed">
        <div className="mission-notify-subscribed__check">
          { subscribedCheckIcon }
        </div>
        <span className="mission-notify-subscribed__msg">
          { __( 'You\u2019re on the list', 'missionwp-donation-platform' ) }
        </span>
        <span className="mission-notify-subscribed__email">
          { subscribedEmail }
        </span>
        <button
          type="button"
          className="mission-notify-unsubscribe"
          onClick={ handleUnsubscribe }
        >
          { __(
            'Remove me from notifications',
            'missionwp-donation-platform'
          ) }
        </button>
      </div>
    );
  }

  if ( view === 'input' ) {
    return (
      <div className="mission-notify-input-row">
        <input
          ref={ inputRef }
          type="email"
          className={ `mission-notify-email-input${
            error ? ' is-error' : ''
          }` }
          placeholder="you@example.com"
          value={ email }
          onChange={ ( e ) => {
            setEmail( e.target.value );
            setError( false );
          } }
          onKeyDown={ ( e ) => {
            if ( e.key === 'Enter' ) {
              handleSubmit();
            }
          } }
        />
        <button
          type="button"
          className="mission-notify-submit"
          onClick={ handleSubmit }
          disabled={ submitting }
        >
          { submitting
            ? __( 'Saving\u2026', 'missionwp-donation-platform' )
            : __( 'Notify me', 'missionwp-donation-platform' ) }
        </button>
        <button
          type="button"
          className="mission-notify-cancel"
          onClick={ () => {
            setView( 'cta' );
            setError( false );
          } }
        >
          { __( 'Cancel', 'missionwp-donation-platform' ) }
        </button>
      </div>
    );
  }

  return (
    <button
      type="button"
      className="mission-tools-coming-soon__cta"
      onClick={ () => setView( 'input' ) }
    >
      { mailIcon }
      { ctaText }
    </button>
  );
}

export default function ComingSoonPanel( { tabId } ) {
  const data = COMING_SOON_DATA[ tabId ];

  if ( ! data ) {
    return null;
  }

  return (
    <div className="mission-settings-panel">
      <div className="mission-settings-card">
        <div className="mission-tools-coming-soon">
          <div
            className="mission-tools-coming-soon__icon"
            style={ data.iconStyle }
          >
            { data.icon }
          </div>
          <div className="mission-tools-coming-soon__badge">
            { __( 'Coming Soon', 'missionwp-donation-platform' ) }
          </div>
          <h2 className="mission-tools-coming-soon__title">{ data.title }</h2>
          <p className="mission-tools-coming-soon__desc">{ data.desc }</p>
          <div className="mission-tools-coming-soon__features">
            { data.features.map( ( feature ) => (
              <div
                className="mission-tools-coming-soon__feature"
                key={ feature }
              >
                { checkIcon }
                <span>{ feature }</span>
              </div>
            ) ) }
          </div>
          <NotifySection
            tabId={ tabId }
            ctaText={
              data.cta ||
              __( 'Get notified when it ships', 'missionwp-donation-platform' )
            }
          />
        </div>
      </div>
    </div>
  );
}
