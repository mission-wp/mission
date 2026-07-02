/**
 * Fundraiser sign-up modal — Interactivity API store.
 *
 * Drives the multi-step modal: account step (login / inline 6-digit code /
 * password reset / signed-in shortcut), fundraiser setup, and the share
 * success screen. Open/step state is global so the trigger buttons in other
 * blocks can open it; per-page config (REST URL, nonce, campaign, preselected
 * team, signed-in donor) is read from this block's own context.
 */
/* global navigator */
// Note: this is a script module, where @wordpress/i18n can't be imported (same
// as donation-form/view.js). User-facing copy is translated server-side and
// passed through the block context (ctx.i18n); the literals here are
// English-only fallbacks.
import { store, getContext, getElement } from '@wordpress/interactivity';

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

// The element focused before the modal opened, restored on close.
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

const { state } = store( 'mission-donation-platform/p2p-signup', {
  state: {
    isOpen: false,
    currentStep: 1,
    step1View: 'form',
    otpPurpose: 'signup',
    // Account fields.
    firstName: '',
    lastName: '',
    email: '',
    phone: '',
    password: '',
    newPassword: '',
    resetGrant: '',
    // Setup fields.
    teamMode: 'join',
    teamPrivate: false,
    teamId: '',
    teamName: '',
    inviteToken: '',
    goal: 0,
    story: '',
    tributeChecked: false,
    tributeType: 'honor',
    honoreeName: '',
    // Signed-in donor (seeded from context on init).
    signedIn: false,
    donorName: '',
    donorEmail: '',
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
    open() {
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
        state.isOpen = false;
        document.body.style.overflow = '';
        restoreFocus();
        return;
      }

      // Trap Tab inside the dialog while the modal is open.
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

      // Enter submits the active step, except from the story textarea.
      if ( event.key !== 'Enter' || event.target.tagName === 'TEXTAREA' ) {
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
    },
    updateLastName( event ) {
      state.lastName = event.target.value;
    },
    updateEmail( event ) {
      state.email = event.target.value;
    },
    updatePhone( event ) {
      state.phone = event.target.value;
    },
    updatePassword( event ) {
      state.password = event.target.value;
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
    },
    continueSignedIn() {
      state.currentStep = 2;
    },

    // Step 1: resolve the account branch.
    *continueAccount() {
      state.firstNameError = ! state.firstName.trim();
      state.lastNameError = ! state.lastName.trim();
      state.emailError = ! state.email.trim();
      state.passwordError = ! state.password;
      state.showPasswordWarning = false;
      state.formError = '';

      if (
        state.firstNameError ||
        state.lastNameError ||
        state.emailError ||
        state.passwordError
      ) {
        return;
      }

      const ctx = getContext();
      state.loading = true;
      try {
        const res = yield post( ctx, 'p2p/account-lookup', {
          campaign_id: ctx.campaignId,
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

    // Step 1 (branch b): send a reset code.
    *startReset() {
      const ctx = getContext();
      state.otpPurpose = 'reset';
      state.otpError = '';
      state.loading = true;
      try {
        const res = yield post( ctx, 'p2p/send-code', {
          email: state.email.trim(),
          purpose: 'reset',
        } );
        const data = yield res.json();
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
      try {
        const res = yield post( ctx, 'p2p/send-code', {
          email: state.email.trim(),
          purpose: state.otpPurpose,
        } );
        const data = yield res.json();
        startCooldown( ( data && data.cooldown ) || 30 );
      } catch ( e ) {
        // Resend failures are non-fatal; the user can try again.
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
              campaign_id: ctx.campaignId,
              email: state.email.trim(),
              purpose: 'reset',
              code,
            }
          : {
              campaign_id: ctx.campaignId,
              email: state.email.trim(),
              purpose: 'signup',
              code,
              first_name: state.firstName.trim(),
              last_name: state.lastName.trim(),
              phone: state.phone.trim(),
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
          campaign_id: ctx.campaignId,
          team_mode: ctx.preselectedTeamId ? 'join' : state.teamMode,
          team_id:
            ctx.preselectedTeamId ||
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

    // Share.
    shareFacebook() {
      window.open(
        'https://www.facebook.com/sharer/sharer.php?u=' +
          encodeURIComponent( shareUrl() ),
        '_blank',
        'noopener,width=600,height=500'
      );
    },
    shareX() {
      window.open(
        'https://twitter.com/intent/tweet?url=' +
          encodeURIComponent( shareUrl() ),
        '_blank',
        'noopener,width=600,height=500'
      );
    },
    shareBluesky() {
      window.open(
        'https://bsky.app/intent/compose?text=' +
          encodeURIComponent( shareUrl() ),
        '_blank',
        'noopener,width=600,height=500'
      );
    },
    copyLink() {
      if ( ! navigator.clipboard ) {
        return;
      }
      navigator.clipboard.writeText( shareUrl() ).catch( () => {} );
      state.copied = true;
      state.copyLabel = i18n( 'copied', 'Copied' );
      setTimeout( () => {
        state.copied = false;
        state.copyLabel = i18n( 'copy', 'Copy' );
      }, 2000 );
    },
  },

  callbacks: {
    init() {
      const ctx = getContext();
      state.signedIn = !! ctx.signedIn;
      state.donorName = ctx.donorName || '';
      state.donorEmail = ctx.donorEmail || '';
      state.copyLabel = i18n( 'copy', 'Copy' );
      state.step1View = ctx.signedIn ? 'signedin' : 'form';
      if ( ! state.goal ) {
        // The server provides the default goal in major units, ready to display.
        state.goal = ctx.defaultGoal || 0;
      }
      if ( ctx.preselectedTeamId ) {
        state.teamMode = 'join';
        state.teamId = String( ctx.preselectedTeamId );
      }

      // An invite link (?team_invite=<token>) carries the invitation token and
      // opens the modal straight away so the invitee can accept.
      const params = new URLSearchParams( window.location.search );
      const token = params.get( 'team_invite' );
      if ( token ) {
        state.inviteToken = token;
        state.isOpen = true;
        state.currentStep = 1;
        state.step1View = ctx.signedIn ? 'signedin' : 'form';
        document.body.style.overflow = 'hidden';
        focusIntoDialog();
      }
    },
  },
} );
