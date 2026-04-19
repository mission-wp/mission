import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { Modal, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Toast from '../components/Toast';
import GeneralPanel from './settings/GeneralPanel';
import EmailsPanel from './settings/EmailsPanel';
import DonorPortalPanel from './settings/DonorPortalPanel';

const TABS = [
  {
    id: 'general',
    label: __( 'General', 'missionwp-donation-platform' ),
    icon: (
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <circle cx="12" cy="12" r="3" />
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
      </svg>
    ),
  },
  {
    id: 'emails',
    label: __( 'Emails', 'missionwp-donation-platform' ),
    icon: (
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <rect x="2" y="4" width="20" height="16" rx="3" />
        <polyline points="22 7 12 14 2 7" />
      </svg>
    ),
  },
  {
    id: 'portal',
    label: __( 'Donor Portal', 'missionwp-donation-platform' ),
    icon: (
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
        <circle cx="12" cy="7" r="4" />
      </svg>
    ),
  },
];

function SkeletonBar( { width = '60%', height = '14px' } ) {
  return (
    <span
      className="mission-skeleton"
      style={ {
        display: 'block',
        width,
        height,
        borderRadius: '4px',
        background: '#e2e4e9',
      } }
    />
  );
}

function SkeletonField() {
  return (
    <div style={ { marginBottom: '20px' } }>
      <SkeletonBar width="90px" height="12px" />
      <div style={ { marginTop: '8px' } }>
        <SkeletonBar width="100%" height="38px" />
      </div>
    </div>
  );
}

function SettingsSkeleton() {
  return (
    <div className="mission-settings-panel">
      { [ 0, 1 ].map( ( i ) => (
        <div key={ i } className="mission-settings-card">
          <div className="mission-settings-card__header">
            <SkeletonBar width={ i === 0 ? '140px' : '100px' } height="16px" />
            <div style={ { marginTop: '6px' } }>
              <SkeletonBar width={ i === 0 ? '280px' : '220px' } />
            </div>
          </div>
          <SkeletonField />
          <SkeletonField />
          { i === 0 && <SkeletonField /> }
        </div>
      ) ) }
    </div>
  );
}

const STORAGE_KEY = 'mission_settings_tab';

function getInitialTab() {
  try {
    const stored = window.localStorage.getItem( STORAGE_KEY );
    if ( stored && TABS.some( ( t ) => t.id === stored ) ) {
      return stored;
    }
  } catch {}
  return 'general';
}

export default function Settings() {
  const [ activeTab, setActiveTab ] = useState( getInitialTab );
  const [ settings, setSettings ] = useState( null );
  const [ saving, setSaving ] = useState( false );
  const [ toast, setToast ] = useState( () => {
    const params = new URLSearchParams( window.location.search );
    if ( params.get( 'stripe_error' ) === 'access_denied' ) {
      const cleanUrl = new URL( window.location.href );
      cleanUrl.searchParams.delete( 'stripe_error' );
      window.history.replaceState( {}, '', cleanUrl.toString() );
      return {
        type: 'error',
        message: __(
          'Stripe connection was cancelled. You can try again whenever you\u2019re ready.',
          'missionwp-donation-platform'
        ),
      };
    }
    return null;
  } );
  const [ toastKey, setToastKey ] = useState( 0 );
  const clearToast = useCallback( () => setToast( null ), [] );
  const [ connecting, setConnecting ] = useState( false );
  const [ showDisconnectModal, setShowDisconnectModal ] = useState( false );
  const [ pendingCurrency, setPendingCurrency ] = useState( null );
  const savedSettings = useRef( null );

  const fetchSettings = useCallback( () => {
    apiFetch( { path: '/mission/v1/settings' } ).then( ( data ) => {
      savedSettings.current = data;
      setSettings( data );
    } );
  }, [] );

  useEffect( () => {
    fetchSettings();
  }, [ fetchSettings ] );

  // Handle OAuth return — exchange setup_code for site_token.
  useEffect( () => {
    const params = new URLSearchParams( window.location.search );
    const setupCode = params.get( 'setup_code' );
    const siteId = params.get( 'site_id' );

    if ( ! setupCode || ! siteId ) {
      return;
    }

    const chargesEnabled = params.get( 'charges_enabled' );

    // Clean the URL immediately so a refresh doesn't re-trigger.
    const cleanUrl = new URL( window.location.href );
    cleanUrl.searchParams.delete( 'setup_code' );
    cleanUrl.searchParams.delete( 'site_id' );
    cleanUrl.searchParams.delete( 'charges_enabled' );
    window.history.replaceState( {}, '', cleanUrl.toString() );

    setConnecting( true );

    apiFetch( {
      path: '/mission/v1/stripe/connect',
      method: 'POST',
      data: {
        setup_code: setupCode,
        site_id: siteId,
        charges_enabled: chargesEnabled === '1',
      },
    } )
      .then( ( data ) => {
        savedSettings.current = data;
        setSettings( data );
        setToastKey( ( k ) => k + 1 );

        if ( ! data.stripe_charges_enabled ) {
          setToast( {
            type: 'error',
            message: __(
              'Stripe connected, but your account isn\u2019t ready to accept payments yet. Finish setting up your account on the Stripe Dashboard.',
              'missionwp-donation-platform'
            ),
          } );
        } else {
          setToast( {
            type: 'success',
            message: __(
              'Stripe connected successfully!',
              'missionwp-donation-platform'
            ),
          } );
        }
      } )
      .catch( ( error ) => {
        setToastKey( ( k ) => k + 1 );
        setToast( {
          type: 'error',
          message:
            error.message ||
            __( 'Failed to connect Stripe.', 'missionwp-donation-platform' ),
        } );
      } )
      .finally( () => {
        setConnecting( false );
      } );
  }, [] );

  const isDirty =
    !! settings &&
    !! savedSettings.current &&
    JSON.stringify( settings ) !== JSON.stringify( savedSettings.current );

  useEffect( () => {
    if ( ! isDirty ) {
      return;
    }

    const handleBeforeUnload = ( event ) => {
      event.preventDefault();
    };

    window.addEventListener( 'beforeunload', handleBeforeUnload );
    return () => {
      window.removeEventListener( 'beforeunload', handleBeforeUnload );
    };
  }, [ isDirty ] );

  const updateField = ( key, value ) => {
    setSettings( ( prev ) => ( { ...prev, [ key ]: value } ) );
  };

  const handleSave = async () => {
    setSaving( true );

    try {
      const data = await apiFetch( {
        path: '/mission/v1/settings',
        method: 'POST',
        data: settings,
      } );
      savedSettings.current = data;
      setSettings( data );
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'success',
        message: __( 'Settings saved.', 'missionwp-donation-platform' ),
      } );

      const adminBarNode = document.getElementById(
        'wp-admin-bar-mission-test-mode'
      );
      if ( adminBarNode ) {
        adminBarNode.classList.toggle( 'hidden', ! data.test_mode );
      }
    } catch ( error ) {
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'error',
        message:
          error.message ||
          __( 'Failed to save settings.', 'missionwp-donation-platform' ),
      } );
    }

    setSaving( false );
  };

  const handleDisconnect = async () => {
    setSaving( true );

    try {
      const data = await apiFetch( {
        path: '/mission/v1/stripe/disconnect',
        method: 'POST',
      } );
      savedSettings.current = data;
      setSettings( data );
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'success',
        message: __( 'Stripe disconnected.', 'missionwp-donation-platform' ),
      } );
    } catch ( error ) {
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'error',
        message:
          error.message ||
          __( 'Failed to disconnect Stripe.', 'missionwp-donation-platform' ),
      } );
    }

    setSaving( false );
  };

  const handleTabChange = ( tabId ) => {
    setActiveTab( tabId );
    try {
      window.localStorage.setItem( STORAGE_KEY, tabId );
    } catch {}
  };

  const isLoading = ! settings;

  const panelProps = {
    settings,
    setSettings,
    updateField,
    saving,
    isDirty,
    handleSave,
  };

  return (
    <div className="mission-admin-page">
      <div style={ { marginBottom: '24px' } }>
        <h1 style={ { fontSize: '24px', fontWeight: 600, margin: 0 } }>
          { __( 'Settings', 'missionwp-donation-platform' ) }
        </h1>
        <p style={ { fontSize: '13px', color: '#9b9ba8', margin: '4px 0 0' } }>
          { __(
            'Configure your MissionWP plugin',
            'missionwp-donation-platform'
          ) }
        </p>
      </div>

      <Toast key={ toastKey } notice={ toast } onDone={ clearToast } />

      { connecting && (
        <div style={ { marginBottom: '20px' } }>
          <Notice status="info" isDismissible={ false }>
            { __(
              'Connecting to Stripe\u2026',
              'missionwp-donation-platform'
            ) }
          </Notice>
        </div>
      ) }

      <div className="mission-settings-layout">
        { /* Vertical Nav */ }
        <nav className="mission-settings-nav">
          <ul className="mission-settings-nav__list">
            { TABS.map( ( tab ) => (
              <li key={ tab.id }>
                <button
                  className={ `mission-settings-nav__item${
                    activeTab === tab.id ? ' is-active' : ''
                  }` }
                  onClick={ () => handleTabChange( tab.id ) }
                  type="button"
                >
                  { tab.icon }
                  { tab.label }
                </button>
              </li>
            ) ) }
          </ul>
        </nav>

        { /* Panels */ }
        <div>
          { isLoading && <SettingsSkeleton /> }

          { ! isLoading && activeTab === 'general' && (
            <GeneralPanel
              { ...panelProps }
              connecting={ connecting }
              showDisconnectModal={ showDisconnectModal }
              setShowDisconnectModal={ setShowDisconnectModal }
              handleDisconnect={ handleDisconnect }
              pendingCurrency={ pendingCurrency }
              setPendingCurrency={ setPendingCurrency }
            />
          ) }

          { ! isLoading && activeTab === 'emails' && (
            <EmailsPanel { ...panelProps } />
          ) }

          { ! isLoading && activeTab === 'portal' && (
            <DonorPortalPanel { ...panelProps } />
          ) }
        </div>
      </div>

      { /* Currency change confirmation modal */ }
      { pendingCurrency && (
        <Modal
          title={ __( 'Change Currency', 'missionwp-donation-platform' ) }
          onRequestClose={ () => setPendingCurrency( null ) }
          size="small"
        >
          <p style={ { margin: '0 0 16px' } }>
            { __(
              'Make sure this matches the default currency on your Stripe account. Mismatched currencies may cause payment failures.',
              'missionwp-donation-platform'
            ) }
          </p>
          <div
            style={ {
              display: 'flex',
              justifyContent: 'flex-end',
              gap: '8px',
            } }
          >
            <button
              className="components-button is-tertiary"
              onClick={ () => setPendingCurrency( null ) }
              type="button"
            >
              { __( 'Cancel', 'missionwp-donation-platform' ) }
            </button>
            <button
              className="components-button is-primary"
              onClick={ () => {
                updateField( 'currency', pendingCurrency );
                setPendingCurrency( null );
              } }
              style={ { backgroundColor: '#2fa36b', borderColor: '#2fa36b' } }
              type="button"
            >
              { __( 'Change Currency', 'missionwp-donation-platform' ) }
            </button>
          </div>
        </Modal>
      ) }

      { /* Stripe disconnect confirmation modal */ }
      { showDisconnectModal && (
        <Modal
          title={ __( 'Disconnect Stripe', 'missionwp-donation-platform' ) }
          onRequestClose={ () => setShowDisconnectModal( false ) }
          size="small"
        >
          <p style={ { margin: '0 0 16px' } }>
            { __(
              'Are you sure you want to disconnect your Stripe account? You will not be able to process donations until you reconnect.',
              'missionwp-donation-platform'
            ) }
          </p>
          <div
            style={ {
              display: 'flex',
              justifyContent: 'flex-end',
              gap: '8px',
            } }
          >
            <button
              className="components-button is-tertiary"
              onClick={ () => setShowDisconnectModal( false ) }
              type="button"
            >
              { __( 'Cancel', 'missionwp-donation-platform' ) }
            </button>
            <button
              className="components-button is-primary is-destructive"
              onClick={ () => {
                setShowDisconnectModal( false );
                handleDisconnect();
              } }
              type="button"
            >
              { __( 'Disconnect', 'missionwp-donation-platform' ) }
            </button>
          </div>
        </Modal>
      ) }
    </div>
  );
}
