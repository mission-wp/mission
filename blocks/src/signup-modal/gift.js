/**
 * Fundraiser sign-up modal — first-gift ("kickoff") step.
 *
 * Extends the p2p-signup store (same namespace; store() merges definitions)
 * with step 3's sub-views: the nudge amount picker, the Stripe payment view,
 * and the post-gift thank-you. The server pipeline is the standard donation
 * one (donations/create-payment-intent + donations/confirm, webhook
 * reconciliation, receipt emails), so a kickoff gift behaves exactly like a
 * donation made on the fundraiser's page.
 *
 * The Stripe mount/watch/submit code below is deliberately duplicated from
 * blocks/src/donation-form/view.js (mountPaymentElement, watchAmounts,
 * submit), minus the recurring/address/tribute/custom-field branches the
 * wizard never needs — keep fixes to that flow in sync here.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';
import { formatAmount } from '@shared/currency';
import { majorToMinor, minorToMajor } from '@shared/currencies';
import {
  calculateFee,
  calculateTip,
  defaultFixedFee,
  PLATFORM_FEE_RATE,
} from '@shared/fees';
import { i18n, post, shareUrl } from './view';

// Deadlines keep the submit spinner from spinning forever if a promise never
// settles. confirmPayment gets a soft "taking long" notice instead of a hard
// deadline: a 3D Secure challenge can legitimately sit open for minutes.
const PRE_CHARGE_DEADLINE_MS = 30000;
const CONFIRM_SLOW_NOTICE_MS = 30000;
const RECORD_DONATION_DEADLINE_MS = 15000;

/**
 * Race a promise against a deadline that rejects with the given message.
 *
 * @param {Promise} promise The promise to guard.
 * @param {number}  ms      Deadline in milliseconds.
 * @param {string}  message Error message shown to the donor on timeout.
 * @return {Promise} The guarded promise.
 */
function withDeadline( promise, ms, message ) {
  let timer;
  return Promise.race( [
    Promise.resolve( promise ).finally( () => clearTimeout( timer ) ),
    new Promise( ( _resolve, reject ) => {
      timer = setTimeout( () => reject( new Error( message ) ), ms );
    } ),
  ] );
}

// The modal is a page singleton, so unlike the donation form's per-form
// WeakMap this is a single mutable holder. Deliberately outside the reactive
// state: proxying Stripe's internals breaks them.
const giftStripe = { stripe: null, elements: null, appearance: null };

let paymentConfigPromise = null;

/**
 * Prefetch the connected Stripe account so the payment view can mount the
 * Payment Element without a loading pause (see CLAUDE.md: prefetch over
 * loading spinners). Kicked off as soon as the fundraiser is registered.
 *
 * @param {Object} ctx Shell context (restUrl).
 * @return {Promise<string>} Resolves with the connected account ID, or ''.
 */
export function prefetchGiftPaymentConfig( ctx ) {
  paymentConfigPromise = fetch( `${ ctx.restUrl }donations/payment-config` )
    .then( ( response ) => ( response.ok ? response.json() : null ) )
    .then( ( data ) => ( data && data.connected_account_id ) || '' )
    .catch( () => '' );
  return paymentConfigPromise;
}

/**
 * Drop the Stripe objects and empty the payment element container, so the
 * next entry into the payment view mounts fresh (a rebind can change the
 * campaign's currency or Stripe account).
 */
export function teardownGiftStripe() {
  document.querySelector( '.mission-su__payment-element' )?.replaceChildren();
  giftStripe.stripe = null;
  giftStripe.elements = null;
  giftStripe.appearance = null;
  paymentConfigPromise = null;
}

/**
 * Reset the first-gift step to the nudge view with default selections.
 *
 * Called on every entry into step 3 (and on campaign rebind), so re-entering
 * the wizard always starts from the nudge.
 */
export function resetGiftState() {
  state.step3View = 'nudge';
  state.giftAmount =
    ( state.kickoffAmounts && state.kickoffAmounts[ 0 ] ) || 2500;
  state.isCustomGift = false;
  state.customGiftValue = '';
  state.giftPickerOpen = false;
  state.giftFeeCovered = true;
  state.showGiftFeeDetails = false;
  state.giftTipPercent = 15;
  state.giftTipMenuOpen = false;
  state.isCustomGiftTip = false;
  state.customGiftTipAmount = 0;
  state.isSubmittingGift = false;
  state.giftTakingLong = false;
  state.giftError = '';
  teardownGiftStripe();
}

function currency() {
  return state.currency || 'USD';
}

/**
 * The selected gift amount in minor units (preset or custom).
 *
 * @return {number} Amount in minor units.
 */
function giftEffectiveAmount() {
  if ( state.isCustomGift && state.customGiftValue ) {
    return majorToMinor( parseFloat( state.customGiftValue ), currency() ) || 0;
  }
  return state.giftAmount || 0;
}

/**
 * The processing fee for an amount, regardless of whether it is covered
 * (the fee line shows the struck-through value when uncovered).
 *
 * @param {number} amount Donation amount in minor units.
 * @return {number} Fee in minor units.
 */
function giftFeeValue( amount ) {
  const rate = ( state.stripeFeePercent ?? 2.9 ) / 100;
  const fixed = state.stripeFeeFixed ?? defaultFixedFee( currency() );
  // When tips are disabled the flat platform fee is recovered too, matching
  // the donation form's getPlatformRate().
  const platformRate = state.tipEnabled ? 0 : PLATFORM_FEE_RATE;
  return calculateFee( amount, rate, fixed, platformRate, currency() );
}

/**
 * The fee actually charged: zero when fee recovery is off or unchecked.
 *
 * @param {number} amount Donation amount in minor units.
 * @return {number} Fee in minor units.
 */
function giftFeeAmount( amount ) {
  if ( ! state.feeRecovery || ! state.giftFeeCovered ) {
    return 0;
  }
  return giftFeeValue( amount );
}

/**
 * The tip in minor units (custom or percentage of the gift).
 *
 * @param {number} amount Donation amount in minor units.
 * @return {number} Tip in minor units.
 */
function giftTipAmount( amount ) {
  if ( ! state.tipEnabled ) {
    return 0;
  }
  if ( state.isCustomGiftTip ) {
    return Math.max( 0, state.customGiftTipAmount || 0 );
  }
  return calculateTip( amount, state.giftTipPercent, currency() );
}

/**
 * The full charge total: amount + covered fee + tip.
 *
 * @return {number} Total in minor units.
 */
function giftTotal() {
  const amount = giftEffectiveAmount();
  return amount + giftFeeAmount( amount ) + giftTipAmount( amount );
}

const { state } = store( 'mission-donation-platform/p2p-signup', {
  state: {
    // Server-seeded keys (kickoffEnabled, kickoffAmounts, kickoffMessage,
    // currency, feeRecovery, feeMode, tipEnabled, campaignPostId,
    // stripeAccountId, stripePublishableKey, testMode, stripeFeePercent,
    // stripeFeeFixed, stripeAppearance, locale, donorFirstName,
    // donorLastName) are NOT declared here — see the note in view.js.
    step3View: 'nudge',
    fundraiserId: 0,
    giftAmount: 2500,
    isCustomGift: false,
    customGiftValue: '',
    giftPickerOpen: false,
    giftFeeCovered: true,
    showGiftFeeDetails: false,
    giftTipPercent: 15,
    giftTipMenuOpen: false,
    isCustomGiftTip: false,
    customGiftTipAmount: 0,
    isSubmittingGift: false,
    giftTakingLong: false,
    giftError: '',

    get isNudgeView() {
      return (
        state.currentStep === 3 &&
        ! state.isPending &&
        !! state.kickoffEnabled &&
        state.step3View === 'nudge'
      );
    },
    get isGiftPaymentView() {
      return (
        state.currentStep === 3 &&
        ! state.isPending &&
        !! state.kickoffEnabled &&
        state.step3View === 'payment'
      );
    },
    get isGiftSuccessView() {
      return (
        state.currentStep === 3 &&
        ! state.isPending &&
        !! state.kickoffEnabled &&
        state.step3View === 'success'
      );
    },
    get isGiftFeeOptional() {
      return !! state.feeRecovery && state.feeMode !== 'required';
    },
    get kickoffHeadlineText() {
      return i18n( 'kickoffHeadline', 'Give %s to kick off your page' ).replace(
        '%s',
        formatAmount( giftEffectiveAmount(), currency(), {
          stripZeroCents: true,
        } )
      );
    },
    get giftAmountDisplay() {
      return formatAmount( giftEffectiveAmount(), currency() );
    },
    get giftFeeDisplay() {
      return formatAmount( giftFeeValue( giftEffectiveAmount() ), currency() );
    },
    get giftSubmitLabel() {
      return i18n( 'donateAndLaunch', 'Donate %s & launch' ).replace(
        '%s',
        formatAmount( giftTotal(), currency() )
      );
    },
    get giftSuccessText() {
      return i18n(
        'giftSuccessMessage',
        'Your %s gift is in. Your page is off to a great start, so keep the momentum going by sharing it:'
      ).replace(
        '%s',
        formatAmount( giftEffectiveAmount(), currency(), {
          stripZeroCents: true,
        } )
      );
    },
    get giftTipTriggerLabel() {
      if ( state.isCustomGiftTip ) {
        const amount = giftEffectiveAmount();
        const tip = giftTipAmount( amount );
        if ( amount > 0 && tip > 0 ) {
          const percent = ( tip / amount ) * 100;
          return `${
            Number.isInteger( percent ) ? percent : percent.toFixed( 1 )
          }%`;
        }
        return '0%';
      }
      return `${ state.giftTipPercent }%`;
    },
  },

  actions: {
    toggleGiftPicker() {
      state.giftPickerOpen = ! state.giftPickerOpen;
    },
    selectGiftPreset() {
      state.giftAmount = getContext().preset;
      state.isCustomGift = false;
      state.giftPickerOpen = false;
    },
    chooseOtherGift() {
      if ( ! state.isCustomGift ) {
        state.isCustomGift = true;
        state.customGiftValue = String(
          minorToMajor( state.giftAmount || 0, currency() ) || ''
        );
      }
    },
    updateCustomGift( event ) {
      state.customGiftValue = event.target.value;
    },
    blurCustomGift() {
      // Leaving the field empty (or nonsense) reverts to the preset button.
      if (
        ! state.customGiftValue ||
        ! ( parseFloat( state.customGiftValue ) > 0 )
      ) {
        state.isCustomGift = false;
        state.customGiftValue = '';
      }
    },
    backToNudge() {
      state.step3View = 'nudge';
      state.giftError = '';
    },
    toggleGiftFeeDetails() {
      state.showGiftFeeDetails = ! state.showGiftFeeDetails;
    },
    toggleGiftFeeCovered( event ) {
      state.giftFeeCovered = event.target.checked;
    },
    toggleGiftTipMenu() {
      state.giftTipMenuOpen = ! state.giftTipMenuOpen;
    },
    closeGiftTipMenu( event ) {
      if ( ! event.target.closest( '.mission-su__tip-trigger-wrap' ) ) {
        state.giftTipMenuOpen = false;
      }
    },
    selectGiftTipPercent() {
      state.giftTipPercent = getContext().tipPercent;
      state.isCustomGiftTip = false;
      state.giftTipMenuOpen = false;
    },
    selectGiftCustomTip() {
      if ( ! state.isCustomGiftTip ) {
        state.isCustomGiftTip = true;
        state.customGiftTipAmount = calculateTip(
          giftEffectiveAmount(),
          15,
          currency()
        );
      }
      state.giftTipMenuOpen = false;
    },
    updateGiftCustomTip( event ) {
      const value = parseFloat( event.target.value );
      state.customGiftTipAmount = isNaN( value )
        ? 0
        : Math.max( 0, majorToMinor( value, currency() ) || 0 );
    },
    giftTipUp() {
      state.customGiftTipAmount =
        ( state.customGiftTipAmount || 0 ) + majorToMinor( 1, currency() );
    },
    giftTipDown() {
      state.customGiftTipAmount = Math.max(
        0,
        ( state.customGiftTipAmount || 0 ) - majorToMinor( 1, currency() )
      );
    },
    shareEmail() {
      const ctx = getContext();
      const template =
        ( ctx.shareTemplates && ctx.shareTemplates.email ) || 'mailto:?body=%s';
      // mailto must navigate, not open a popup (that leaves a blank window).
      window.location.href = template.replace(
        '%s',
        encodeURIComponent( shareUrl() )
      );
    },

    *showGiftPayment() {
      // A cleared/invalid custom amount falls back to the selected preset so
      // the payment view never opens with an uncharg�eable zero total.
      if ( giftEffectiveAmount() <= 0 ) {
        state.isCustomGift = false;
        state.customGiftValue = '';
      }

      state.giftError = '';
      state.step3View = 'payment';

      if ( giftStripe.elements ) {
        const total = giftTotal();
        if ( total > 0 ) {
          giftStripe.elements.update( { amount: total } );
        }
        return;
      }

      if ( ! state.stripePublishableKey || ! window.Stripe ) {
        state.giftError = i18n(
          'paymentUnavailable',
          'Payment system unavailable. Please refresh and try again.'
        );
        return;
      }

      const ctx = getContext();
      const initError = i18n(
        'paymentInitFailed',
        'Payment processing is not available right now. Please try again later.'
      );

      let accountId = '';
      try {
        accountId = yield paymentConfigPromise ||
          prefetchGiftPaymentConfig( ctx );
      } catch ( e ) {
        accountId = '';
      }
      if ( ! accountId ) {
        state.giftError = initError;
        return;
      }

      const container = document.querySelector(
        '.mission-su__payment-element'
      );
      if ( ! container ) {
        return;
      }

      giftStripe.stripe = window.Stripe( state.stripePublishableKey, {
        stripeAccount: accountId,
        locale: state.locale || 'auto',
      } );

      const root = container.closest( '.mission-su' );
      const primaryColor = root
        ? window
            .getComputedStyle( root )
            .getPropertyValue( '--mission-primary' )
            .trim()
        : '';
      const customAppearance = state.stripeAppearance || {};

      giftStripe.appearance = {
        theme: customAppearance.theme || 'stripe',
        variables: {
          colorPrimary: primaryColor || '#2FA36B',
          colorDanger: '#dc2626',
          fontFamily:
            '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
          borderRadius: '10px',
          ...( customAppearance.variables || {} ),
        },
        rules: {
          '.Input--invalid': {
            borderColor: '#dc2626',
            boxShadow: '0 0 0 3px rgba(220, 38, 38, 0.12)',
          },
          '.Error': {
            fontSize: '0.8125rem',
          },
          ...( customAppearance.rules || {} ),
        },
      };

      giftStripe.elements = giftStripe.stripe.elements( {
        mode: 'payment',
        amount: giftTotal(),
        currency: currency().toLowerCase(),
        paymentMethodTypes: [ 'card' ],
        appearance: giftStripe.appearance,
      } );

      const paymentElement = giftStripe.elements.create( 'payment', {
        layout: 'tabs',
        fields: {
          billingDetails: {
            // Name and email come from the step-1 account and are passed at
            // confirm time, so the element never asks for them.
            name: 'never',
            email: 'never',
            address: 'auto',
          },
        },
        wallets: {
          link: 'never',
        },
      } );

      paymentElement.mount( container );

      paymentElement.on( 'change', ( event ) => {
        state.giftError = event.error ? event.error.message : '';
      } );
    },

    *submitGift() {
      // eslint-disable-next-line @wordpress/no-unused-vars-before-return -- getContext() must be called synchronously at action start.
      const ctx = getContext();

      if ( state.isSubmittingGift ) {
        return;
      }

      state.isSubmittingGift = true;
      state.giftError = '';

      try {
        if ( ! giftStripe.elements ) {
          state.giftError = i18n(
            'elementNotLoaded',
            'Payment element not loaded. Please refresh the page.'
          );
          return;
        }

        const timeoutMessage = i18n(
          'paymentTimeout',
          'The payment could not be started. Please check your connection and try again.'
        );

        const { error: submitError } = yield withDeadline(
          giftStripe.elements.submit(),
          PRE_CHARGE_DEADLINE_MS,
          timeoutMessage
        );
        if ( submitError ) {
          // The Payment Element renders its own inline validation message.
          return;
        }

        const donationAmount = giftEffectiveAmount();
        const feeAmount = giftFeeAmount( donationAmount );
        const tipAmount = giftTipAmount( donationAmount );

        const intentResponse = yield withDeadline(
          post( ctx, 'donations/create-payment-intent', {
            donation_amount: donationAmount + feeAmount,
            tip_amount: tipAmount,
            fee_amount: feeAmount,
            fee_mode: state.tipEnabled ? 'tip' : 'flat',
            donor_email: state.donorEmail || '',
            donor_first_name: state.donorFirstName || '',
            donor_last_name: state.donorLastName || '',
            frequency: 'one_time',
            campaign_id: state.campaignId || 0,
            fundraiser_id: state.fundraiserId || 0,
            team_id: 0,
            source_post_id: state.campaignPostId || 0,
            form_id: 'p2p-kickoff',
            stripe_account_id: state.stripeAccountId || '',
            is_anonymous: false,
          } ),
          PRE_CHARGE_DEADLINE_MS,
          timeoutMessage
        );

        const intentData = yield withDeadline(
          intentResponse.json(),
          PRE_CHARGE_DEADLINE_MS,
          timeoutMessage
        );

        if ( ! intentResponse.ok || ! intentData.client_secret ) {
          state.giftError =
            intentData.message ||
            i18n(
              'paymentCreateFailed',
              'Failed to create payment. Please try again.'
            );
          return;
        }

        // No hard deadline on confirm: a 3D Secure challenge can keep this
        // pending as long as the donor takes. Surface a notice instead.
        const slowNoticeTimer = setTimeout( () => {
          state.giftTakingLong = true;
        }, CONFIRM_SLOW_NOTICE_MS );
        let confirmResult;
        try {
          confirmResult = yield giftStripe.stripe.confirmPayment( {
            elements: giftStripe.elements,
            clientSecret: intentData.client_secret,
            confirmParams: {
              payment_method_data: {
                billing_details: {
                  name: `${ state.donorFirstName || '' } ${
                    state.donorLastName || ''
                  }`.trim(),
                  email: state.donorEmail || '',
                },
              },
              return_url: window.location.href,
            },
            redirect: 'if_required',
          } );
        } finally {
          clearTimeout( slowNoticeTimer );
          state.giftTakingLong = false;
        }

        if ( confirmResult.error ) {
          state.giftError = confirmResult.error.message;
          return;
        }

        // The donor is charged at this point, so a slow or failed record call
        // must never surface as an error or block the thank-you view. The
        // payment_intent.succeeded webhook reconciles the transaction.
        try {
          const confirmResponse = yield withDeadline(
            post( ctx, 'donations/confirm', {
              transaction_id: intentData.transaction_id,
              payment_intent_id:
                intentData.client_secret.split( '_secret_' )[ 0 ],
            } ),
            RECORD_DONATION_DEADLINE_MS,
            'confirm request timed out'
          );
          if ( ! confirmResponse.ok && confirmResponse.status !== 202 ) {
            // eslint-disable-next-line no-console
            console.error(
              'Mission: Unexpected confirm response',
              confirmResponse.status
            );
          }
        } catch ( confirmErr ) {
          // eslint-disable-next-line no-console
          console.error( 'Mission: Failed to record confirmation', confirmErr );
        }

        state.step3View = 'success';
      } catch ( err ) {
        state.giftError =
          err.message ||
          i18n( 'genericError', 'Something went wrong. Please try again.' );
      } finally {
        state.isSubmittingGift = false;
      }
    },
  },

  callbacks: {
    isGiftPresetActive() {
      return ! state.isCustomGift && getContext().preset === state.giftAmount;
    },
    giftPresetLabel() {
      return formatAmount( getContext().preset, currency(), {
        stripZeroCents: true,
      } );
    },
    customGiftTipDisplay() {
      return state.customGiftTipAmount
        ? minorToMajor( state.customGiftTipAmount, currency() )
        : '';
    },
    focusCustomGiftInput() {
      if ( state.isCustomGift ) {
        const { ref } = getElement();
        if ( ref && ref.tagName === 'INPUT' ) {
          ref.focus();
        }
      }
    },
    watchGiftAmounts() {
      // Read reactive properties before the early return so the Interactivity
      // API tracks them as dependencies of this watcher.
      void state.giftAmount;
      void state.isCustomGift;
      void state.customGiftValue;
      void state.giftFeeCovered;
      void state.giftTipPercent;
      void state.isCustomGiftTip;
      void state.customGiftTipAmount;

      if ( ! giftStripe.elements ) {
        return;
      }
      const total = giftTotal();
      if ( total > 0 ) {
        giftStripe.elements.update( { amount: total } );
      }
    },
  },
} );
