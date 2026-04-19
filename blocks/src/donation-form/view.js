/**
 * Donation form frontend — Interactivity API store.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';
import {
  formatCurrency,
  getEffectiveAmount,
  getAmountsForFrequency,
  resetAmountForFrequency,
  getFeeParams,
  calculateFee,
  calculateTip,
  getTipAmount,
  getPaymentStep,
  validateCustomFields,
} from './utils';
import { majorToMinor, minorToMajor } from '@shared/currencies';
import { PLATFORM_FEE_RATE } from '@shared/fees';

/**
 * Get the platform fee rate for fee recovery calculations.
 *
 * Returns the platform rate when tips are disabled (flat fee mode) so
 * fee recovery covers both Stripe and platform fees.
 *
 * @param {Object} ctx Interactivity context.
 * @return {number} Platform rate as decimal (0.03 or 0).
 */
function getPlatformRate( ctx ) {
  return ctx.settings.tipEnabled ? 0 : PLATFORM_FEE_RATE;
}

/**
 * Module-scope references for Stripe instances.
 */
let stripeInstance = null;
let elementsInstance = null;
let elementsIsRecurring = false;
let elementsAppearance = null;

/**
 * Focus the payment error container within the form.
 *
 * @param {Element} ref Element inside the form.
 */
function focusPaymentError( ref ) {
  const form = ref?.closest( '[data-wp-interactive="mission/donation-form"]' );
  const errorEl = form?.querySelector( '.mission-df-card-error' );
  if ( errorEl ) {
    errorEl.setAttribute( 'tabindex', '-1' );
    errorEl.focus();
  }
}

/**
 * Focus the active step's heading after a step transition.
 *
 * @param {Element} ref Element inside the form.
 */
function focusStepHeading( ref ) {
  const form = ref?.closest( '[data-wp-interactive="mission/donation-form"]' );
  const heading = form?.querySelector(
    '.mission-df-step.active .mission-df-step-title'
  );
  if ( heading ) {
    heading.setAttribute( 'tabindex', '-1' );
    heading.focus();
  }
}

store( 'mission/donation-form', {
  state: {
    get currentAmounts() {
      const ctx = getContext();
      return getAmountsForFrequency( ctx, ctx.selectedFrequency );
    },
    get currentFrequencyHasDescriptions() {
      const ctx = getContext();
      const descs = ctx.amountDescriptions?.[ ctx.selectedFrequency ];
      if ( ! descs ) {
        return false;
      }
      return Object.values( descs ).some( ( d ) => d && d.trim() );
    },
    get isOngoing() {
      return getContext().isOngoing;
    },
    get frequencyDropdownOpen() {
      return getContext().frequencyDropdownOpen;
    },
    get isCustomAmount() {
      return getContext().isCustomAmount;
    },
    get tributeChecked() {
      return getContext().tributeChecked;
    },
    get feeRecoveryChecked() {
      return getContext().feeRecoveryChecked;
    },
    get showFeeDetails() {
      return getContext().showFeeDetails;
    },
    get selectedTipPercent() {
      return getContext().selectedTipPercent;
    },
    get tipMenuOpen() {
      return getContext().tipMenuOpen;
    },
    get isCustomTip() {
      return getContext().isCustomTip;
    },
    get customTipAmount() {
      return getContext().customTipAmount;
    },
    get tributeType() {
      return getContext().tributeType;
    },
    get honoreeName() {
      return getContext().honoreeName;
    },
    get honoreeEmail() {
      return getContext().honoreeEmail;
    },
    get notifyEnabled() {
      return getContext().notifyEnabled;
    },
    get notifyName() {
      return getContext().notifyName;
    },
    get notifyEmail() {
      return getContext().notifyEmail;
    },
    get notifyMethod() {
      return getContext().notifyMethod;
    },
    get isAnonymous() {
      return getContext().isAnonymous;
    },
    get firstName() {
      return getContext().firstName;
    },
    get lastName() {
      return getContext().lastName;
    },
    get email() {
      return getContext().email;
    },
    get stepDirection() {
      return getContext().stepDirection;
    },
    get leavingStep() {
      return getContext().leavingStep;
    },
    get isSubmitting() {
      return getContext().isSubmitting;
    },
    get paymentError() {
      return getContext().paymentError;
    },
    get paymentSuccess() {
      return getContext().paymentSuccess;
    },
    get honoreeNameError() {
      return getContext().honoreeNameError;
    },
    get notifyNameError() {
      return getContext().notifyNameError;
    },
    get notifyEmailError() {
      return getContext().notifyEmailError;
    },
    get firstNameError() {
      return getContext().firstNameError;
    },
    get lastNameError() {
      return getContext().lastNameError;
    },
    get emailError() {
      return getContext().emailError;
    },
    get phone() {
      return getContext().phone;
    },
    get phoneError() {
      return getContext().phoneError;
    },
    get comment() {
      return getContext().comment;
    },
    get commentChecked() {
      return getContext().commentChecked;
    },
  },
  actions: {
    selectOneTime() {
      const ctx = getContext();
      ctx.isOngoing = false;
      ctx.selectedFrequency = 'one_time';
      ctx.frequencyDropdownOpen = false;
      resetAmountForFrequency( ctx, 'one_time' );
    },
    selectOngoing() {
      const ctx = getContext();
      ctx.isOngoing = true;
      // Prefer monthly when switching to ongoing, fall back to first available.
      const freq = ctx.recurringFrequencies.includes( 'monthly' )
        ? 'monthly'
        : ctx.recurringFrequencies[ 0 ] || 'monthly';
      ctx.selectedFrequency = freq;
      ctx.frequencyDropdownOpen = false;
      resetAmountForFrequency( ctx, freq );
    },
    toggleFrequencyDropdown( event ) {
      event.stopPropagation();
      const ctx = getContext();
      ctx.frequencyDropdownOpen = ! ctx.frequencyDropdownOpen;
    },
    closeFrequencyDropdown() {
      getContext().frequencyDropdownOpen = false;
    },
    selectRecurringFrequency( event ) {
      event.stopPropagation();
      const ctx = getContext();
      const freq = ctx.frequency;
      ctx.selectedFrequency = freq;
      ctx.frequencyDropdownOpen = false;
      resetAmountForFrequency( ctx, freq );
    },
    selectAmount() {
      const ctx = getContext();
      ctx.selectedAmount = ctx.amount;
      ctx.isCustomAmount = false;
      ctx.customAmountValue = '';
    },
    toggleCustomAmount() {
      const ctx = getContext();
      ctx.isCustomAmount = true;
      ctx.selectedAmount = 0;
    },
    updateCustomAmount( event ) {
      const ctx = getContext();
      ctx.customAmountValue = event.target.value;
    },
    blurCustomAmount() {
      const ctx = getContext();
      if (
        ! ctx.customAmountValue ||
        parseFloat( ctx.customAmountValue ) <= 0
      ) {
        ctx.isCustomAmount = false;
        ctx.customAmountValue = '';
        const amounts = getAmountsForFrequency( ctx, ctx.selectedFrequency );
        const defaultAmt = ctx.defaultAmounts?.[ ctx.selectedFrequency ];
        ctx.selectedAmount = defaultAmt ?? amounts[ 0 ] ?? 0;
      }
    },
    nextStep() {
      const ctx = getContext();
      if ( ctx.leavingStep ) {
        return;
      }

      // Step 1 → validate amount + tribute.
      if ( ctx.currentStep === 1 ) {
        const amount = getEffectiveAmount( ctx );
        const minimum = ctx.settings.minimumAmount || 0;
        if ( amount < minimum ) {
          return;
        }

        // Validate tribute fields.
        if ( ctx.tributeChecked ) {
          ctx.honoreeNameError = ! ctx.honoreeName.trim();
          if ( ctx.notifyEnabled ) {
            ctx.notifyNameError = ! ctx.notifyName.trim();
            ctx.notifyEmailError =
              ctx.notifyMethod === 'email' &&
              ( ! ctx.notifyEmail ||
                ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( ctx.notifyEmail ) );
          }
          if (
            ctx.honoreeNameError ||
            ctx.notifyNameError ||
            ctx.notifyEmailError
          ) {
            const { ref } = getElement();
            const form = ref?.closest(
              '[data-wp-interactive="mission/donation-form"]'
            );
            form?.querySelector( '[aria-invalid="true"]' )?.focus();
            return;
          }
        }
      }

      // Step 2 (custom fields) → validate required fields.
      if ( ctx.currentStep === 2 && ctx.hasCustomFields ) {
        if ( ! validateCustomFields( ctx ) ) {
          const { ref } = getElement();
          const form = ref?.closest(
            '[data-wp-interactive="mission/donation-form"]'
          );
          form?.querySelector( '[aria-invalid="true"]' )?.focus();
          return;
        }
      }

      const { ref } = getElement();
      const oldStep = ctx.currentStep;
      ctx.stepDirection = 'forward';
      ctx.leavingStep = oldStep;
      ctx.currentStep = oldStep + 1;

      setTimeout( () => {
        ctx.leavingStep = 0;
        focusStepHeading( ref );
      }, 300 );
    },
    prevStep() {
      const ctx = getContext();
      if ( ctx.leavingStep ) {
        return;
      }

      const { ref } = getElement();
      const oldStep = ctx.currentStep;
      ctx.stepDirection = 'back';
      ctx.leavingStep = oldStep;
      ctx.currentStep = oldStep - 1;

      setTimeout( () => {
        ctx.leavingStep = 0;
        focusStepHeading( ref );
      }, 300 );
    },
    toggleFeeRecovery() {
      const ctx = getContext();
      ctx.feeRecoveryChecked = ! ctx.feeRecoveryChecked;
      ctx.showFeeDetails = false;
    },
    toggleFeeDetails() {
      const ctx = getContext();
      ctx.showFeeDetails = ! ctx.showFeeDetails;
    },
    toggleTipMenu( event ) {
      event.stopPropagation();
      const ctx = getContext();
      if ( ! ctx.tipMenuOpen ) {
        ctx.tipMenuOpen = true;

        // Position menu so the active option aligns over the trigger value.
        const { ref } = getElement();
        const wrap = ref?.closest( '.mission-df-tip-trigger-wrap' );
        const menu = wrap?.querySelector( '.mission-df-tip-menu' );
        const triggerValue = wrap?.querySelector(
          '.mission-df-tip-trigger-value'
        );
        if ( menu && triggerValue ) {
          const wrapRect = wrap.getBoundingClientRect();
          const valueRect = triggerValue.getBoundingClientRect();
          const valueCenterY =
            valueRect.top + valueRect.height / 2 - wrapRect.top;

          const activeOpt =
            menu.querySelector( '.mission-df-tip-option.active' ) ||
            menu.querySelector( '.mission-df-tip-option' );
          const options = Array.from(
            menu.querySelectorAll( '.mission-df-tip-option' )
          );
          const activeIndex = Math.max( 0, options.indexOf( activeOpt ) );
          const optionHeight = 37;
          const activeCenterY = activeIndex * optionHeight + optionHeight / 2;

          menu.style.top = `${ valueCenterY - activeCenterY }px`;
        }
      } else {
        ctx.tipMenuOpen = false;
      }
    },
    closeTipMenu() {
      getContext().tipMenuOpen = false;
    },
    selectTipPercent( event ) {
      event.stopPropagation();
      const ctx = getContext();
      ctx.selectedTipPercent = ctx.tipPercent;
      ctx.isCustomTip = false;
      ctx.customTipAmount = 0;
      ctx.tipMenuOpen = false;
    },
    selectCustomTip( event ) {
      event.stopPropagation();
      const ctx = getContext();
      ctx.isCustomTip = true;
      // Pre-fill at 15% of donation amount.
      const amount = getEffectiveAmount( ctx );
      ctx.customTipAmount = calculateTip( amount, 15 );
      ctx.selectedTipPercent = 0;
      ctx.tipMenuOpen = false;
    },
    updateCustomTipAmount( event ) {
      const ctx = getContext();
      const value = parseFloat( event.target.value ) || 0;
      ctx.customTipAmount = majorToMinor(
        value,
        ctx.settings.currency || 'USD'
      );
    },
    tipCustomUp() {
      const ctx = getContext();
      ctx.customTipAmount += 100;
    },
    tipCustomDown() {
      const ctx = getContext();
      ctx.customTipAmount = Math.max( 0, ctx.customTipAmount - 100 );
    },
    toggleTribute() {
      const ctx = getContext();
      ctx.tributeChecked = ! ctx.tributeChecked;
    },
    updateHonoreeName( event ) {
      const ctx = getContext();
      ctx.honoreeName = event.target.value;
      ctx.honoreeNameError = false;
    },
    updateHonoreeEmail( event ) {
      getContext().honoreeEmail = event.target.value;
    },
    selectTributeType( event ) {
      getContext().tributeType = event.target.value;
    },
    toggleNotify() {
      const ctx = getContext();
      if ( ctx.notifyEnabled ) {
        ctx.notifyName = '';
        ctx.notifyEmail = '';
        ctx.tributeMessage = '';
        ctx.notifyNameError = false;
        ctx.notifyEmailError = false;
      }
      ctx.notifyEnabled = ! ctx.notifyEnabled;
    },
    updateNotifyName( event ) {
      const ctx = getContext();
      ctx.notifyName = event.target.value;
      ctx.notifyNameError = false;
    },
    updateNotifyEmail( event ) {
      const ctx = getContext();
      ctx.notifyEmail = event.target.value;
      ctx.notifyEmailError = false;
    },
    selectNotifyMethod() {
      const ctx = getContext();
      ctx.notifyMethod = ctx.method;
    },
    updateNotifyCountry( event ) {
      getContext().notifyCountry = event.target.value;
    },
    updateNotifyAddress( event ) {
      getContext().notifyAddress = event.target.value;
    },
    updateNotifyCity( event ) {
      getContext().notifyCity = event.target.value;
    },
    updateNotifyState( event ) {
      getContext().notifyState = event.target.value;
    },
    updateNotifyZip( event ) {
      getContext().notifyZip = event.target.value;
    },
    updateTributeMessage( event ) {
      getContext().tributeMessage = event.target.value;
    },
    toggleAnonymous() {
      const ctx = getContext();
      ctx.isAnonymous = ! ctx.isAnonymous;
    },
    updateFirstName( event ) {
      const ctx = getContext();
      ctx.firstName = event.target.value;
      ctx.firstNameError = false;
    },
    updateLastName( event ) {
      const ctx = getContext();
      ctx.lastName = event.target.value;
      ctx.lastNameError = false;
    },
    updateEmail( event ) {
      const ctx = getContext();
      ctx.email = event.target.value;
      ctx.emailError = false;
    },
    updatePhone( event ) {
      const ctx = getContext();
      ctx.phone = event.target.value;
      ctx.phoneError = false;
    },
    updateComment( event ) {
      getContext().comment = event.target.value;
    },
    toggleComment() {
      const ctx = getContext();
      ctx.commentChecked = ! ctx.commentChecked;
    },
    updateCustomField( event ) {
      const ctx = getContext();
      const fieldId = ctx.fieldId;
      if ( ! ctx.customFieldValues ) {
        ctx.customFieldValues = {};
      }
      if ( event.target.type === 'checkbox' ) {
        ctx.customFieldValues[ fieldId ] = event.target.checked;
      } else {
        ctx.customFieldValues[ fieldId ] = event.target.value;
      }
      // Clear error on interaction.
      if ( ctx.customFieldErrors?.[ fieldId ] ) {
        const errors = { ...ctx.customFieldErrors };
        delete errors[ fieldId ];
        ctx.customFieldErrors = errors;
      }
    },
    toggleMultiselectDropdown( event ) {
      event.stopPropagation();
      const ctx = getContext();
      const fieldId = ctx.fieldId;
      ctx.openMultiselectId = ctx.openMultiselectId === fieldId ? '' : fieldId;
    },
    closeMultiselectDropdown() {
      getContext().openMultiselectId = '';
    },
    toggleMultiselectOption( event ) {
      event.stopPropagation();
      const ctx = getContext();
      const fieldId = ctx.fieldId;
      const optionValue = ctx.optionValue;
      if ( ! ctx.customFieldValues ) {
        ctx.customFieldValues = {};
      }
      const current = ctx.customFieldValues[ fieldId ] || [];
      if ( current.includes( optionValue ) ) {
        ctx.customFieldValues[ fieldId ] = current.filter(
          ( v ) => v !== optionValue
        );
      } else {
        ctx.customFieldValues[ fieldId ] = [ ...current, optionValue ];
      }
      // Clear error on interaction.
      if ( ctx.customFieldErrors?.[ fieldId ] ) {
        const errors = { ...ctx.customFieldErrors };
        delete errors[ fieldId ];
        ctx.customFieldErrors = errors;
      }
    },
    dismissError() {
      getContext().paymentError = '';
    },
    *submit() {
      const ctx = getContext();

      // Prevent double-submit.
      if ( ctx.isSubmitting ) {
        return;
      }

      ctx.isSubmitting = true;
      ctx.paymentError = '';

      try {
        if ( ! elementsInstance ) {
          ctx.paymentError =
            'Payment element not loaded. Please refresh the page.';
          focusPaymentError( getElement().ref );
          return;
        }

        // Validate all fields at once — our own and Stripe's.
        if ( ! ctx.settings.collectAddress ) {
          ctx.firstNameError = ! ctx.firstName;
          ctx.lastNameError = ! ctx.lastName;
        }
        ctx.emailError =
          ! ctx.email || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( ctx.email );
        if ( ctx.settings.phoneRequired ) {
          ctx.phoneError = ! ctx.phone?.trim();
        }

        const { error: submitError } = yield elementsInstance.submit();
        const hasOwnErrors =
          ctx.firstNameError ||
          ctx.lastNameError ||
          ctx.emailError ||
          ctx.phoneError;

        if ( hasOwnErrors || submitError ) {
          if ( hasOwnErrors ) {
            const { ref } = getElement();
            const form = ref?.closest(
              '[data-wp-interactive="mission/donation-form"]'
            );
            form?.querySelector( '[aria-invalid="true"]' )?.focus();
          }
          return;
        }

        // Calculate amounts (tip first — fee depends on it).
        const donationAmount = getEffectiveAmount( ctx );
        const tipAmount = getTipAmount( ctx, donationAmount );
        const { rate, fixed } = getFeeParams( ctx );
        const platformRate = getPlatformRate( ctx );
        const feeAmount = ctx.feeRecoveryChecked
          ? calculateFee( donationAmount, rate, fixed, platformRate )
          : 0;

        const isRecurring = ctx.selectedFrequency !== 'one_time';
        const createEndpoint = isRecurring
          ? 'donations/create-subscription'
          : 'donations/create-payment-intent';

        // Step 2: Create PaymentIntent (one-time) or Subscription (recurring).
        const intentResponse = yield fetch(
          `${ ctx.restUrl }${ createEndpoint }`,
          {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': ctx.restNonce,
            },
            body: JSON.stringify( {
              donation_amount: donationAmount + feeAmount,
              tip_amount: tipAmount,
              fee_amount: feeAmount,
              fee_mode: ctx.settings.tipEnabled ? 'tip' : 'flat',
              currency: ( ctx.settings.currency || 'USD' ).toLowerCase(),
              donor_email: ctx.email,
              donor_first_name: ctx.firstName,
              donor_last_name: ctx.lastName,
              frequency: ctx.selectedFrequency,
              campaign_id: ctx.campaignId || 0,
              source_post_id: ctx.sourcePostId || 0,
              form_id: ctx.formId || '',
              is_anonymous: ctx.isAnonymous,
              tribute_type: ctx.tributeChecked ? ctx.tributeType : '',
              honoree_name: ctx.tributeChecked ? ctx.honoreeName : '',
              notify_name:
                ctx.tributeChecked && ctx.notifyEnabled ? ctx.notifyName : '',
              notify_email:
                ctx.tributeChecked &&
                ctx.notifyEnabled &&
                ctx.notifyMethod === 'email'
                  ? ctx.notifyEmail
                  : '',
              notify_method:
                ctx.tributeChecked && ctx.notifyEnabled ? ctx.notifyMethod : '',
              notify_address_1:
                ctx.tributeChecked &&
                ctx.notifyEnabled &&
                ctx.notifyMethod === 'mail'
                  ? ctx.notifyAddress
                  : '',
              notify_city:
                ctx.tributeChecked &&
                ctx.notifyEnabled &&
                ctx.notifyMethod === 'mail'
                  ? ctx.notifyCity
                  : '',
              notify_state:
                ctx.tributeChecked &&
                ctx.notifyEnabled &&
                ctx.notifyMethod === 'mail'
                  ? ctx.notifyState
                  : '',
              notify_zip:
                ctx.tributeChecked &&
                ctx.notifyEnabled &&
                ctx.notifyMethod === 'mail'
                  ? ctx.notifyZip
                  : '',
              notify_country:
                ctx.tributeChecked &&
                ctx.notifyEnabled &&
                ctx.notifyMethod === 'mail'
                  ? ctx.notifyCountry
                  : '',
              message:
                ctx.tributeChecked && ctx.notifyEnabled
                  ? ctx.tributeMessage
                  : '',
              phone: ctx.phone || '',
              comment: ctx.comment || '',
              address_1: ctx.addressLine1 || '',
              address_2: ctx.addressLine2 || '',
              city: ctx.addressCity || '',
              state: ctx.addressState || '',
              zip: ctx.addressZip || '',
              country: ctx.addressCountry || '',
              custom_fields: ctx.hasCustomFields ? ctx.customFieldValues : {},
              custom_fields_config: ctx.hasCustomFields ? ctx.customFields : [],
            } ),
          }
        );

        const intentData = yield intentResponse.json();

        if ( ! intentResponse.ok || ! intentData.client_secret ) {
          ctx.paymentError =
            intentData.message || 'Failed to create payment. Please try again.';
          focusPaymentError( getElement().ref );
          return;
        }

        // Step 3: Confirm payment (works identically for subscriptions —
        // the first invoice's PaymentIntent is a regular PaymentIntent).
        const { error } = yield stripeInstance.confirmPayment( {
          elements: elementsInstance,
          clientSecret: intentData.client_secret,
          confirmParams: {
            payment_method_data: {
              billing_details: {
                ...( ! ctx.settings.collectAddress && {
                  name: `${ ctx.firstName } ${ ctx.lastName }`,
                } ),
                email: ctx.email,
              },
            },
            return_url: window.location.href,
          },
          redirect: 'if_required',
        } );

        if ( error ) {
          ctx.paymentError = error.message;
          focusPaymentError( getElement().ref );
          return;
        }

        // Step 4: Confirm the donation. The server verifies PaymentIntent
        // status with Stripe and transitions the transaction synchronously
        // in the common case. If the server returns 202 (still processing),
        // the webhook will complete the transaction asynchronously — we
        // show the success UI either way because Stripe.js has already
        // confirmed the payment client-side.
        const confirmEndpoint = isRecurring
          ? 'donations/confirm-subscription'
          : 'donations/confirm';
        const confirmBody = {
          transaction_id: intentData.transaction_id,
          payment_intent_id: intentData.client_secret.split( '_secret_' )[ 0 ],
        };

        if ( isRecurring ) {
          confirmBody.subscription_id = intentData.subscription_id;
        }

        const confirmResponse = yield fetch(
          `${ ctx.restUrl }${ confirmEndpoint }`,
          {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': ctx.restNonce,
            },
            body: JSON.stringify( confirmBody ),
          }
        );

        if ( ! confirmResponse.ok && confirmResponse.status !== 202 ) {
          // 402/4xx/5xx — log but proceed to success UI. Stripe.js already
          // confirmed payment, so the donor's card is charged regardless.
          // eslint-disable-next-line no-console
          console.error(
            'MissionWP: Unexpected confirm response',
            confirmResponse.status
          );
        }

        // Step 5: Handle confirmation — redirect or show success state.
        if (
          ctx.confirmationType === 'redirect' &&
          ctx.confirmationRedirectUrl
        ) {
          window.location.href = ctx.confirmationRedirectUrl;
          return;
        }

        ctx.paymentSuccess = true;

        const { ref } = getElement();
        const container = ref?.closest(
          '[data-wp-interactive="mission/donation-form"]'
        );
        if ( container ) {
          container.scrollIntoView( { behavior: 'smooth', block: 'start' } );
        }
      } catch ( err ) {
        ctx.paymentError =
          err.message || 'An unexpected error occurred. Please try again.';
      } finally {
        ctx.isSubmitting = false;
      }
    },
  },
  callbacks: {
    isStep1Active() {
      return getContext().currentStep === 1;
    },
    isStep1Leaving() {
      return getContext().leavingStep === 1;
    },
    isCustomFieldsStepActive() {
      const ctx = getContext();
      return ctx.hasCustomFields && ctx.currentStep === 2;
    },
    isCustomFieldsStepLeaving() {
      const ctx = getContext();
      return ctx.hasCustomFields && ctx.leavingStep === 2;
    },
    isPaymentStepActive() {
      return getContext().currentStep === getPaymentStep( getContext() );
    },
    isPaymentStepLeaving() {
      return getContext().leavingStep === getPaymentStep( getContext() );
    },
    isSlideBack() {
      return getContext().stepDirection === 'back';
    },
    isProgressDot1Active() {
      return getContext().currentStep === 1;
    },
    isProgressDot1Complete() {
      return getContext().currentStep > 1;
    },
    isProgressLine1Complete() {
      return getContext().currentStep > 1;
    },
    isProgressDot2Active() {
      return getContext().currentStep === 2;
    },
    isProgressDot2Complete() {
      return getContext().currentStep > 2;
    },
    isProgressLine2Complete() {
      return getContext().currentStep > 2;
    },
    isProgressDot3Active() {
      const ctx = getContext();
      return ctx.currentStep === getPaymentStep( ctx );
    },
    hasCustomFieldError() {
      const ctx = getContext();
      return !! ctx.customFieldErrors?.[ ctx.fieldId ];
    },
    isMultiselectOpen() {
      const ctx = getContext();
      return ctx.openMultiselectId === ctx.fieldId;
    },
    isMultiselectOptionSelected() {
      const ctx = getContext();
      const values = ctx.customFieldValues?.[ ctx.fieldId ] || [];
      return values.includes( ctx.optionValue );
    },
    multiselectLabel() {
      const ctx = getContext();
      const values = ctx.customFieldValues?.[ ctx.fieldId ] || [];
      if ( values.length === 0 ) {
        return 'Select options';
      }
      return values.join( ', ' );
    },
    isSelectedFrequency() {
      const ctx = getContext();
      return ctx.selectedFrequency === ctx.frequency;
    },
    selectedFrequencyLabel() {
      const ctx = getContext();
      return (
        ctx.frequencyLabels[ ctx.selectedFrequency ] || ctx.selectedFrequency
      );
    },
    isSelectedAmount() {
      const ctx = getContext();
      return ! ctx.isCustomAmount && ctx.selectedAmount === ctx.amount;
    },
    tipTriggerLabel() {
      const ctx = getContext();
      if ( ctx.isCustomTip ) {
        const amount = getEffectiveAmount( ctx );
        if ( amount <= 0 || ctx.customTipAmount <= 0 ) {
          return '0%';
        }
        const pct = ( ctx.customTipAmount / amount ) * 100;
        const label =
          pct === Math.floor( pct ) ? `${ pct }%` : `${ pct.toFixed( 1 ) }%`;
        return label;
      }
      return ctx.selectedTipPercent ? `${ ctx.selectedTipPercent }%` : '0%';
    },
    isTipOptionActive() {
      const ctx = getContext();
      return ! ctx.isCustomTip && ctx.selectedTipPercent === ctx.tipPercent;
    },
    customTipDisplayValue() {
      const ctx = getContext();
      return minorToMajor(
        ctx.customTipAmount,
        ctx.settings.currency || 'USD'
      ).toFixed( 2 );
    },
    notifyLinkLabel() {
      return getContext().notifyEnabled
        ? '\u2212 Remove notification'
        : 'Notify someone of this tribute';
    },
    isNotifyEmail() {
      return getContext().notifyMethod === 'email';
    },
    isNotifyMail() {
      return getContext().notifyMethod === 'mail';
    },
    showMinimumWarning() {
      const ctx = getContext();
      if ( ! ctx.isCustomAmount || ! ctx.customAmountValue ) {
        return false;
      }
      const amount = majorToMinor(
        parseFloat( ctx.customAmountValue ),
        ctx.settings.currency || 'USD'
      );
      return amount > 0 && amount < ( ctx.settings.minimumAmount || 0 );
    },
    formattedPresetAmount() {
      const ctx = getContext();
      return formatCurrency( ctx.amount, ctx.settings.currency );
    },
    amountDescription() {
      const ctx = getContext();
      const descs = ctx.amountDescriptions?.[ ctx.selectedFrequency ];
      return descs?.[ String( ctx.amount ) ] || '';
    },
    formattedMinimumAmount() {
      const ctx = getContext();
      return formatCurrency(
        ctx.settings.minimumAmount || 0,
        ctx.settings.currency
      );
    },
    formattedDonationAmount() {
      const ctx = getContext();
      return formatCurrency( getEffectiveAmount( ctx ), ctx.settings.currency );
    },
    formattedAmountWithFrequency() {
      const ctx = getContext();
      return formatCurrency(
        getEffectiveAmount( ctx ),
        ctx.settings.currency,
        true
      );
    },
    frequencySuffix() {
      const ctx = getContext();
      const suffixes = {
        weekly: '/wk',
        monthly: '/mo',
        quarterly: '/qtr',
        annually: '/yr',
      };
      return suffixes[ ctx.selectedFrequency ] || '';
    },
    formattedFeeAmount() {
      const ctx = getContext();
      const amount = getEffectiveAmount( ctx );
      const { rate, fixed } = getFeeParams( ctx );
      return formatCurrency(
        calculateFee( amount, rate, fixed, getPlatformRate( ctx ) ),
        ctx.settings.currency
      );
    },
    formattedTipAmount() {
      const ctx = getContext();
      const amount = getEffectiveAmount( ctx );
      return formatCurrency(
        getTipAmount( ctx, amount ),
        ctx.settings.currency
      );
    },
    formattedTotalAmount() {
      const ctx = getContext();
      const amount = getEffectiveAmount( ctx );
      let total = amount;
      if ( ctx.feeRecoveryChecked ) {
        const { rate, fixed } = getFeeParams( ctx );
        total += calculateFee( amount, rate, fixed, getPlatformRate( ctx ) );
      }
      total += getTipAmount( ctx, amount );
      return formatCurrency( total, ctx.settings.currency );
    },
    focusComment() {
      const ctx = getContext();
      if ( ctx.commentChecked ) {
        const { ref } = getElement();
        if ( ref && ref.tagName === 'TEXTAREA' ) {
          ref.focus();
        }
      }
    },
    focusCustomInput() {
      const ctx = getContext();
      if ( ctx.isCustomAmount ) {
        const { ref } = getElement();
        if ( ref && ref.tagName === 'INPUT' ) {
          ref.focus();
        }
      }
    },
    *mountPaymentElement() {
      const ctx = getContext();
      const { ref } = getElement();

      if ( ! ctx.stripePublishableKey || ! ref ) {
        return;
      }

      const configResponse = yield fetch(
        `${ ctx.restUrl }donations/payment-config`
      );
      const configData = yield configResponse.json();

      if ( ! configData.connected_account_id ) {
        ctx.paymentError =
          'Payment processing is not available. The site owner needs to reconnect Stripe in the MissionWP settings.';
        return;
      }

      stripeInstance = window.Stripe( ctx.stripePublishableKey, {
        stripeAccount: configData.connected_account_id,
        locale: ctx.locale || 'auto',
      } );

      const amount = getEffectiveAmount( ctx );
      const tip = calculateTip( amount, ctx.selectedTipPercent );
      const { rate: feeRate, fixed: feeFixed } = getFeeParams( ctx );
      const fee = ctx.feeRecoveryChecked
        ? calculateFee( amount, feeRate, feeFixed, getPlatformRate( ctx ) )
        : 0;

      const customAppearance = ctx.stripeAppearance || {};

      elementsAppearance = {
        theme: customAppearance.theme || 'stripe',
        variables: {
          colorPrimary: ctx.primaryColor || '#2FA36B',
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

      elementsInstance = stripeInstance.elements( {
        mode: 'payment',
        amount: amount + fee + tip,
        currency: ( ctx.settings.currency || 'USD' ).toLowerCase(),
        paymentMethodTypes: [ 'card' ],
        appearance: elementsAppearance,
      } );

      const paymentElement = elementsInstance.create( 'payment', {
        layout: 'tabs',
        fields: {
          billingDetails: {
            name: 'never',
            email: 'never',
            address: ctx.settings.collectAddress ? 'never' : 'auto',
          },
        },
        wallets: {
          link: 'never',
        },
      } );

      paymentElement.mount( ref );

      // Mount Address Element for full billing address collection.
      if ( ctx.settings.collectAddress ) {
        const addressContainer = document.getElementById(
          ref.id.replace( 'payment-element', 'address-element' )
        );
        if ( addressContainer ) {
          const addressElement = elementsInstance.create( 'address', {
            mode: 'billing',
            display: { name: 'split' },
          } );
          addressElement.mount( addressContainer );

          addressElement.on( 'change', ( event ) => {
            if ( event.value ) {
              ctx.firstName = event.value.firstName || '';
              ctx.lastName = event.value.lastName || '';
              // Capture billing address.
              const addr = event.value.address || {};
              ctx.addressLine1 = addr.line1 || '';
              ctx.addressLine2 = addr.line2 || '';
              ctx.addressCity = addr.city || '';
              ctx.addressState = addr.state || '';
              ctx.addressZip = addr.postal_code || '';
              ctx.addressCountry = addr.country || '';
            }
          } );
        }
      }

      paymentElement.on( 'change', ( event ) => {
        ctx.paymentError = event.error ? event.error.message : '';
      } );
    },
    watchAmounts() {
      const ctx = getContext();
      // Read reactive properties before any early return so the
      // Interactivity API tracks them as dependencies of this watcher.
      void ctx.selectedFrequency;
      void ctx.selectedAmount;
      void ctx.customAmount;
      void ctx.selectedTipPercent;
      void ctx.feeRecoveryChecked;
      void ctx.isCustomTip;
      void ctx.customTipAmount;

      if ( ! elementsInstance ) {
        return;
      }
      const amount = getEffectiveAmount( ctx );
      const tip = getTipAmount( ctx, amount );
      const { rate: feeRate, fixed: feeFixed } = getFeeParams( ctx );
      const fee = ctx.feeRecoveryChecked
        ? calculateFee( amount, feeRate, feeFixed, getPlatformRate( ctx ) )
        : 0;
      const total = amount + fee + tip;
      const isRecurring = ctx.selectedFrequency !== 'one_time';

      // Recreate Elements when switching between one-time and recurring
      // because setupFutureUsage can only be set at creation time.
      if ( isRecurring !== elementsIsRecurring ) {
        elementsIsRecurring = isRecurring;
        const container = document.querySelector(
          '.mission-df-payment-element'
        );
        if ( container && total > 0 ) {
          elementsInstance = stripeInstance.elements( {
            mode: 'payment',
            amount: total,
            currency: ( ctx.settings.currency || 'USD' ).toLowerCase(),
            paymentMethodTypes: [ 'card' ],
            ...( isRecurring && {
              setupFutureUsage: 'off_session',
            } ),
            appearance: elementsAppearance,
          } );
          const paymentElement = elementsInstance.create( 'payment', {
            layout: 'tabs',
            fields: {
              billingDetails: {
                name: 'never',
                email: 'never',
                address: ctx.settings.collectAddress ? 'never' : 'auto',
              },
            },
            wallets: { link: 'never' },
          } );
          container.replaceChildren();
          paymentElement.mount( container );

          paymentElement.on( 'change', ( event ) => {
            ctx.paymentError = event.error ? event.error.message : '';
          } );

          // Recreate Address Element if address collection is enabled,
          // since it belongs to the old Elements instance.
          if ( ctx.settings.collectAddress ) {
            const addressContainer = document.getElementById(
              container.id.replace( 'payment-element', 'address-element' )
            );
            if ( addressContainer ) {
              const addressElement = elementsInstance.create( 'address', {
                mode: 'billing',
                display: { name: 'split' },
              } );
              addressContainer.replaceChildren();
              addressElement.mount( addressContainer );

              addressElement.on( 'change', ( event ) => {
                if ( event.value ) {
                  ctx.firstName = event.value.firstName || '';
                  ctx.lastName = event.value.lastName || '';
                  const addr = event.value.address || {};
                  ctx.addressLine1 = addr.line1 || '';
                  ctx.addressLine2 = addr.line2 || '';
                  ctx.addressCity = addr.city || '';
                  ctx.addressState = addr.state || '';
                  ctx.addressZip = addr.postal_code || '';
                  ctx.addressCountry = addr.country || '';
                }
              } );
            }
          }
        }
      } else if ( total > 0 ) {
        elementsInstance.update( { amount: total } );
      }
    },
  },
} );
