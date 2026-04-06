/**
 * Donor Dashboard — Auth state and actions.
 *
 * Handles login, email verification, password setup, and password strength.
 * Auth views use hash-based routing so the browser back button works.
 */
import { getContext } from '@wordpress/interactivity';

/**
 * Auth views that can be reached via hash navigation.
 *
 * Views like 'set-password' and 'reset-password' are server-driven (from URL
 * params) and are intentionally excluded — they require tokens that only exist
 * in the original email link.
 */
const HASH_VIEWS = [
  'login',
  'activate',
  'forgot-password',
  'activate-sent',
  'forgot-password-sent',
];

/**
 * Read the auth view from the URL hash.
 *
 * Falls back to 'login' if the hash is empty or not a valid view.
 *
 * @return {string} Auth view ID.
 */
function authViewFromHash() {
  const hash = window.location.hash.replace( '#', '' );
  return HASH_VIEWS.includes( hash ) ? hash : 'login';
}

/**
 * Reset transient auth form state when switching views.
 *
 * Preserves `authEmail` so it carries between views (e.g. login → forgot).
 *
 * @param {Object} ctx Interactivity API context.
 */
function resetAuthFormState( ctx ) {
  ctx.authError = '';
  ctx.authPassword = '';
  ctx.passwordVisible = false;
  ctx.passwordStrength = '';
  ctx.strengthLabel = '';
}

/**
 * Compute password strength from a plain-text value.
 *
 * @param {string} password The password to evaluate.
 * @return {{ level: string, label: string }} Strength level and label.
 */
function computePasswordStrength( password ) {
  if ( ! password ) {
    return { level: '', label: '' };
  }

  let score = 0;

  if ( password.length >= 8 ) {
    score++;
  }
  if ( password.length >= 12 ) {
    score++;
  }
  if ( /[a-z]/.test( password ) && /[A-Z]/.test( password ) ) {
    score++;
  }
  if ( /\d/.test( password ) ) {
    score++;
  }
  if ( /[^a-zA-Z0-9]/.test( password ) ) {
    score++;
  }

  if ( score <= 1 ) {
    return { level: 'weak', label: 'Weak — try adding more characters' };
  }
  if ( score <= 2 ) {
    return { level: 'fair', label: 'Fair — add numbers or symbols' };
  }
  if ( score <= 3 ) {
    return { level: 'good', label: 'Good password' };
  }
  return { level: 'strong', label: 'Strong password' };
}

export const authState = {
  get isLoginView() {
    return getContext().authView === 'login';
  },
  get isActivateView() {
    return getContext().authView === 'activate';
  },
  get isActivateSentView() {
    return getContext().authView === 'activate-sent';
  },
  get isSetPasswordView() {
    return getContext().authView === 'set-password';
  },
  get isForgotPasswordView() {
    return getContext().authView === 'forgot-password';
  },
  get isForgotPasswordSentView() {
    return getContext().authView === 'forgot-password-sent';
  },
  get isResetPasswordView() {
    return getContext().authView === 'reset-password';
  },
  get passwordInputType() {
    return getContext().passwordVisible ? 'text' : 'password';
  },
  get passwordToggleLabel() {
    return getContext().passwordVisible ? 'Hide password' : 'Show password';
  },
  get isStrengthWeak() {
    return getContext().passwordStrength === 'weak';
  },
  get isStrengthFair() {
    return getContext().passwordStrength === 'fair';
  },
  get isStrengthGood() {
    return getContext().passwordStrength === 'good';
  },
  get isStrengthStrong() {
    return getContext().passwordStrength === 'strong';
  },
};

export const authCallbacks = {
  /**
   * Initialise auth forms — read hash, listen for back-button navigation.
   *
   * Server-set views (set-password, reset-password) take priority over hash
   * because they require tokens from the original email link URL.
   */
  initAuth() {
    const ctx = getContext();
    const SERVER_VIEWS = [ 'set-password', 'reset-password' ];

    // Server-set views (from URL params) take priority over hash.
    if ( ! SERVER_VIEWS.includes( ctx.authView ) ) {
      ctx.authView = authViewFromHash();

      if ( ctx.authView === 'login' ) {
        ctx.authError = '';
      }
    }

    // Listen for hash changes (back/forward button).
    window.addEventListener( 'hashchange', () => {
      if ( SERVER_VIEWS.includes( ctx.authView ) ) {
        return;
      }

      const view = authViewFromHash();
      if ( view !== ctx.authView ) {
        resetAuthFormState( ctx );
        ctx.authView = view;
      }
    } );
  },
};

export const authActions = {
  // ── Field updates ──
  updateEmail( event ) {
    getContext().authEmail = event.target.value;
  },
  updatePassword( event ) {
    const ctx = getContext();
    ctx.authPassword = event.target.value;

    // Recalculate strength for views with password creation.
    if (
      ctx.authView === 'activate' ||
      ctx.authView === 'set-password' ||
      ctx.authView === 'reset-password'
    ) {
      const { level, label } = computePasswordStrength( ctx.authPassword );
      ctx.passwordStrength = level;
      ctx.strengthLabel = label;
    }
  },
  toggleRemember() {
    const ctx = getContext();
    ctx.authRemember = ! ctx.authRemember;
  },
  togglePasswordVisibility() {
    const ctx = getContext();
    ctx.passwordVisible = ! ctx.passwordVisible;
  },

  // ── View switching ──
  showLogin() {
    window.location.hash = 'login';
  },
  showActivate() {
    window.location.hash = 'activate';
  },
  showForgotPassword() {
    window.location.hash = 'forgot-password';
  },

  // ── Form submissions ──
  *submitLogin( event ) {
    event.preventDefault();
    const ctx = getContext();

    if ( ! ctx.authEmail || ! ctx.authPassword ) {
      ctx.authError = 'Please enter your email and password.';
      return;
    }

    ctx.authError = '';
    ctx.authLoading = true;

    try {
      const response = yield fetch( ctx.restUrl + 'donor-auth/login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': ctx.nonce,
        },
        body: JSON.stringify( {
          email: ctx.authEmail,
          password: ctx.authPassword,
          remember: ctx.authRemember,
        } ),
      } );

      const data = yield response.json();

      if ( ! response.ok ) {
        ctx.authError = data.message || 'Login failed. Please try again.';
        ctx.authLoading = false;
        return;
      }

      // Reload to show the dashboard.
      window.location.reload();
    } catch {
      ctx.authError = 'Something went wrong. Please try again.';
      ctx.authLoading = false;
    }
  },

  /**
   * Send a verification email (activate step 1).
   *
   * @param {Event} event Submit event.
   */
  *submitActivate( event ) {
    event.preventDefault();
    const ctx = getContext();

    if ( ! ctx.authEmail ) {
      ctx.authError = 'Please enter your email address.';
      return;
    }

    ctx.authError = '';
    ctx.authLoading = true;

    try {
      const response = yield fetch(
        ctx.restUrl + 'donor-auth/send-activation',
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
          body: JSON.stringify( {
            email: ctx.authEmail,
          } ),
        }
      );

      yield response.json();

      // Always show the "check your email" view (even on error) to
      // prevent email enumeration.
      ctx.authLoading = false;
      window.location.hash = 'activate-sent';
    } catch {
      ctx.authError = 'Something went wrong. Please try again.';
      ctx.authLoading = false;
    }
  },

  /**
   * Complete activation with a password (activate step 2, after email verification).
   *
   * @param {Event} event Submit event.
   */
  *submitSetPassword( event ) {
    event.preventDefault();
    const ctx = getContext();

    if ( ! ctx.authPassword ) {
      ctx.authError = 'Please create a password.';
      return;
    }

    if ( ctx.authPassword.length < 8 ) {
      ctx.authError = 'Password must be at least 8 characters.';
      return;
    }

    ctx.authError = '';
    ctx.authLoading = true;

    try {
      const response = yield fetch( ctx.restUrl + 'donor-auth/activate', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': ctx.nonce,
        },
        body: JSON.stringify( {
          email: ctx.activationEmail,
          password: ctx.authPassword,
          token: ctx.activationToken,
        } ),
      } );

      const data = yield response.json();

      if ( ! response.ok ) {
        ctx.authError = data.message || 'Activation failed. Please try again.';
        ctx.authLoading = false;
        return;
      }

      // Strip the token params from the URL before reloading.
      const url = new URL( window.location.href );
      url.searchParams.delete( 'activation_token' );
      url.searchParams.delete( 'email' );
      window.location.replace( url.toString() );
    } catch {
      ctx.authError = 'Something went wrong. Please try again.';
      ctx.authLoading = false;
    }
  },

  /**
   * Send a password reset email (forgot password step 1).
   *
   * @param {Event} event Submit event.
   */
  *submitForgotPassword( event ) {
    event.preventDefault();
    const ctx = getContext();

    if ( ! ctx.authEmail ) {
      ctx.authError = 'Please enter your email address.';
      return;
    }

    ctx.authError = '';
    ctx.authLoading = true;

    try {
      const response = yield fetch(
        ctx.restUrl + 'donor-auth/forgot-password',
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
          body: JSON.stringify( {
            email: ctx.authEmail,
          } ),
        }
      );

      yield response.json();

      // Always show the "check your email" view (even on error) to
      // prevent email enumeration.
      ctx.authLoading = false;
      window.location.hash = 'forgot-password-sent';
    } catch {
      ctx.authError = 'Something went wrong. Please try again.';
      ctx.authLoading = false;
    }
  },

  /**
   * Reset password with key from email link (forgot password step 2).
   *
   * @param {Event} event Submit event.
   */
  *submitResetPassword( event ) {
    event.preventDefault();
    const ctx = getContext();

    if ( ! ctx.authPassword ) {
      ctx.authError = 'Please enter a new password.';
      return;
    }

    if ( ctx.authPassword.length < 8 ) {
      ctx.authError = 'Password must be at least 8 characters.';
      return;
    }

    ctx.authError = '';
    ctx.authLoading = true;

    try {
      const response = yield fetch( ctx.restUrl + 'donor-auth/reset-password', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': ctx.nonce,
        },
        body: JSON.stringify( {
          login: ctx.resetLogin,
          key: ctx.resetKey,
          password: ctx.authPassword,
        } ),
      } );

      const data = yield response.json();

      if ( ! response.ok ) {
        ctx.authError =
          data.message || 'Password reset failed. Please try again.';
        ctx.authLoading = false;
        return;
      }

      // Strip the reset params from the URL before reloading.
      const url = new URL( window.location.href );
      url.searchParams.delete( 'action' );
      url.searchParams.delete( 'key' );
      url.searchParams.delete( 'login' );
      window.location.replace( url.toString() );
    } catch {
      ctx.authError = 'Something went wrong. Please try again.';
      ctx.authLoading = false;
    }
  },
};
