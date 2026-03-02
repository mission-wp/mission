/**
 * Donation form frontend — Interactivity API store.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * Module-scope references for Stripe instances.
 */
let stripeInstance = null;
let cardElement = null;

/**
 * Format minor units (cents) as a currency string.
 *
 * @param {number}  minorUnits     Amount in minor units (e.g. 1000 = $10.00).
 * @param {string}  currencyCode   ISO 4217 currency code.
 * @param {boolean} stripZeroCents Drop ".00" when the amount is a whole number.
 * @return {string}                  Formatted currency string.
 */
function formatCurrency(
	minorUnits,
	currencyCode = 'USD',
	stripZeroCents = false
) {
	const major = minorUnits / 100;
	const fractionDigits = stripZeroCents && Number.isInteger( major ) ? 0 : 2;
	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: currencyCode,
			minimumFractionDigits: fractionDigits,
			maximumFractionDigits: fractionDigits,
		} ).format( major );
	} catch {
		return `$${ fractionDigits ? major.toFixed( 2 ) : major }`;
	}
}

/**
 * Get the effective donation amount in minor units.
 *
 * @param {Object} ctx Interactivity context.
 * @return {number} Amount in minor units.
 */
function getEffectiveAmount( ctx ) {
	if ( ctx.isCustomAmount && ctx.customAmountValue ) {
		return Math.round( parseFloat( ctx.customAmountValue ) * 100 ) || 0;
	}
	return ctx.selectedAmount || 0;
}

/**
 * Calculate the Stripe processing fee in minor units.
 *
 * Covers the Stripe fee on the donation only. Mission absorbs the fee on
 * its own tip server-side. Solving fee = 0.029 × (donation + fee) + 30:
 *   fee = (0.029 × donation + 30) / 0.971
 *
 * @param {number} donationAmount Donation amount in minor units.
 * @return {number} Fee in minor units.
 */
function calculateFee( donationAmount ) {
	// Algebraic estimate of fee needed so donor covers Stripe's charge.
	const estimate = Math.round( ( 0.029 * donationAmount + 30 ) / 0.971 );
	// Correction pass: simulate Stripe's actual fee on the resulting charge
	// to eliminate ±1¢ rounding drift from the algebraic formula.
	return Math.round( ( donationAmount + estimate ) * 0.029 + 30 );
}

/**
 * Calculate tip amount in minor units.
 *
 * @param {number} amount  Donation amount in minor units.
 * @param {number} percent Tip percentage.
 * @return {number} Tip in minor units.
 */
function calculateTip( amount, percent ) {
	return Math.round( ( amount * percent ) / 100 );
}

store( 'mission/donation-form', {
	state: {
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
		get honoreeName() {
			return getContext().honoreeName;
		},
		get honoreeEmail() {
			return getContext().honoreeEmail;
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
		get isSubmitting() {
			return getContext().isSubmitting;
		},
		get paymentError() {
			return getContext().paymentError;
		},
		get paymentSuccess() {
			return getContext().paymentSuccess;
		},
	},
	actions: {
		selectOneTime() {
			const ctx = getContext();
			ctx.isOngoing = false;
			ctx.selectedFrequency = 'one_time';
			ctx.frequencyDropdownOpen = false;
		},
		selectOngoing() {
			const ctx = getContext();
			ctx.isOngoing = true;
			// Default to first recurring frequency.
			ctx.selectedFrequency = ctx.recurringFrequencies[ 0 ] || 'monthly';
			ctx.frequencyDropdownOpen = false;
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
			ctx.selectedFrequency = ctx.frequency;
			ctx.frequencyDropdownOpen = false;
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
		nextStep() {
			const ctx = getContext();
			const amount = getEffectiveAmount( ctx );
			const minimum = ctx.settings.minimumAmount || 0;
			if ( amount < minimum ) {
				return;
			}
			ctx.currentStep = 2;
		},
		prevStep() {
			getContext().currentStep = 1;
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
		tipUp() {
			const ctx = getContext();
			const options = [ 0, ...( ctx.settings.tipPercentages || [] ) ];
			const idx = options.indexOf( ctx.selectedTipPercent );
			if ( idx < options.length - 1 ) {
				ctx.selectedTipPercent = options[ idx + 1 ];
			}
		},
		tipDown() {
			const ctx = getContext();
			const options = [ 0, ...( ctx.settings.tipPercentages || [] ) ];
			const idx = options.indexOf( ctx.selectedTipPercent );
			if ( idx > 0 ) {
				ctx.selectedTipPercent = options[ idx - 1 ];
			}
		},
		toggleTribute() {
			const ctx = getContext();
			ctx.tributeChecked = ! ctx.tributeChecked;
		},
		updateHonoreeName( event ) {
			getContext().honoreeName = event.target.value;
		},
		updateHonoreeEmail( event ) {
			getContext().honoreeEmail = event.target.value;
		},
		toggleAnonymous() {
			const ctx = getContext();
			ctx.isAnonymous = ! ctx.isAnonymous;
		},
		updateFirstName( event ) {
			getContext().firstName = event.target.value;
		},
		updateLastName( event ) {
			getContext().lastName = event.target.value;
		},
		updateEmail( event ) {
			getContext().email = event.target.value;
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
				// Validate required fields.
				if ( ! ctx.email || ! ctx.firstName || ! ctx.lastName ) {
					ctx.paymentError = 'Please fill in all required fields.';
					return;
				}

				if ( ! cardElement ) {
					ctx.paymentError =
						'Card element not loaded. Please refresh the page.';
					return;
				}

				// Calculate amounts (tip first — fee depends on it).
				const donationAmount = getEffectiveAmount( ctx );
				const tipAmount = calculateTip(
					donationAmount,
					ctx.selectedTipPercent
				);
				const feeAmount = ctx.feeRecoveryChecked
					? calculateFee( donationAmount )
					: 0;

				// Step 1: Create PaymentIntent via our REST endpoint.
				const intentResponse = yield fetch(
					`${ ctx.restUrl }donations/create-payment-intent`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': ctx.restNonce,
						},
						body: JSON.stringify( {
							donation_amount: donationAmount + feeAmount,
							tip_amount: tipAmount,
						} ),
					}
				);

				const intentData = yield intentResponse.json();

				if ( ! intentResponse.ok || ! intentData.client_secret ) {
					ctx.paymentError =
						intentData.message ||
						'Failed to create payment. Please try again.';
					return;
				}

				// Step 2: Confirm the card payment.
				const { error, paymentIntent } =
					yield stripeInstance.confirmCardPayment(
						intentData.client_secret,
						{
							payment_method: {
								card: cardElement,
								billing_details: {
									name: `${ ctx.firstName } ${ ctx.lastName }`,
									email: ctx.email,
								},
							},
						}
					);

				if ( error ) {
					ctx.paymentError = error.message;
					return;
				}

				if ( paymentIntent.status !== 'succeeded' ) {
					ctx.paymentError =
						'Payment was not completed. Please try again.';
					return;
				}

				// Step 4: Record the donation in our database.
				const confirmResponse = yield fetch(
					`${ ctx.restUrl }donations/confirm`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': ctx.restNonce,
						},
						body: JSON.stringify( {
							payment_intent_id: paymentIntent.id,
							donor_email: ctx.email,
							donor_first_name: ctx.firstName,
							donor_last_name: ctx.lastName,
							donation_amount: donationAmount,
							fee_amount: feeAmount,
							tip_amount: tipAmount,
							currency: ctx.settings.currency || 'USD',
							frequency: ctx.selectedFrequency,
							campaign_id: ctx.campaignId || 0,
							source_post_id: ctx.sourcePostId || 0,
							is_anonymous: ctx.isAnonymous,
						} ),
					}
				);

				const confirmData = yield confirmResponse.json();

				if ( ! confirmResponse.ok || ! confirmData.success ) {
					// Payment succeeded but recording failed — don't show error to donor.
					// eslint-disable-next-line no-console
					console.error(
						'Mission: Failed to record donation',
						confirmData
					);
				}

				// Step 5: Show success state.
				ctx.paymentSuccess = true;
			} catch ( err ) {
				ctx.paymentError =
					err.message ||
					'An unexpected error occurred. Please try again.';
			} finally {
				ctx.isSubmitting = false;
			}
		},
	},
	callbacks: {
		isStep1() {
			return getContext().currentStep === 1;
		},
		isStep2() {
			return getContext().currentStep === 2;
		},
		hideStep1() {
			const ctx = getContext();
			return ctx.currentStep !== 1 || ctx.paymentSuccess;
		},
		hideStep2() {
			const ctx = getContext();
			return ctx.currentStep !== 2 || ctx.paymentSuccess;
		},
		isSelectedFrequency() {
			const ctx = getContext();
			return ctx.selectedFrequency === ctx.frequency;
		},
		selectedFrequencyLabel() {
			const ctx = getContext();
			return (
				ctx.frequencyLabels[ ctx.selectedFrequency ] ||
				ctx.selectedFrequency
			);
		},
		isSelectedAmount() {
			const ctx = getContext();
			return ! ctx.isCustomAmount && ctx.selectedAmount === ctx.amount;
		},
		tipPercentLabel() {
			const ctx = getContext();
			return ctx.selectedTipPercent
				? `${ ctx.selectedTipPercent }%`
				: '0%';
		},
		showMinimumWarning() {
			const ctx = getContext();
			if ( ! ctx.isCustomAmount || ! ctx.customAmountValue ) {
				return false;
			}
			const amount = Math.round(
				parseFloat( ctx.customAmountValue ) * 100
			);
			return amount > 0 && amount < ( ctx.settings.minimumAmount || 0 );
		},
		formattedPresetAmount() {
			const ctx = getContext();
			return formatCurrency( ctx.amount, ctx.settings.currency );
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
			return formatCurrency(
				getEffectiveAmount( ctx ),
				ctx.settings.currency
			);
		},
		formattedAmountWithFrequency() {
			const ctx = getContext();
			const amount = formatCurrency(
				getEffectiveAmount( ctx ),
				ctx.settings.currency,
				true
			);
			const suffixes = {
				monthly: '/mo',
				quarterly: '/three months',
				annually: '/year',
			};
			const suffix = suffixes[ ctx.selectedFrequency ] || '';
			return `${ amount }${ suffix }`;
		},
		formattedFeeAmount() {
			const ctx = getContext();
			const amount = getEffectiveAmount( ctx );
			return formatCurrency(
				calculateFee( amount ),
				ctx.settings.currency
			);
		},
		formattedTipAmount() {
			const ctx = getContext();
			const amount = getEffectiveAmount( ctx );
			return formatCurrency(
				calculateTip( amount, ctx.selectedTipPercent ),
				ctx.settings.currency
			);
		},
		formattedTotalAmount() {
			const ctx = getContext();
			const amount = getEffectiveAmount( ctx );
			let total = amount;
			if ( ctx.feeRecoveryChecked ) {
				total += calculateFee( amount );
			}
			if ( ctx.selectedTipPercent ) {
				total += calculateTip( amount, ctx.selectedTipPercent );
			}
			return formatCurrency( total, ctx.settings.currency );
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
		*mountCardElement() {
			const ctx = getContext();
			const { ref } = getElement();

			if ( ! ctx.stripePublishableKey || ! ref ) {
				return;
			}

			// Fetch connected account ID for Stripe Connect direct charges.
			const configResponse = yield fetch(
				`${ ctx.restUrl }donations/payment-config`
			);
			const configData = yield configResponse.json();

			if ( ! configData.connected_account_id ) {
				ctx.paymentError =
					'Payment processing is not available. The site owner needs to reconnect Stripe in the Mission settings.';
				return;
			}

			stripeInstance = window.Stripe( ctx.stripePublishableKey, {
				stripeAccount: configData.connected_account_id,
			} );
			const elements = stripeInstance.elements();

			cardElement = elements.create( 'card', {
				style: {
					base: {
						fontSize: '14px',
						color: '#1e1e1e',
						fontFamily:
							'-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
						'::placeholder': {
							color: '#6b7280',
						},
					},
					invalid: {
						color: '#dc2626',
					},
				},
			} );

			cardElement.mount( ref );

			cardElement.on( 'change', ( event ) => {
				ctx.paymentError = event.error ? event.error.message : '';
			} );
		},
	},
} );
