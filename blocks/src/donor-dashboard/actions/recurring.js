/**
 * Donor Dashboard — Recurring Donations state and actions.
 *
 * Handles subscription cancel, pause, resume, change amount,
 * and update payment method actions.
 */
import { getContext, getElement } from '@wordpress/interactivity';
import { minorToMajor, majorToMinor } from '@shared/currencies';
import { formatAmount } from '@shared/currency';
import { calculateFee, calculateTip, PLATFORM_FEE_RATE } from '@shared/fees';
import { showToast } from '../utils/toast';

/**
 * Module-scope Stripe references for the payment method update flow.
 * Only one update modal can be open at a time.
 */
let stripeInstance = null;
let elementsInstance = null;
let paymentElement = null;
let clientSecret = null;

/**
 * Focus trap state — shared across modals (only one open at a time).
 */
let focusTrapCleanup = null;
let previouslyFocused = null;

/**
 * Focusable element selector.
 */
const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Trap focus within a container element.
 *
 * Returns a cleanup function that removes the event listener.
 *
 * @param {HTMLElement} container The element to trap focus within.
 * @return {Function} Cleanup function.
 */
function trapFocus( container ) {
  function handleKeydown( event ) {
    if ( event.key !== 'Tab' ) {
      return;
    }

    const focusable = [ ...container.querySelectorAll( FOCUSABLE ) ].filter(
      ( el ) => el.offsetParent !== null
    );

    if ( focusable.length === 0 ) {
      event.preventDefault();
      return;
    }

    const first = focusable[ 0 ];
    const last = focusable[ focusable.length - 1 ];

    const active = container.ownerDocument.activeElement;

    if ( event.shiftKey && active === first ) {
      event.preventDefault();
      last.focus();
    } else if ( ! event.shiftKey && active === last ) {
      event.preventDefault();
      first.focus();
    }
  }

  container.addEventListener( 'keydown', handleKeydown );

  return () => container.removeEventListener( 'keydown', handleKeydown );
}

/**
 * Set up focus trap on a modal within the current subscription card.
 *
 * @param {HTMLElement} subscriptionEl The subscription card element.
 * @param {string}      modalSelector  Selector for the modal within the card.
 */
function setupModalFocusTrap( subscriptionEl, modalSelector ) {
  previouslyFocused = subscriptionEl.ownerDocument.activeElement;

  // eslint-disable-next-line no-undef -- Browser API.
  requestAnimationFrame( () => {
    const modal = subscriptionEl.querySelector( modalSelector );
    if ( ! modal ) {
      return;
    }

    const content = modal.querySelector( '.mission-dd-modal-content' );
    if ( ! content ) {
      return;
    }

    focusTrapCleanup = trapFocus( content );

    // Focus the first focusable element in the modal.
    const firstFocusable = content.querySelector( FOCUSABLE );
    if ( firstFocusable ) {
      firstFocusable.focus();
    }
  } );
}

/**
 * Clean up focus trap and restore focus.
 */
function cleanupFocusTrap() {
  if ( focusTrapCleanup ) {
    focusTrapCleanup();
    focusTrapCleanup = null;
  }

  if ( previouslyFocused ) {
    previouslyFocused.focus();
    previouslyFocused = null;
  }
}

/**
 * Format the current date into a short display string.
 *
 * @return {string} e.g. "Mar 25, 2026"
 */
function formatDateNow() {
  return new Date().toLocaleDateString( undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  } );
}

/**
 * Format the current date as "Mon YYYY".
 *
 * @return {string} e.g. "Mar 2026"
 */
function formatMonthYear() {
  return new Date().toLocaleDateString( undefined, {
    month: 'short',
    year: 'numeric',
  } );
}

/**
 * Move a subscription from the active list to the cancelled list in context.
 *
 * @param {Object} ctx   The Interactivity API context.
 * @param {number} subId Subscription ID to move.
 */
function moveToCancelled( ctx, subId ) {
  const { recurring } = ctx;
  const index = recurring.activeSubscriptions.findIndex(
    ( s ) => s.id === subId
  );

  if ( index === -1 ) {
    return;
  }

  const sub = recurring.activeSubscriptions[ index ];
  const endMonth = formatMonthYear();
  const period =
    sub.started && endMonth !== sub.started
      ? `${ sub.started.replace( /\d{1,2}, /, '' ) } \u2013 ${ endMonth }`
      : sub.started?.replace( /\d{1,2}, /, '' ) || endMonth;

  recurring.activeSubscriptions.splice( index, 1 );
  recurring.cancelledSubscriptions.unshift( {
    id: sub.id,
    formattedAmount: sub.formattedAmount,
    frequencySuffix: sub.frequencySuffix,
    frequencyLabel: sub.frequencyLabel,
    campaignName: sub.campaignName,
    paymentsMade: sub.paymentsMade,
    totalContributed: sub.totalContributed,
    period,
    cancelled: formatDateNow(),
  } );

  recurring.hasActive = recurring.activeSubscriptions.length > 0;
  recurring.hasCancelled = true;
  recurring.hasAny = true;
}

/**
 * Clean up Stripe Element references.
 */
function cleanupStripe() {
  if ( paymentElement ) {
    paymentElement.unmount();
    paymentElement = null;
  }
  elementsInstance = null;
  stripeInstance = null;
  clientSecret = null;
}

/**
 * Check if any subscription modal is currently open.
 *
 * @param {Object} ctx The Interactivity API context.
 * @return {string|null} 'changeAmount' | 'updatePayment' | null.
 */
export function getOpenModal( ctx ) {
  const subs = ctx.recurring?.activeSubscriptions;
  if ( ! subs ) {
    return null;
  }
  for ( const sub of subs ) {
    if ( sub.changeAmountOpen ) {
      return 'changeAmount';
    }
    if ( sub.updatePaymentOpen ) {
      return 'updatePayment';
    }
  }
  return null;
}

/**
 * Close any open subscription modal and clean up focus.
 *
 * @param {Object} ctx The Interactivity API context.
 */
export function closeAnyModal( ctx ) {
  const subs = ctx.recurring?.activeSubscriptions;
  if ( ! subs ) {
    return;
  }
  for ( const sub of subs ) {
    if ( sub.changeAmountOpen ) {
      sub.changeAmountOpen = false;
      sub.changeError = '';
      sub.changeTipMenuOpen = false;
      sub.changeFeeDetailsOpen = false;
      return;
    }
    if ( sub.updatePaymentOpen ) {
      sub.updatePaymentOpen = false;
      sub.updatePaymentError = '';
      sub.updatePaymentReady = false;
      sub.updatePaymentLoading = false;
      cleanupStripe();
      cleanupFocusTrap();
      return;
    }
  }
}

export const recurringState = {
  get subIsActive() {
    return getContext().sub?.status === 'active';
  },
  get subIsPaused() {
    return getContext().sub?.status === 'paused';
  },
  get subChangeAmountOpen() {
    return getContext().sub?.changeAmountOpen === true;
  },
  get changeFormattedFeeAmount() {
    const sub = getContext().sub;
    if ( ! sub ) {
      return '';
    }
    const base = majorToMinor(
      parseFloat( sub.changeAmountInput ) || 0,
      sub.currency
    );
    const rate = ( sub.stripeFeePercent ?? 2.9 ) / 100;
    const fixed = sub.stripeFeeFixed ?? 30;
    const platform = sub.feeModeFlat ? PLATFORM_FEE_RATE : 0;
    return formatAmount(
      calculateFee( base, rate, fixed, platform ),
      sub.currency
    );
  },
  get changeTipTriggerLabel() {
    const sub = getContext().sub;
    if ( ! sub ) {
      return '0%';
    }
    if ( sub.changeIsCustomTip ) {
      const base = majorToMinor(
        parseFloat( sub.changeAmountInput ) || 0,
        sub.currency
      );
      if ( base <= 0 || sub.changeCustomTipAmount <= 0 ) {
        return '0%';
      }
      const pct = ( sub.changeCustomTipAmount / base ) * 100;
      return pct === Math.floor( pct ) ? `${ pct }%` : `${ pct.toFixed( 1 ) }%`;
    }
    return sub.changeSelectedTipPercent
      ? `${ sub.changeSelectedTipPercent }%`
      : '0%';
  },
  get isChangeTipOptionActive() {
    const ctx = getContext();
    const sub = ctx.sub;
    return (
      ! sub?.changeIsCustomTip &&
      sub?.changeSelectedTipPercent === ctx.changeTipPercent
    );
  },
  get changeCustomTipDisplayValue() {
    const sub = getContext().sub;
    if ( ! sub ) {
      return '0.00';
    }
    return minorToMajor( sub.changeCustomTipAmount, sub.currency ).toFixed( 2 );
  },
  get changeUpdateButtonLabel() {
    const sub = getContext().sub;
    if ( ! sub ) {
      return 'Update';
    }
    if ( sub.changeLoading ) {
      return 'Updating\u2026';
    }
    const base = majorToMinor(
      parseFloat( sub.changeAmountInput ) || 0,
      sub.currency
    );
    const rate = ( sub.stripeFeePercent ?? 2.9 ) / 100;
    const fixed = sub.stripeFeeFixed ?? 30;
    const platform = sub.feeModeFlat ? PLATFORM_FEE_RATE : 0;
    let total = base;
    if ( sub.changeFeeRecoveryChecked ) {
      total += calculateFee( base, rate, fixed, platform );
    }
    if ( ! sub.feeModeFlat ) {
      if ( sub.changeIsCustomTip ) {
        total += Math.max( 0, sub.changeCustomTipAmount || 0 );
      } else {
        total += calculateTip( base, sub.changeSelectedTipPercent );
      }
    }
    return `Update to ${ formatAmount( total, sub.currency ) } ${
      sub.frequencySuffix
    }`;
  },
  get changeAmountLabel() {
    const sub = getContext().sub;
    if ( ! sub ) {
      return 'New amount';
    }
    return `New ${ sub.frequencyLabel?.toLowerCase() || '' } amount`;
  },
  get subPaymentElementId() {
    const sub = getContext().sub;
    return sub ? `mission-dd-payment-element-${ sub.id }` : '';
  },
  get subUpdatePaymentDisabled() {
    const sub = getContext().sub;
    return sub?.updatePaymentLoading || ! sub?.updatePaymentReady;
  },
  get subUpdatePaymentLabel() {
    const sub = getContext().sub;
    if ( sub?.updatePaymentLoading ) {
      return 'Updating\u2026';
    }
    return 'Update Payment Method';
  },
};

export const recurringActions = {
  *cancelSubscription() {
    // eslint-disable-next-line no-alert
    const confirmed = window.confirm(
      'Are you sure you want to cancel this recurring donation? This cannot be undone.'
    );

    if ( ! confirmed ) {
      return;
    }

    const ctx = getContext();
    const sub = ctx.sub;
    const subId = sub.id;

    sub.cancelLoading = true;
    sub.actionError = '';

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/subscriptions/${ subId }/cancel`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        sub.actionError = data.message || 'Failed to cancel. Please try again.';
        sub.cancelLoading = false;
        return;
      }

      moveToCancelled( ctx, subId );
      showToast( ctx, 'Subscription cancelled' );
    } catch {
      sub.actionError = 'Something went wrong. Please try again.';
      sub.cancelLoading = false;
    }
  },

  *pauseSubscription() {
    const ctx = getContext();
    const sub = ctx.sub;

    sub.pauseLoading = true;
    sub.actionError = '';

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/subscriptions/${ sub.id }/pause`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        sub.actionError = data.message || 'Failed to pause. Please try again.';
        sub.pauseLoading = false;
        return;
      }

      sub.status = 'paused';
      sub.pauseLoading = false;
      showToast( ctx, 'Subscription paused' );
    } catch {
      sub.actionError = 'Something went wrong. Please try again.';
      sub.pauseLoading = false;
    }
  },

  *resumeSubscription() {
    const ctx = getContext();
    const sub = ctx.sub;

    sub.pauseLoading = true;
    sub.actionError = '';

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/subscriptions/${ sub.id }/resume`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        sub.actionError = data.message || 'Failed to resume. Please try again.';
        sub.pauseLoading = false;
        return;
      }

      sub.status = 'active';
      sub.pauseLoading = false;
      showToast( ctx, 'Subscription resumed' );
    } catch {
      sub.actionError = 'Something went wrong. Please try again.';
      sub.pauseLoading = false;
    }
  },

  // ── Change Amount ──

  openChangeAmount() {
    const ctx = getContext();
    closeAnyModal( ctx );
    const sub = ctx.sub;

    sub.changeAmountOpen = true;
    sub.changeAmountInput = String( minorToMajor( sub.amount, sub.currency ) );
    sub.changeError = '';

    // Fee recovery: default to checked if subscription has a non-zero fee_amount.
    sub.changeFeeRecoveryChecked = sub.feeAmount > 0;
    sub.changeFeeDetailsOpen = false;

    // Tip: initialize from current tip amount.
    sub.changeTipMenuOpen = false;
    sub.changeIsCustomTip = false;

    if ( sub.feeModeFlat ) {
      sub.changeSelectedTipPercent = 0;
      sub.changeCustomTipAmount = 0;
    } else {
      // Reverse-engineer current tip percent, snap to standard options.
      const currentTipPercent =
        sub.amount > 0
          ? Math.round( ( sub.tipAmount / sub.amount ) * 100 )
          : 15;
      if ( [ 20, 15, 10 ].includes( currentTipPercent ) ) {
        sub.changeSelectedTipPercent = currentTipPercent;
        sub.changeCustomTipAmount = 0;
      } else {
        sub.changeIsCustomTip = true;
        sub.changeSelectedTipPercent = 0;
        sub.changeCustomTipAmount = sub.tipAmount;
      }
    }
  },

  closeChangeAmount() {
    const sub = getContext().sub;
    sub.changeAmountOpen = false;
    sub.changeError = '';
    sub.changeTipMenuOpen = false;
    sub.changeFeeDetailsOpen = false;
  },

  updateChangeAmountInput( event ) {
    getContext().sub.changeAmountInput = event.target.value;
  },

  // ── Change Amount: Fee Recovery ──

  toggleChangeFeeRecovery() {
    const sub = getContext().sub;
    sub.changeFeeRecoveryChecked = ! sub.changeFeeRecoveryChecked;
    sub.changeFeeDetailsOpen = false;
  },

  toggleChangeFeeDetails() {
    const sub = getContext().sub;
    sub.changeFeeDetailsOpen = ! sub.changeFeeDetailsOpen;
  },

  // ── Change Amount: Tip ──

  toggleChangeTipMenu( event ) {
    event.stopPropagation();
    const sub = getContext().sub;
    if ( ! sub.changeTipMenuOpen ) {
      sub.changeTipMenuOpen = true;

      // Position menu so active option aligns over the trigger value.
      const { ref } = getElement();
      const wrap = ref?.closest( '.mission-dd-ca-tip-trigger-wrap' );
      const menu = wrap?.querySelector( '.mission-dd-ca-tip-menu' );
      const triggerValue = wrap?.querySelector(
        '.mission-dd-ca-tip-trigger-value'
      );
      if ( menu && triggerValue ) {
        const wrapRect = wrap.getBoundingClientRect();
        const valueRect = triggerValue.getBoundingClientRect();
        const valueCenterY =
          valueRect.top + valueRect.height / 2 - wrapRect.top;
        const activeOpt =
          menu.querySelector( '.mission-dd-ca-tip-option.active' ) ||
          menu.querySelector( '.mission-dd-ca-tip-option' );
        const options = Array.from(
          menu.querySelectorAll( '.mission-dd-ca-tip-option' )
        );
        const activeIndex = Math.max( 0, options.indexOf( activeOpt ) );
        const optionHeight = 37;
        const activeCenterY = activeIndex * optionHeight + optionHeight / 2;
        menu.style.top = `${ valueCenterY - activeCenterY }px`;
      }
    } else {
      sub.changeTipMenuOpen = false;
    }
  },

  closeChangeTipMenu( event ) {
    if ( ! event.target.closest( '.mission-dd-ca-tip-trigger-wrap' ) ) {
      getContext().sub.changeTipMenuOpen = false;
    }
  },

  selectChangeTipPercent( event ) {
    event.stopPropagation();
    const ctx = getContext();
    const sub = ctx.sub;
    sub.changeSelectedTipPercent = ctx.changeTipPercent;
    sub.changeIsCustomTip = false;
    sub.changeCustomTipAmount = 0;
    sub.changeTipMenuOpen = false;
  },

  selectChangeCustomTip( event ) {
    event.stopPropagation();
    const sub = getContext().sub;
    sub.changeIsCustomTip = true;
    const amount = majorToMinor(
      parseFloat( sub.changeAmountInput ) || 0,
      sub.currency
    );
    sub.changeCustomTipAmount = calculateTip( amount, 15 );
    sub.changeSelectedTipPercent = 0;
    sub.changeTipMenuOpen = false;
  },

  updateChangeCustomTipAmount( event ) {
    const sub = getContext().sub;
    const value = parseFloat( event.target.value ) || 0;
    sub.changeCustomTipAmount = majorToMinor( value, sub.currency );
  },

  changeTipCustomUp() {
    const sub = getContext().sub;
    sub.changeCustomTipAmount += 100;
  },

  changeTipCustomDown() {
    const sub = getContext().sub;
    sub.changeCustomTipAmount = Math.max( 0, sub.changeCustomTipAmount - 100 );
  },

  // ── Change Amount: Submit ──

  *submitChangeAmount() {
    const ctx = getContext();
    const sub = ctx.sub;

    const baseAmount = parseFloat( sub.changeAmountInput );

    if ( ! baseAmount || baseAmount < 1 ) {
      sub.changeError = `Please enter an amount of at least ${ formatAmount(
        100,
        sub.currency
      ) }.`;
      return;
    }

    const baseAmountMinor = majorToMinor( baseAmount, sub.currency );

    // Calculate fee recovery.
    const feeRate = ( sub.stripeFeePercent ?? 2.9 ) / 100;
    const feeFixed = sub.stripeFeeFixed ?? 30;
    const platformRate = sub.feeModeFlat ? PLATFORM_FEE_RATE : 0;
    const feeAmount = sub.changeFeeRecoveryChecked
      ? calculateFee( baseAmountMinor, feeRate, feeFixed, platformRate )
      : 0;

    // Fee rolls into donation_amount (same as donation form).
    const donationAmount = baseAmountMinor + feeAmount;

    // Calculate tip.
    let tipAmount = 0;
    if ( ! sub.feeModeFlat ) {
      if ( sub.changeIsCustomTip ) {
        tipAmount = Math.max( 0, sub.changeCustomTipAmount || 0 );
      } else {
        tipAmount = calculateTip(
          baseAmountMinor,
          sub.changeSelectedTipPercent
        );
      }
    }

    sub.changeLoading = true;
    sub.changeError = '';

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/subscriptions/${ sub.id }/amount`,
        {
          method: 'PUT',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
          body: JSON.stringify( {
            donation_amount: donationAmount,
            tip_amount: tipAmount,
            fee_amount: feeAmount,
          } ),
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        sub.changeError = data.message || 'Failed to update. Try again.';
        sub.changeLoading = false;
        return;
      }

      // Update local state with new amounts.
      sub.amount = donationAmount;
      sub.tipAmount = tipAmount;
      sub.feeAmount = feeAmount;
      sub.formattedAmount = formatAmount( donationAmount, sub.currency );
      sub.changeAmountOpen = false;
      sub.changeLoading = false;
      showToast( ctx, 'Donation amount updated' );
    } catch {
      sub.changeError = 'Something went wrong. Please try again.';
      sub.changeLoading = false;
    }
  },

  // ── Update Payment Method ──

  *openUpdatePayment() {
    const ctx = getContext();
    // eslint-disable-next-line @wordpress/no-unused-vars-before-return -- getElement() must be called synchronously at action start.
    const { ref } = getElement();
    const sub = ctx.sub;

    sub.updatePaymentOpen = true;
    sub.updatePaymentError = '';
    sub.updatePaymentReady = false;
    sub.updatePaymentLoading = false;

    if ( ! window.Stripe ) {
      sub.updatePaymentError =
        'Payment system unavailable. Please refresh and try again.';
      return;
    }

    try {
      // Create a SetupIntent via the WP REST proxy.
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/subscriptions/${ sub.id }/setup-intent`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        sub.updatePaymentError =
          data.message || 'Failed to initialize. Please try again.';
        return;
      }

      const intentData = yield response.json();

      // Initialize Stripe with the connected account.
      stripeInstance = window.Stripe( ctx.stripePublishableKey, {
        stripeAccount: intentData.connected_account_id,
      } );

      // Store client secret in module scope (not on reactive context).
      clientSecret = intentData.client_secret;

      // Create Elements using the SetupIntent's client secret.
      elementsInstance = stripeInstance.elements( {
        clientSecret,
        appearance: {
          theme: 'stripe',
          variables: {
            colorPrimary:
              window
                .getComputedStyle( document.documentElement )
                .getPropertyValue( '--mission-primary' )
                .trim() || '#2FA36B',
            fontFamily:
              '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
            borderRadius: '6px',
          },
        },
      } );

      // Create and mount the PaymentElement.
      paymentElement = elementsInstance.create( 'payment', {
        layout: 'tabs',
        wallets: { link: 'never' },
      } );

      const container = document.getElementById(
        `mission-dd-payment-element-${ sub.id }`
      );

      if ( container ) {
        paymentElement.mount( container );
      }

      // Track when the card form is complete.
      paymentElement.on( 'change', ( event ) => {
        sub.updatePaymentReady = event.complete;
        if ( event.error ) {
          sub.updatePaymentError = event.error.message;
        } else if ( sub.updatePaymentError && ! sub.updatePaymentLoading ) {
          sub.updatePaymentError = '';
        }
      } );

      // Trap focus in the modal.
      const card = ref.closest( '.mission-dd-subscription' );
      if ( card ) {
        setupModalFocusTrap( card, '.mission-dd-modal:not([hidden])' );
      }
    } catch ( err ) {
      // eslint-disable-next-line no-console -- Debugging aid.
      console.error( '[Mission] openUpdatePayment error:', err );
      sub.updatePaymentError = 'Something went wrong. Please try again.';
    }
  },

  closeUpdatePayment() {
    const sub = getContext().sub;
    sub.updatePaymentOpen = false;
    sub.updatePaymentError = '';
    sub.updatePaymentReady = false;
    sub.updatePaymentLoading = false;
    cleanupStripe();
    cleanupFocusTrap();
  },

  *submitUpdatePayment() {
    const ctx = getContext();
    const sub = ctx.sub;

    sub.updatePaymentLoading = true;
    sub.updatePaymentError = '';

    try {
      // Confirm the SetupIntent with Stripe.
      const { error, setupIntent } = yield stripeInstance.confirmSetup( {
        elements: elementsInstance,
        confirmParams: {
          return_url: window.location.href,
        },
        redirect: 'if_required',
      } );

      if ( error ) {
        sub.updatePaymentError = error.message;
        sub.updatePaymentLoading = false;
        return;
      }

      // Send the payment method ID to the WP REST endpoint.
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/subscriptions/${ sub.id }/payment-method`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
          body: JSON.stringify( {
            payment_method_id: setupIntent.payment_method,
          } ),
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        sub.updatePaymentError =
          data.message || 'Failed to update. Please try again.';
        sub.updatePaymentLoading = false;
        return;
      }

      const pmData = yield response.json();

      // Update the subscription card's payment method display.
      const brand = pmData.brand
        ? pmData.brand.charAt( 0 ).toUpperCase() + pmData.brand.slice( 1 )
        : 'Card';
      sub.paymentMethod = `${ brand } ending in ${ pmData.last4 }`;

      // Update the profile panel if this is the first active subscription.
      if (
        ctx.recurring.activeSubscriptions.length > 0 &&
        ctx.recurring.activeSubscriptions[ 0 ].id === sub.id
      ) {
        ctx.profile.paymentMethod = {
          brand: pmData.brand || 'card',
          last4: pmData.last4,
          expMonth: pmData.exp_month || '',
          expYear: pmData.exp_year || '',
        };
      }

      // Close modal and clean up.
      sub.updatePaymentOpen = false;
      sub.updatePaymentLoading = false;
      cleanupStripe();
      cleanupFocusTrap();
      showToast( ctx, 'Payment method updated' );
    } catch {
      sub.updatePaymentError = 'Something went wrong. Please try again.';
      sub.updatePaymentLoading = false;
    }
  },
};
