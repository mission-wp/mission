/**
 * Donation form frontend — Interactivity API store.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * Format minor units (cents) as a currency string.
 *
 * @param {number}  minorUnits       Amount in minor units (e.g. 1000 = $10.00).
 * @param {string}  currencyCode     ISO 4217 currency code.
 * @param {boolean} stripZeroCents   Drop ".00" when the amount is a whole number.
 * @return {string}                  Formatted currency string.
 */
function formatCurrency(
	minorUnits,
	currencyCode = 'USD',
	stripZeroCents = false
) {
	const major = minorUnits / 100;
	const fractionDigits =
		stripZeroCents && Number.isInteger( major ) ? 0 : 2;
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
 * Stripe standard: 2.9% + 30¢.
 *
 * @param {number} amount Amount in minor units.
 * @return {number} Fee in minor units.
 */
function calculateFee( amount ) {
	return Math.round( amount * 0.029 + 30 );
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
		submit() {
			// No-op — payment processing will be added later.
		},
	},
	callbacks: {
		isStep1() {
			return getContext().currentStep === 1;
		},
		isStep2() {
			return getContext().currentStep === 2;
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
	},
} );
