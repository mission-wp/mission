/**
 * Fundraiser sign-up modal — Interactivity API store.
 *
 * The modal is not a block: SignupModal::render() outputs the dialog shell
 * once per page (this directory only exists so wp-scripts builds this module
 * and its stylesheet; the block.json is a build manifest, not a registered
 * block). Drives the multi-step modal: account step (login / inline 6-digit
 * code / password reset / signed-in shortcut), fundraiser setup, and the
 * share success screen against the /p2p REST routes.
 *
 * Campaign binding: every campaign-dependent value (campaignId, brandline,
 * teams list, goal, success copy) lives in global state, server-seeded with
 * the shell's default payload. CTA blocks embed their own campaign's payload
 * in their context and pass it to `open( payload )`, which rebinds the modal;
 * `open()` with no payload (e.g. the invite-link auto-open) keeps the default.
 * Request plumbing (REST URL, nonce, i18n, share templates) is read from the
 * shell's own context.
 */
/* global navigator */
// Script modules can't import @wordpress/i18n; copy is translated server-side
// and passed via ctx.i18n, so the literals here are English-only fallbacks.
import { store, getContext, getElement } from '@wordpress/interactivity';

import './style.scss';

let cooldownTimer = null;

/**
 * A translated string from the block context, with an English fallback.
 *
 * @param {string} key      Key in the context's i18n map.
 * @param {string} fallback English fallback.
 * @return {string} Translated string.
 */
function i18n( key, fallback ) {
  const ctx = getContext();
  return ( ctx.i18n && ctx.i18n[ key ] ) || fallback;
}

function genericError() {
  return i18n( 'genericError', 'Something went wrong. Please try again.' );
}

/**
 * POST JSON to a REST route with the nonce attached.
 *
 * @param {Object} ctx  Element context (restUrl, nonce).
 * @param {string} path REST path after the namespace.
 * @param {Object} body Request body.
 * @return {Promise} Fetch promise.
 */
function post( ctx, path, body ) {
  return fetch( ctx.restUrl + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.nonce },
    credentials: 'same-origin',
    body: JSON.stringify( body ),
  } );
}

/**
 * Start (or restart) the resend cooldown countdown.
 *
 * @param {number} seconds Cooldown length.
 */
function startCooldown( seconds ) {
  state.resendIn = seconds;
  state.resendLabel = seconds > 0 ? ` (${ seconds }s)` : '';
  clearInterval( cooldownTimer );
  cooldownTimer = setInterval( () => {
    state.resendIn -= 1;
    if ( state.resendIn <= 0 ) {
      clearInterval( cooldownTimer );
      state.resendIn = 0;
      state.resendLabel = '';
    } else {
      state.resendLabel = ` (${ state.resendIn }s)`;
    }
  }, 1000 );
}

/**
 * The fundraiser's public URL to share (falls back to the current page).
 *
 * @return {string} URL.
 */
function shareUrl() {
  return state.successUrl || window.location.href;
}

/**
 * Open a share-intent popup for a network.
 *
 * @param {string} network  Network key in the shell context's shareTemplates.
 * @param {string} fallback English/default intent URL template with a %s slot.
 */
function openShareIntent( network, fallback ) {
  const ctx = getContext();
  const template =
    ( ctx.shareTemplates && ctx.shareTemplates[ network ] ) || fallback;
  window.open(
    template.replace( '%s', encodeURIComponent( shareUrl() ) ),
    '_blank',
    'noopener,width=600,height=500'
  );
}

let lastFocused = null;

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])';

function dialogElement() {
  return document.querySelector( '.mission-su__dialog' );
}

/**
 * Visible focusable elements inside the dialog, in tab order.
 *
 * @param {Element} dialog The dialog element.
 * @return {Element[]} Focusable elements.
 */
function focusablesIn( dialog ) {
  return Array.from( dialog.querySelectorAll( FOCUSABLE_SELECTOR ) ).filter(
    ( el ) => el.offsetParent !== null
  );
}

/**
 * Move focus into the dialog once it has rendered open.
 */
function focusIntoDialog() {
  const dialog = dialogElement();
  if ( ! dialog ) {
    return;
  }
  lastFocused = dialog.ownerDocument.activeElement;
  window.requestAnimationFrame( () => {
    const first = focusablesIn( dialog )[ 0 ];
    ( first || dialog ).focus();
  } );
}

function restoreFocus() {
  if ( lastFocused && typeof lastFocused.focus === 'function' ) {
    lastFocused.focus();
  }
  lastFocused = null;
}

/**
 * Record that the visitor authenticated mid-flow, so Back and reopen behave
 * as they do for donors who arrived signed in.
 */
function markSignedIn() {
  state.signedIn = true;
  state.donorName =
    `${ state.firstName.trim() } ${ state.lastName.trim() }`.trim();
  state.donorEmail = state.email.trim();
}

/**
 * Rebind the modal to a campaign payload (see SignupModal::payload()).
 *
 * When the payload targets a different campaign than the modal is currently
 * bound to, all transient form state is reset first so a half-completed form
 * for one campaign never leaks into another's. Page-level state (signed-in
 * donor, invite token) survives.
 *
 * @param {Object} payload Sign-up payload, or undefined to keep the current binding.
 */
function applyPayload( payload ) {
  if ( ! payload || ! payload.campaignId ) {
    return;
  }
  const rebinding = payload.campaignId !== state.campaignId;
  if ( rebinding ) {
    state.firstName = '';
    state.lastName = '';
    state.email = '';
    state.password = '';
    state.newPassword = '';
    state.resetGrant = '';
    state.otpPurpose = 'signup';
    state.teamMode = 'join';
    state.teamPrivate = false;
    state.teamId = '';
    state.teamName = '';
    state.story = '';
    state.tributeChecked = false;
    state.tributeType = 'honor';
    state.honoreeName = '';
    state.successUrl = '';
    state.isPending = false;
  }
  Object.assign( state, payload );
  if ( rebinding ) {
    state.goal = payload.defaultGoal || 0;
  }
  if ( payload.preselectedTeamId ) {
    state.teamMode = 'join';
    state.teamId = String( payload.preselectedTeamId );
  }
}

const { state, actions } = store( 'mission-donation-platform/p2p-signup', {
  state: {
    // The campaign binding (campaignId, brandline, currencySymbol,
    // defaultGoal, teamCreationEnabled, showTeamChooser, preselectedTeamId,
    // preselectedTeamName, teams, storyPlaceholder, success/pending copy) and
    // the signed-in donor (signedIn, donorName, donorEmail, goal) are NOT
    // declared here: they arrive server-seeded via wp_interactivity_state()
    // (see SignupModal::render()), and store() definitions override server
    // state, so literal defaults would clobber the seed. open( payload )
    // rebinds them.
    isOpen: false,
    currentStep: 1,
    step1View: 'form',
    otpPurpose: 'signup',
    // Account fields.
    firstName: '',
    lastName: '',
    email: '',
    password: '',
    newPassword: '',
    resetGrant: '',
    // Setup fields.
    teamMode: 'join',
    teamPrivate: false,
    teamId: '',
    teamName: '',
    inviteToken: '',
    story: '',
    tributeChecked: false,
    tributeType: 'honor',
    honoreeName: '',
    // UI state.
    loading: false,
    firstNameError: false,
    lastNameError: false,
    emailError: false,
    passwordError: false,
    showPasswordWarning: false,
    formError: '',
    otpError: '',
    resendIn: 0,
    resendLabel: '',
    successUrl: '',
    isPending: false,
    copyLabel: 'Copy',
    copied: false,

    get isStep1() {
      return state.currentStep === 1;
    },
    get isStep2() {
      return state.currentStep === 2;
    },
    get isStep3() {
      return state.currentStep === 3;
    },
    get isFormView() {
      return state.currentStep === 1 && state.step1View === 'form';
    },
    get isOtpView() {
      return state.currentStep === 1 && state.step1View === 'otp';
    },
    get isNewpassView() {
      return state.currentStep === 1 && state.step1View === 'newpass';
    },
    get isSignedInView() {
      return state.currentStep === 1 && state.step1View === 'signedin';
    },
    get isJoinMode() {
      return state.teamMode === 'join';
    },
    get isCreateMode() {
      return state.teamMode === 'create';
    },
    get isHonor() {
      return state.tributeType === 'honor';
    },
    get isMemory() {
      return state.tributeType === 'memory';
    },
  },

  actions: {
    // Note: open() may be called cross-store from a CTA block's scope, so it
    // must not read getContext() — everything it needs travels in the payload.
    open( payload ) {
      applyPayload( payload );
      state.isOpen = true;
      state.currentStep = 1;
      state.step1View = state.signedIn ? 'signedin' : 'form';
      state.formError = '';
      state.otpError = '';
      state.showPasswordWarning = false;
      state.firstNameError = false;
      state.lastNameError = false;
      state.emailError = false;
      state.passwordError = false;
      document.body.style.overflow = 'hidden';
      focusIntoDialog();
    },
    close() {
      state.isOpen = false;
      document.body.style.overflow = '';
      restoreFocus();
    },
    onKeydown( event ) {
      if ( event.key === 'Escape' ) {
        actions.close();
        return;
      }

      if ( event.key === 'Tab' ) {
        const dialog = dialogElement();
        const focusables = dialog ? focusablesIn( dialog ) : [];
        if ( ! focusables.length ) {
          return;
        }
        const first = focusables[ 0 ];
        const last = focusables[ focusables.length - 1 ];
        const active = dialog.ownerDocument.activeElement;
        if ( event.shiftKey && ( active === first || active === dialog ) ) {
          event.preventDefault();
          last.focus();
        } else if ( ! event.shiftKey && active === last ) {
          event.preventDefault();
          first.focus();
        }
        return;
      }

      // Enter acts as implicit submit only from plain fields; interactive
      // controls (links, toggles, close, select) keep native activation.
      if (
        event.key !== 'Enter' ||
        event.target.closest( 'button, a, select, textarea, [role="button"]' )
      ) {
        return;
      }
      const root = event.target.closest( '.mission-su' );
      if ( ! root ) {
        return;
      }
      event.preventDefault();
      const primary = Array.from(
        root.querySelectorAll(
          'button.mission-su__btn:not(.mission-su__btn--ghost)'
        )
      ).find( ( el ) => el.offsetParent !== null && ! el.disabled );
      if ( primary ) {
        primary.click();
      }
    },

    // Field updaters.
    updateFirstName( event ) {
      state.firstName = event.target.value;
      state.firstNameError = false;
    },
    updateLastName( event ) {
      state.lastName = event.target.value;
      state.lastNameError = false;
    },
    updateEmail( event ) {
      state.email = event.target.value;
      state.emailError = false;
    },
    updatePassword( event ) {
      state.password = event.target.value;
      state.passwordError = false;
    },
    updateNewPassword( event ) {
      state.newPassword = event.target.value;
    },
    updateTeamId( event ) {
      state.teamId = event.target.value;
    },
    updateTeamName( event ) {
      state.teamName = event.target.value;
    },
    updateTeamPrivate( event ) {
      state.teamPrivate = event.target.checked;
    },
    updateGoal( event ) {
      state.goal = event.target.value;
    },
    updateStory( event ) {
      state.story = event.target.value;
    },
    updateHonoreeName( event ) {
      state.honoreeName = event.target.value;
    },
    setJoinMode() {
      state.teamMode = 'join';
    },
    setCreateMode() {
      state.teamMode = 'create';
    },
    toggleTribute( event ) {
      state.tributeChecked = !! event.target.checked;
    },
    setHonor() {
      state.tributeType = 'honor';
    },
    setMemory() {
      state.tributeType = 'memory';
    },
    showForm() {
      state.step1View = 'form';
      state.otpError = '';
    },
    back() {
      state.currentStep = 1;
      state.step1View = state.signedIn ? 'signedin' : 'form';
    },
    continueSignedIn() {
      state.currentStep = 2;
    },

    *continueAccount() {
      state.firstNameError = ! state.firstName.trim();
      state.lastNameError = ! state.lastName.trim();
      state.emailError = ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
        state.email.trim()
      );
      state.passwordError = ! state.password;
      state.showPasswordWarning = false;
      state.formError = '';

      if (
        state.firstNameError ||
        state.lastNameError ||
        state.emailError ||
        state.passwordError
      ) {
        state.formError = i18n(
          'checkFields',
          'Please check the highlighted fields.'
        );
        const root = getElement().ref?.closest( '.mission-su' );
        // The error classes land on the next render; focus after it.
        window.requestAnimationFrame( () => {
          root?.querySelector( '[aria-invalid="true"]' )?.focus();
        } );
        return;
      }

      const ctx = getContext();
      state.loading = true;
      try {
        const res = yield post( ctx, 'p2p/account-lookup', {
          campaign_id: state.campaignId,
          email: state.email.trim(),
          password: state.password,
        } );
        const data = yield res.json();

        if ( ! res.ok ) {
          state.formError = data.message || genericError();
          return;
        }

        if ( data.branch === 'authenticated' ) {
          if ( data.nonce ) {
            ctx.nonce = data.nonce;
          }
          state.currentStep = 2;
        } else if ( data.branch === 'password_mismatch' ) {
          state.showPasswordWarning = true;
          state.passwordError = true;
        } else if ( data.branch === 'verify_required' ) {
          state.otpPurpose = 'signup';
          state.step1View = 'otp';
          startCooldown( data.cooldown || 30 );
        }
      } catch ( e ) {
        state.formError = genericError();
      } finally {
        state.loading = false;
      }
    },

    *startReset() {
      const ctx = getContext();
      state.formError = '';
      state.otpError = '';
      state.loading = true;
      try {
        const res = yield post( ctx, 'p2p/send-code', {
          email: state.email.trim(),
          purpose: 'reset',
        } );
        const data = yield res.json();

        if ( ! res.ok ) {
          state.formError = ( data && data.message ) || genericError();
          return;
        }

        state.otpPurpose = 'reset';
        state.step1View = 'otp';
        startCooldown( ( data && data.cooldown ) || 30 );
      } catch ( e ) {
        state.formError = genericError();
      } finally {
        state.loading = false;
      }
    },

    *resendCode() {
      if ( state.resendIn > 0 ) {
        return;
      }
      const ctx = getContext();
      state.otpError = '';
      try {
        const res = yield post( ctx, 'p2p/send-code', {
          email: state.email.trim(),
          purpose: state.otpPurpose,
        } );
        const data = yield res.json();

        if ( ! res.ok ) {
          state.otpError = ( data && data.message ) || genericError();
          return;
        }

        startCooldown( ( data && data.cooldown ) || 30 );
      } catch ( e ) {
        state.otpError = genericError();
      }
    },

    *verifyCode() {
      const { ref } = getElement();
      const root = ref.closest( '.mission-su' );
      const inputs = root
        ? Array.from( root.querySelectorAll( '.mission-su__otp-input' ) )
        : [];
      const code = inputs.map( ( input ) => input.value ).join( '' );

      state.otpError = '';
      if ( code.length !== 6 ) {
        state.otpError = i18n( 'enterCode', 'Enter the 6-digit code.' );
        return;
      }

      const ctx = getContext();
      const body =
        state.otpPurpose === 'reset'
          ? {
              campaign_id: state.campaignId,
              email: state.email.trim(),
              purpose: 'reset',
              code,
            }
          : {
              campaign_id: state.campaignId,
              email: state.email.trim(),
              purpose: 'signup',
              code,
              first_name: state.firstName.trim(),
              last_name: state.lastName.trim(),
              password: state.password,
            };

      state.loading = true;
      try {
        const res = yield post( ctx, 'p2p/verify-code', body );
        const data = yield res.json();

        if ( ! res.ok ) {
          state.otpError = data.message || genericError();
          inputs.forEach( ( input ) => {
            input.value = '';
          } );
          if ( inputs[ 0 ] ) {
            inputs[ 0 ].focus();
          }
          return;
        }

        if ( state.otpPurpose === 'reset' ) {
          state.resetGrant = data.grant;
          state.step1View = 'newpass';
        } else {
          if ( data.nonce ) {
            ctx.nonce = data.nonce;
          }
          markSignedIn();
          state.currentStep = 2;
        }
      } catch ( e ) {
        state.otpError = genericError();
      } finally {
        state.loading = false;
      }
    },

    *savePassword() {
      state.formError = '';
      if ( ! state.newPassword ) {
        state.formError = i18n( 'enterNewPassword', 'Enter a new password.' );
        return;
      }

      const ctx = getContext();
      state.loading = true;
      try {
        const res = yield post( ctx, 'p2p/set-password', {
          email: state.email.trim(),
          grant: state.resetGrant,
          password: state.newPassword,
        } );
        const data = yield res.json();

        if ( ! res.ok ) {
          state.formError = data.message || genericError();
          return;
        }

        if ( data.nonce ) {
          ctx.nonce = data.nonce;
        }
        markSignedIn();
        state.currentStep = 2;
      } catch ( e ) {
        state.formError = genericError();
      } finally {
        state.loading = false;
      }
    },

    *submit() {
      const ctx = getContext();
      state.formError = '';
      state.loading = true;
      try {
        const res = yield post( ctx, 'p2p/register', {
          campaign_id: state.campaignId,
          team_mode: state.preselectedTeamId ? 'join' : state.teamMode,
          team_id:
            state.preselectedTeamId ||
            ( state.teamId ? Number( state.teamId ) : 0 ),
          team_name: state.teamName,
          team_access: state.teamPrivate ? 'private' : 'public',
          // Send the goal in major units; the server converts to minor.
          goal: Number( state.goal ) || 0,
          story: state.story,
          dedicate: state.tributeChecked,
          tribute_type: state.tributeType,
          honoree_name: state.honoreeName,
          invite_token: state.inviteToken,
        } );
        const data = yield res.json();

        if ( ! res.ok ) {
          state.formError = data.message || genericError();
          return;
        }

        state.successUrl = ( data.fundraiser && data.fundraiser.url ) || '';
        state.isPending =
          ( ( data.fundraiser && data.fundraiser.status ) || '' ) === 'pending';
        state.currentStep = 3;
      } catch ( e ) {
        state.formError = genericError();
      } finally {
        state.loading = false;
      }
    },

    *logout() {
      const ctx = getContext();
      try {
        yield post( ctx, 'donor-auth/logout', {} );
      } catch ( e ) {
        // Reload regardless so the modal re-renders signed out.
      }
      window.location.reload();
    },

    // OTP input helpers.
    onOtpInput( event ) {
      const input = event.target;
      input.value = input.value.replace( /\D/g, '' ).slice( 0, 1 );
      if ( input.value && input.nextElementSibling ) {
        input.nextElementSibling.focus();
      }
    },
    onOtpKeydown( event ) {
      const input = event.target;
      if (
        event.key === 'Backspace' &&
        ! input.value &&
        input.previousElementSibling
      ) {
        input.previousElementSibling.focus();
      }
    },
    onOtpPaste( event ) {
      event.preventDefault();
      const digits = ( event.clipboardData.getData( 'text' ) || '' )
        .replace( /\D/g, '' )
        .slice( 0, 6 );
      const inputs = Array.from( event.target.parentElement.children );
      digits.split( '' ).forEach( ( digit, i ) => {
        if ( inputs[ i ] ) {
          inputs[ i ].value = digit;
        }
      } );
      const next = inputs[ Math.min( digits.length, inputs.length - 1 ) ];
      if ( next ) {
        next.focus();
      }
    },

    // Share. Intent URL templates come from the shell context (built by
    // Sharing::intent_url()'s PHP templates); literals are fallbacks only.
    shareFacebook() {
      openShareIntent(
        'facebook',
        'https://www.facebook.com/sharer/sharer.php?u=%s'
      );
    },
    shareX() {
      openShareIntent( 'x', 'https://twitter.com/intent/tweet?text=%s' );
    },
    shareBluesky() {
      openShareIntent( 'bluesky', 'https://bsky.app/intent/compose?text=%s' );
    },
    copyLink() {
      if ( ! navigator.clipboard ) {
        return;
      }
      // Read all labels now: getContext() is unavailable in async callbacks.
      const copiedLabel = i18n( 'copied', 'Copied' );
      const failedLabel = i18n( 'copyFailed', 'Copy failed' );
      const idleLabel = i18n( 'copy', 'Copy' );
      return navigator.clipboard
        .writeText( shareUrl() )
        .then( () => {
          state.copied = true;
          state.copyLabel = copiedLabel;
        } )
        .catch( () => {
          state.copyLabel = failedLabel;
        } )
        .then( () => {
          setTimeout( () => {
            state.copied = false;
            state.copyLabel = idleLabel;
          }, 2000 );
        } );
    },
  },

  callbacks: {
    init() {
      // Campaign binding and the signed-in donor arrive server-seeded in
      // state; only the client-only pieces are derived here.
      state.copyLabel = i18n( 'copy', 'Copy' );
      state.step1View = state.signedIn ? 'signedin' : 'form';
      if ( state.preselectedTeamId ) {
        state.teamMode = 'join';
        state.teamId = String( state.preselectedTeamId );
      }

      // Invite links (?team_invite=<token>) open the modal straight away,
      // bound to the shell's default payload.
      const params = new URLSearchParams( window.location.search );
      const token = params.get( 'team_invite' );
      if ( token ) {
        state.inviteToken = token;
        actions.open();
      }
    },
  },
} );
