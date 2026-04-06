/**
 * Donor Dashboard — Profile state and actions.
 *
 * Handles personal info form, save, communication preference toggles,
 * payment method display, and account deletion.
 */
import { getContext } from '@wordpress/interactivity';
import { showToast } from '../utils/toast';

/**
 * Toggle a single communication preference via REST and update context.
 *
 * Optimistic: flips immediately, reverts on failure.
 *
 * @param {Object}  ctx        The Interactivity API context.
 * @param {string}  restKey    REST parameter key (e.g. 'email_receipts').
 * @param {string}  contextKey Context key (e.g. 'emailReceipts').
 * @param {boolean} newValue   The new toggle value.
 */
async function togglePreference( ctx, restKey, contextKey, newValue ) {
  const previous = ctx.profile.preferences[ contextKey ];
  ctx.profile.preferences[ contextKey ] = newValue;
  ctx.profile.prefSaving = restKey;

  try {
    const response = await fetch(
      `${ ctx.restUrl }donor-dashboard/preferences`,
      {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': ctx.nonce,
        },
        body: JSON.stringify( { [ restKey ]: newValue } ),
      }
    );

    if ( ! response.ok ) {
      ctx.profile.preferences[ contextKey ] = previous;
      showToast( ctx, 'Failed to save preference. Please try again.', 'error' );
    } else {
      showToast( ctx, 'Preference saved' );
    }
  } catch {
    ctx.profile.preferences[ contextKey ] = previous;
    showToast( ctx, 'Something went wrong. Please try again.', 'error' );
  }

  ctx.profile.prefSaving = '';
}

/**
 * Capitalise the first letter of a card brand string.
 *
 * @param {string} brand Raw brand (e.g. "visa", "mastercard").
 * @return {string} Display name (e.g. "Visa", "Mastercard").
 */
function brandDisplayName( brand ) {
  if ( ! brand ) {
    return 'Card';
  }
  return brand.charAt( 0 ).toUpperCase() + brand.slice( 1 );
}

export const profileState = {
  get profileSaveLabel() {
    const ctx = getContext();
    if ( ctx.profile?.saved ) {
      return 'Saved';
    }
    if ( ctx.profile?.saving ) {
      return 'Saving\u2026';
    }
    return 'Save Changes';
  },
  get profileSaveDisabled() {
    const ctx = getContext();
    return (
      ctx.profile?.saving ||
      ! ctx.profile?.firstName?.trim() ||
      ! ctx.profile?.lastName?.trim()
    );
  },

  // ── Inline validation ──
  get firstNameEmpty() {
    const ctx = getContext();
    return ctx.profile?.validated && ! ctx.profile?.firstName?.trim();
  },
  get lastNameEmpty() {
    const ctx = getContext();
    return ctx.profile?.validated && ! ctx.profile?.lastName?.trim();
  },

  // ── Email change ──
  get emailChangeActive() {
    return getContext().profile?.emailChangeEditing === true;
  },
  get emailSendDisabled() {
    const ctx = getContext();
    const newEmail = ctx.profile?.newEmail?.trim();
    return (
      ! newEmail ||
      ctx.profile?.emailChangeSending ||
      newEmail === ctx.profile?.email
    );
  },
  get emailSendLabel() {
    return getContext().profile?.emailChangeSending
      ? 'Sending\u2026'
      : 'Send Verification';
  },

  // ── Payment method display ──
  get paymentBrandLabel() {
    const pm = getContext().profile?.paymentMethod;
    return pm ? brandDisplayName( pm.brand ) : '';
  },
  get paymentCardDisplay() {
    const pm = getContext().profile?.paymentMethod;
    if ( ! pm ) {
      return '';
    }
    return `${ brandDisplayName( pm.brand ) } ending in ${ pm.last4 }`;
  },
  get paymentExpiry() {
    const pm = getContext().profile?.paymentMethod;
    if ( ! pm?.expMonth || ! pm?.expYear ) {
      return '';
    }
    const month = String( pm.expMonth ).padStart( 2, '0' );
    return `Expires ${ month }/${ pm.expYear }`;
  },
};

export const profileActions = {
  // ── Field updates ──
  updateProfileFirstName( event ) {
    getContext().profile.firstName = event.target.value;
  },
  updateProfileLastName( event ) {
    getContext().profile.lastName = event.target.value;
  },
  updateProfilePhone( event ) {
    getContext().profile.phone = event.target.value;
  },
  updateProfileAddress1( event ) {
    getContext().profile.address1 = event.target.value;
  },
  updateProfileCity( event ) {
    getContext().profile.city = event.target.value;
  },
  updateProfileState( event ) {
    getContext().profile.state = event.target.value;
  },
  updateProfileZip( event ) {
    getContext().profile.zip = event.target.value;
  },

  // ── Email change ──
  startEmailChange() {
    const ctx = getContext();
    ctx.profile.emailChangeEditing = true;
    ctx.profile.newEmail = '';
    ctx.profile.emailChangeError = '';
  },
  cancelEmailEdit() {
    const ctx = getContext();
    ctx.profile.emailChangeEditing = false;
    ctx.profile.newEmail = '';
    ctx.profile.emailChangeError = '';
  },
  updateNewEmail( event ) {
    getContext().profile.newEmail = event.target.value;
  },
  *sendEmailVerification() {
    const ctx = getContext();
    ctx.profile.emailChangeSending = true;
    ctx.profile.emailChangeError = '';

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/email-change/request`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
          body: JSON.stringify( {
            new_email: ctx.profile.newEmail.trim(),
          } ),
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        ctx.profile.emailChangeError =
          data.message || 'Failed to send verification email.';
        ctx.profile.emailChangeSending = false;
        return;
      }

      const data = yield response.json();
      ctx.profile.pendingEmail = data.pending_email;
      ctx.profile.emailChangeEditing = false;
      ctx.profile.newEmail = '';
      ctx.profile.emailChangeSending = false;
      showToast( ctx, 'Verification email sent. Check your inbox.' );
    } catch {
      ctx.profile.emailChangeError = 'Something went wrong. Please try again.';
      ctx.profile.emailChangeSending = false;
    }
  },
  *cancelEmailChange() {
    const ctx = getContext();

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/email-change/cancel`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
        }
      );

      if ( response.ok ) {
        ctx.profile.pendingEmail = '';
        showToast( ctx, 'Email change cancelled.' );
      }
    } catch {
      showToast( ctx, 'Failed to cancel. Please try again.', 'error' );
    }
  },

  // ── Save ──
  *saveProfile() {
    const ctx = getContext();
    ctx.profile.validated = true;
    ctx.profile.error = '';

    if ( ! ctx.profile.firstName?.trim() || ! ctx.profile.lastName?.trim() ) {
      return;
    }

    ctx.profile.saving = true;
    ctx.profile.saved = false;

    try {
      const response = yield fetch( `${ ctx.restUrl }donor-dashboard/profile`, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': ctx.nonce,
        },
        body: JSON.stringify( {
          first_name: ctx.profile.firstName,
          last_name: ctx.profile.lastName,
          phone: ctx.profile.phone,
          address_1: ctx.profile.address1,
          city: ctx.profile.city,
          state: ctx.profile.state,
          zip: ctx.profile.zip,
        } ),
      } );

      if ( ! response.ok ) {
        const data = yield response.json();
        ctx.profile.error = data.message || 'Failed to save. Please try again.';
        ctx.profile.saving = false;
        return;
      }

      // Update sidebar donor display.
      ctx.donor.firstName = ctx.profile.firstName;
      ctx.donor.lastName = ctx.profile.lastName;
      ctx.donor.email = ctx.profile.email;
      const first = ctx.profile.firstName?.charAt( 0 ) || '';
      const last = ctx.profile.lastName?.charAt( 0 ) || '';
      ctx.donor.initials = ( first + last ).toUpperCase() || '?';

      ctx.profile.saving = false;
      ctx.profile.saved = true;

      showToast( ctx, 'Profile updated' );

      // Reset "Saved" label after 2 seconds.
      setTimeout( () => {
        ctx.profile.saved = false;
      }, 2000 );
    } catch {
      ctx.profile.error = 'Something went wrong. Please try again.';
      ctx.profile.saving = false;
    }
  },

  // ── Communication preference toggles ──
  // These are intentionally NOT generators — the render must flush immediately
  // for the toggle animation. The async fetch runs in the background.
  toggleEmailReceipts() {
    const ctx = getContext();
    togglePreference(
      ctx,
      'email_receipts',
      'emailReceipts',
      ! ctx.profile.preferences.emailReceipts
    );
  },
  toggleEmailCampaignUpdates() {
    const ctx = getContext();
    togglePreference(
      ctx,
      'email_campaign_updates',
      'emailCampaignUpdates',
      ! ctx.profile.preferences.emailCampaignUpdates
    );
  },
  toggleEmailAnnualReminder() {
    const ctx = getContext();
    togglePreference(
      ctx,
      'email_annual_reminder',
      'emailAnnualReminder',
      ! ctx.profile.preferences.emailAnnualReminder
    );
  },

  // ── Delete account ──
  *deleteAccount() {
    // eslint-disable-next-line no-alert
    const confirmed = window.confirm(
      'Are you sure you want to delete your account? You will lose login access, but your donation history will be preserved.'
    );

    if ( ! confirmed ) {
      return;
    }

    const ctx = getContext();
    ctx.profile.deleteLoading = true;
    ctx.profile.deleteError = '';

    try {
      const response = yield fetch( `${ ctx.restUrl }donor-dashboard/account`, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': ctx.nonce,
        },
      } );

      if ( ! response.ok ) {
        const data = yield response.json();
        ctx.profile.deleteError =
          data.message || 'Failed to delete account. Please try again.';
        ctx.profile.deleteLoading = false;
        return;
      }

      // Account deleted — redirect to site home.
      window.location.href = ctx.siteHomeUrl || '/';
    } catch {
      ctx.profile.deleteError = 'Something went wrong. Please try again.';
      ctx.profile.deleteLoading = false;
    }
  },
};
