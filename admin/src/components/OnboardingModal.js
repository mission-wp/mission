import {
  useState,
  useEffect,
  useRef,
  useCallback,
  createPortal,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { majorToMinor } from '@shared/currencies';

import StepNav from './onboarding/StepNav';
import StepBasics from './onboarding/StepBasics';
import StepDetails from './onboarding/StepDetails';
import StepPayments from './onboarding/StepPayments';
import StepCampaign from './onboarding/StepCampaign';
import StepDone from './onboarding/StepDone';

const TOTAL_STEPS = 5;
const DRAFT_KEY = 'missiondp_onboarding_draft';

const DEFAULT_DATA = {
  org_name: window.missiondpAdmin?.orgName || '',
  org_country: 'US',
  currency: window.missiondpAdmin?.currency || 'USD',
  org_ein: '',
  org_street: '',
  org_city: '',
  org_state: '',
  org_zip: '',
  primary_color: '#2fa36b',
  campaign_name: '',
  campaign_goal: '',
  campaign_goal_type: 'amount',
  campaign_description: '',
  campaign_date_start: '',
  campaign_date_end: '',
  campaign_image: null,
  campaign_image_url: '',
};

function loadDraft() {
  try {
    const raw = window.localStorage.getItem( DRAFT_KEY );
    return raw ? JSON.parse( raw ) : null;
  } catch {
    return null;
  }
}

function saveDraft( data, step ) {
  try {
    window.localStorage.setItem( DRAFT_KEY, JSON.stringify( { data, step } ) );
  } catch {
    // Storage full or unavailable — non-critical.
  }
}

function clearDraft() {
  try {
    window.localStorage.removeItem( DRAFT_KEY );
  } catch {
    // Non-critical.
  }
}

/**
 * Mission logo SVG — inline version of assets/img/icon.svg.
 */
function MissionLogo() {
  return (
    <svg width="18" height="18" viewBox="0 0 200 200" fill="currentColor">
      <path d="M 75.768 35.247 C 84.747 35.247 92.994 38.527 99.378 43.942 C 105.76 38.527 114.012 35.247 122.988 35.247 C 143.116 35.247 159.584 51.717 159.584 71.847 L 159.584 91.545 L 159.584 101.435 L 159.584 104.534 C 159.584 111.679 153.739 117.522 146.596 117.522 L 146.594 117.522 C 139.451 117.522 133.61 111.677 133.61 104.534 L 133.61 101.435 L 133.61 91.545 L 133.61 71.847 C 133.61 66.055 128.781 61.225 122.988 61.225 C 117.196 61.225 112.364 66.055 112.364 71.847 L 112.364 111.578 L 112.364 114.681 C 112.364 121.823 106.524 127.669 99.378 127.669 C 92.235 127.669 86.39 121.823 86.39 114.681 L 86.39 111.578 L 86.39 101.691 L 86.39 71.847 C 86.39 66.055 81.561 61.225 75.768 61.225 C 69.978 61.225 65.149 66.055 65.149 71.847 L 65.149 91.545 L 65.149 101.435 L 65.149 104.534 C 65.149 111.679 59.304 117.522 52.161 117.522 L 52.159 117.522 C 45.015 117.522 39.17 111.677 39.17 104.534 L 39.17 101.435 L 39.17 91.545 L 39.17 71.847 C 39.17 51.719 55.641 35.247 75.768 35.247 z M 66.785 135.525 C 70.108 135.525 73.431 136.791 75.966 139.326 L 76.101 139.463 C 82.003 145.297 90.104 148.911 99.007 148.911 L 99.234 148.911 L 99.463 148.911 C 108.366 148.911 116.466 145.297 122.367 139.463 L 122.501 139.326 C 127.573 134.256 135.796 134.256 140.868 139.326 C 145.939 144.398 145.939 152.621 140.868 157.692 C 130.255 168.31 115.604 174.887 99.463 174.887 L 99.234 174.887 L 99.007 174.887 C 82.866 174.887 68.215 168.31 57.603 157.692 C 52.531 152.621 52.531 144.398 57.603 139.326 C 60.138 136.791 63.461 135.525 66.785 135.525 z" />
    </svg>
  );
}

export default function OnboardingModal( { onComplete } ) {
  const draft = loadDraft();
  const [ step, setStep ] = useState( draft?.step || 1 );
  const [ data, setData ] = useState(
    draft?.data ? { ...DEFAULT_DATA, ...draft.data } : DEFAULT_DATA
  );
  const [ errors, setErrors ] = useState( {} );
  const [ stripeConnected, setStripeConnected ] = useState(
    window.missiondpAdmin?.stripeConnected ?? false
  );
  const [ stripeDisplayName, setStripeDisplayName ] = useState( '' );
  const [ connectError, setConnectError ] = useState( '' );
  const [ isSaving, setIsSaving ] = useState( false );
  const [ isClosing, setIsClosing ] = useState( false );

  const stepsWrapRef = useRef( null );
  const transitioningRef = useRef( false );

  const updateData = useCallback(
    ( updates ) => {
      setData( ( prev ) => {
        const next = { ...prev, ...updates };
        saveDraft( next, step );
        return next;
      } );
      // Clear errors for fields being updated.
      setErrors( ( prev ) => {
        const next = { ...prev };
        for ( const key of Object.keys( updates ) ) {
          delete next[ key ];
        }
        return next;
      } );
    },
    [ step ]
  );

  // Fetch current settings on mount to pre-fill.
  useEffect( () => {
    apiFetch( {
      path: '/mission-donation-platform/v1/settings',
      headers: { 'X-WP-Nonce': window.missiondpAdmin.restNonce },
    } )
      .then( ( settings ) => {
        setData( ( prev ) => ( {
          ...prev,
          org_name: settings.org_name || prev.org_name,
          org_country: settings.org_country || prev.org_country,
          currency: settings.currency || prev.currency,
          org_ein: settings.org_ein || prev.org_ein,
          org_street: settings.org_street || prev.org_street,
          org_city: settings.org_city || prev.org_city,
          org_state: settings.org_state || prev.org_state,
          org_zip: settings.org_zip || prev.org_zip,
          primary_color: settings.primary_color || prev.primary_color,
        } ) );
        if ( settings.stripe_connection_status === 'connected' ) {
          setStripeConnected( true );
          setStripeDisplayName( settings.stripe_display_name || '' );
        }
      } )
      .catch( () => {
        // Settings pre-fill failed — defaults are fine.
      } );
  }, [] );

  // Handle Stripe OAuth return.
  useEffect( () => {
    const params = new URLSearchParams( window.location.search );

    // Handle OAuth denial (user clicked "Return to Mission" on Stripe).
    if ( params.get( 'stripe_error' ) === 'access_denied' ) {
      const url = new URL( window.location.href );
      url.searchParams.delete( 'stripe_error' );
      window.history.replaceState( {}, '', url.toString() );

      setConnectError(
        __(
          'Stripe connection was cancelled. You can try again whenever you\u2019re ready.',
          'mission-donation-platform'
        )
      );
      if ( step !== 3 ) {
        goToStep( 3, step < 3 ? 'forward' : 'backward' );
      }
      return;
    }

    const setupCode = params.get( 'setup_code' );
    const siteId = params.get( 'site_id' );

    if ( ! setupCode || ! siteId ) {
      return;
    }

    const chargesEnabled = params.get( 'charges_enabled' );

    // Clean URL.
    const url = new URL( window.location.href );
    url.searchParams.delete( 'setup_code' );
    url.searchParams.delete( 'site_id' );
    url.searchParams.delete( 'charges_enabled' );
    window.history.replaceState( {}, '', url.toString() );

    // Finalize Stripe connection.
    apiFetch( {
      path: '/mission-donation-platform/v1/stripe/connect',
      method: 'POST',
      headers: { 'X-WP-Nonce': window.missiondpAdmin.restNonce },
      data: {
        setup_code: setupCode,
        site_id: siteId,
        charges_enabled: chargesEnabled === '1',
      },
    } )
      .then( ( result ) => {
        setStripeConnected( true );
        setStripeDisplayName( result.stripe_display_name || '' );
        if ( ! result.stripe_charges_enabled ) {
          setConnectError(
            __(
              'Stripe connected, but your account isn\u2019t ready to accept payments yet. Finish setting up your account on the Stripe Dashboard.',
              'mission-donation-platform'
            )
          );
        }
        // Jump to step 3 to show the connected state.
        if ( step !== 3 ) {
          goToStep( 3, step < 3 ? 'forward' : 'backward' );
        }
      } )
      .catch( () => {
        // Connection failed — user can retry.
      } );
  }, [] ); // eslint-disable-line react-hooks/exhaustive-deps

  // Lock body scroll.
  useEffect( () => {
    document.body.classList.add( 'mission-onboarding-open' );
    return () => {
      document.body.classList.remove( 'mission-onboarding-open' );
    };
  }, [] );

  // Set initial height.
  useEffect( () => {
    window.requestAnimationFrame( () => {
      const activePanel = stepsWrapRef.current?.querySelector(
        '.mission-onboarding-step.is-active'
      );
      if ( activePanel && stepsWrapRef.current ) {
        stepsWrapRef.current.style.height = activePanel.offsetHeight + 'px';
      }
    } );
  }, [] );

  // ---- Validation ----

  function validate( targetStep ) {
    const newErrors = {};

    if ( targetStep === 1 ) {
      if ( ! data.org_name.trim() ) {
        newErrors.org_name = __(
          'Organization name is required.',
          'mission-donation-platform'
        );
      }
    }

    if ( targetStep === 4 ) {
      if ( ! data.campaign_name.trim() ) {
        newErrors.campaign_name = __(
          'Campaign name is required.',
          'mission-donation-platform'
        );
      }
      const goalNum = parseFloat(
        data.campaign_goal.toString().replace( /,/g, '' )
      );
      if ( ! goalNum || goalNum <= 0 ) {
        newErrors.campaign_goal = __(
          'Goal amount is required.',
          'mission-donation-platform'
        );
      }
    }

    setErrors( newErrors );
    return Object.keys( newErrors ).length === 0;
  }

  // ---- Step transitions ----

  function goToStep( targetStep, direction ) {
    if ( transitioningRef.current ) {
      return;
    }
    transitioningRef.current = true;

    const wrap = stepsWrapRef.current;
    const currentPanel = wrap?.querySelector(
      '.mission-onboarding-step.is-active.is-visible'
    );
    const targetPanel = wrap?.querySelector(
      `.mission-onboarding-step[data-step="${ targetStep }"]`
    );

    if ( ! currentPanel || ! targetPanel ) {
      transitioningRef.current = false;
      return;
    }

    // Measure target height.
    targetPanel.classList.add( 'is-measuring' );
    const targetHeight = targetPanel.offsetHeight;
    targetPanel.classList.remove( 'is-measuring' );

    // Slide out current.
    currentPanel.classList.remove( 'is-visible' );
    currentPanel.classList.add(
      direction === 'forward' ? 'is-slide-out-left' : 'is-slide-out-right'
    );

    // Animate wrapper height.
    wrap.style.height = targetHeight + 'px';

    setTimeout( () => {
      currentPanel.classList.remove(
        'is-active',
        'is-slide-out-left',
        'is-slide-out-right'
      );

      wrap.scrollTop = 0;
      targetPanel.classList.add( 'is-active' );
      targetPanel.classList.add(
        direction === 'forward' ? 'is-slide-in-right' : 'is-slide-in-left'
      );

      // Force reflow.
      // eslint-disable-next-line no-unused-expressions
      targetPanel.offsetHeight;

      targetPanel.classList.remove( 'is-slide-in-right', 'is-slide-in-left' );
      targetPanel.classList.add( 'is-visible' );

      setStep( targetStep );
      saveDraft( data, targetStep );

      setTimeout( () => {
        transitioningRef.current = false;
      }, 350 );
    }, 200 );
  }

  function handleContinue() {
    if ( step >= TOTAL_STEPS ) {
      return;
    }

    if ( ! validate( step ) ) {
      return;
    }

    goToStep( step + 1, 'forward' );
  }

  function handleSkip() {
    if ( step >= TOTAL_STEPS ) {
      return;
    }
    setErrors( {} );
    goToStep( step + 1, 'forward' );
  }

  function handleBack() {
    if ( step <= 1 ) {
      return;
    }
    setErrors( {} );
    goToStep( step - 1, 'backward' );
  }

  function handleGoToStep( targetStep ) {
    const direction = targetStep > step ? 'forward' : 'backward';
    setErrors( {} );
    goToStep( targetStep, direction );
  }

  // ---- Save and close ----

  async function saveAndClose() {
    if ( isSaving ) {
      return;
    }
    setIsSaving( true );

    try {
      // Save settings.
      await apiFetch( {
        path: '/mission-donation-platform/v1/settings',
        method: 'POST',
        headers: { 'X-WP-Nonce': window.missiondpAdmin.restNonce },
        data: {
          org_name: data.org_name,
          org_country: data.org_country,
          currency: data.currency,
          org_ein: data.org_ein,
          org_street: data.org_street,
          org_city: data.org_city,
          org_state: data.org_state,
          org_zip: data.org_zip,
          primary_color: data.primary_color,
          onboarding_completed: true,
        },
      } );

      // Create campaign if filled.
      const campaignName = data.campaign_name.trim();
      const goalNum = parseFloat(
        data.campaign_goal.toString().replace( /,/g, '' )
      );

      if ( campaignName && goalNum > 0 ) {
        const campaignData = {
          title: campaignName,
          goal_amount:
            data.campaign_goal_type === 'amount'
              ? majorToMinor( goalNum, data.currency || 'USD' )
              : goalNum,
          goal_type: data.campaign_goal_type || 'amount',
          excerpt: data.campaign_description,
        };

        if ( data.campaign_date_start ) {
          campaignData.date_start = data.campaign_date_start;
        }
        if ( data.campaign_date_end ) {
          campaignData.date_end = data.campaign_date_end;
        }
        if ( data.campaign_image ) {
          campaignData.image = data.campaign_image;
        }

        await apiFetch( {
          path: '/mission-donation-platform/v1/campaigns',
          method: 'POST',
          headers: { 'X-WP-Nonce': window.missiondpAdmin.restNonce },
          data: campaignData,
        } );
      }
    } catch {
      // Save failed — still close to avoid trapping the user.
    }

    clearDraft();
    setIsSaving( false );
    animateClose();
  }

  function animateClose() {
    setIsClosing( true );
    // Wait for close animation to finish.
    setTimeout( () => {
      onComplete();
    }, 250 );
  }

  function handleDismiss() {
    saveAndClose();
  }

  // Escape key.
  useEffect( () => {
    function onKeyDown( e ) {
      if ( e.key === 'Escape' ) {
        handleDismiss();
      }
    }
    document.addEventListener( 'keydown', onKeyDown );
    return () => document.removeEventListener( 'keydown', onKeyDown );
  }, [ data ] ); // eslint-disable-line react-hooks/exhaustive-deps

  const modal = (
    <div
      className={ `mission-onboarding-overlay${
        isClosing ? ' is-closing' : ''
      }` }
    >
      <div className="mission-onboarding-modal">
        { /* Header */ }
        <div className="mission-onboarding-header">
          <div className="mission-onboarding-brand">
            <div className="mission-onboarding-logo">
              <MissionLogo />
            </div>
            <span className="mission-onboarding-wordmark">
              { __( 'Mission', 'mission-donation-platform' ) }
            </span>
            <span className="mission-onboarding-setup-tag">
              { __( 'Setup', 'mission-donation-platform' ) }
            </span>
          </div>
          <button
            type="button"
            className="mission-onboarding-close"
            onClick={ handleDismiss }
            title={ __( 'Close', 'mission-donation-platform' ) }
          >
            <svg
              width="16"
              height="16"
              viewBox="0 0 16 16"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
            >
              <path d="M4 4l8 8M12 4l-8 8" />
            </svg>
          </button>
        </div>

        { /* Step Nav */ }
        <StepNav currentStep={ step } />

        { /* Step Content */ }
        <div className="mission-onboarding-steps-wrap" ref={ stepsWrapRef }>
          { [ 1, 2, 3, 4, 5 ].map( ( s ) => (
            <div
              key={ s }
              className={ `mission-onboarding-step${
                s === step ? ' is-active is-visible' : ''
              }` }
              data-step={ s }
            >
              { s === 1 && (
                <StepBasics
                  data={ data }
                  updateData={ updateData }
                  errors={ errors }
                />
              ) }
              { s === 2 && (
                <StepDetails data={ data } updateData={ updateData } />
              ) }
              { s === 3 && (
                <StepPayments
                  stripeConnected={ stripeConnected }
                  stripeDisplayName={ stripeDisplayName }
                  connectError={ connectError }
                />
              ) }
              { s === 4 && (
                <StepCampaign
                  data={ data }
                  updateData={ updateData }
                  errors={ errors }
                />
              ) }
              { s === 5 && (
                <StepDone
                  data={ data }
                  stripeConnected={ stripeConnected }
                  onGoToStep={ handleGoToStep }
                  onComplete={ saveAndClose }
                />
              ) }
            </div>
          ) ) }
        </div>

        { /* Navigation */ }
        <div
          className={ `mission-onboarding-nav${
            step === TOTAL_STEPS ? ' is-hidden' : ''
          }` }
        >
          <button
            type="button"
            className={ `mission-onboarding-nav-back${
              step === 1 ? ' is-invisible' : ''
            }` }
            onClick={ handleBack }
          >
            <svg
              width="14"
              height="14"
              viewBox="0 0 14 14"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M11 7H3M7 3L3 7l4 4" />
            </svg>
            { __( 'Back', 'mission-donation-platform' ) }
          </button>
          <div className="mission-onboarding-nav-right">
            <button
              type="button"
              className="mission-onboarding-nav-skip"
              onClick={ handleSkip }
            >
              { __( 'Skip step', 'mission-donation-platform' ) }
            </button>
            <button
              type="button"
              className="mission-onboarding-nav-continue"
              onClick={ handleContinue }
            >
              { __( 'Continue', 'mission-donation-platform' ) }
              <svg
                width="14"
                height="14"
                viewBox="0 0 14 14"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <path d="M3 7h8M7 3l4 4-4 4" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>
  );

  return createPortal( modal, document.body );
}
