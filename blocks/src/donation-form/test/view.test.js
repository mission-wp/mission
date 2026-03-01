/* eslint-env jest */

/**
 * Tests for the donation form Interactivity API store.
 *
 * Uses a mock of @wordpress/interactivity (mapped via jest.config.js)
 * so we can call actions/callbacks and inspect context mutations.
 */

const interactivity = require( '@wordpress/interactivity' );

// Import the view module — this calls store() and populates _mockStoreDefinition.
require( '../view' );

const { _mockStoreDefinition: storeDef } = interactivity;
const ctx = interactivity._mockContext;

/**
 * Reset the shared context to a fresh donation form state.
 */
function resetContext() {
	for ( const key of Object.keys( ctx ) ) {
		delete ctx[ key ];
	}
	Object.assign( ctx, {
		currentStep: 1,
		isOngoing: false,
		selectedFrequency: 'one_time',
		recurringFrequencies: [ 'monthly', 'quarterly', 'annually' ],
		frequencyLabels: {
			one_time: 'One Time',
			monthly: 'Monthly',
			quarterly: 'Quarterly',
			annually: 'Annually',
		},
		frequencyDropdownOpen: false,
		selectedAmount: 1000,
		isCustomAmount: false,
		customAmountValue: '',
		feeRecoveryChecked: false,
		showFeeDetails: false,
		selectedTipPercent: 15,
		tributeChecked: false,
		honoreeName: '',
		honoreeEmail: '',
		isAnonymous: false,
		firstName: '',
		lastName: '',
		email: '',
		settings: {
			amounts: [ 1000, 2500, 5000, 10000 ],
			customAmount: true,
			minimumAmount: 500,
			recurringEnabled: true,
			recurringFrequencies: [ 'monthly', 'quarterly', 'annually' ],
			feeRecovery: true,
			tipEnabled: true,
			tipPercentages: [ 5, 10, 15, 20 ],
			currency: 'USD',
			siteName: 'Test Org',
		},
	} );
}

beforeEach( () => {
	resetContext();
} );

const fakeEvent = { stopPropagation: jest.fn() };

// ── Frequency toggle (One Time / Ongoing) ──

describe( 'frequency toggle', () => {
	it( 'selectOngoing sets isOngoing true and picks first recurring frequency', () => {
		storeDef.actions.selectOngoing();
		expect( ctx.isOngoing ).toBe( true );
		expect( ctx.selectedFrequency ).toBe( 'monthly' );
	} );

	it( 'selectOneTime resets to one_time', () => {
		storeDef.actions.selectOngoing();
		storeDef.actions.selectOneTime();
		expect( ctx.isOngoing ).toBe( false );
		expect( ctx.selectedFrequency ).toBe( 'one_time' );
	} );

	it( 'selectOngoing closes the dropdown if it was open', () => {
		ctx.frequencyDropdownOpen = true;
		storeDef.actions.selectOngoing();
		expect( ctx.frequencyDropdownOpen ).toBe( false );
	} );
} );

// ── Frequency dropdown ──

describe( 'frequency dropdown', () => {
	it( 'toggleFrequencyDropdown opens the dropdown', () => {
		expect( ctx.frequencyDropdownOpen ).toBe( false );
		storeDef.actions.toggleFrequencyDropdown( fakeEvent );
		expect( ctx.frequencyDropdownOpen ).toBe( true );
	} );

	it( 'toggleFrequencyDropdown closes when already open', () => {
		ctx.frequencyDropdownOpen = true;
		storeDef.actions.toggleFrequencyDropdown( fakeEvent );
		expect( ctx.frequencyDropdownOpen ).toBe( false );
	} );

	it( 'toggleFrequencyDropdown calls stopPropagation', () => {
		fakeEvent.stopPropagation.mockClear();
		storeDef.actions.toggleFrequencyDropdown( fakeEvent );
		expect( fakeEvent.stopPropagation ).toHaveBeenCalled();
	} );

	it( 'selectRecurringFrequency sets frequency and closes dropdown', () => {
		ctx.isOngoing = true;
		ctx.frequencyDropdownOpen = true;
		// Simulates the child data-wp-context merging { frequency: 'quarterly' }.
		ctx.frequency = 'quarterly';
		storeDef.actions.selectRecurringFrequency( fakeEvent );
		expect( ctx.selectedFrequency ).toBe( 'quarterly' );
		expect( ctx.frequencyDropdownOpen ).toBe( false );
	} );

	it( 'selectRecurringFrequency calls stopPropagation', () => {
		ctx.frequency = 'annually';
		fakeEvent.stopPropagation.mockClear();
		storeDef.actions.selectRecurringFrequency( fakeEvent );
		expect( fakeEvent.stopPropagation ).toHaveBeenCalled();
	} );
} );

// ── Callbacks ──

describe( 'selectedFrequencyLabel', () => {
	it( 'returns the label for the selected frequency', () => {
		ctx.selectedFrequency = 'quarterly';
		expect( storeDef.callbacks.selectedFrequencyLabel() ).toBe(
			'Quarterly'
		);
	} );

	it( 'falls back to the raw key if no label exists', () => {
		ctx.selectedFrequency = 'biweekly';
		expect( storeDef.callbacks.selectedFrequencyLabel() ).toBe(
			'biweekly'
		);
	} );
} );

describe( 'isSelectedFrequency', () => {
	it( 'returns true when context frequency matches selected', () => {
		ctx.selectedFrequency = 'monthly';
		ctx.frequency = 'monthly';
		expect( storeDef.callbacks.isSelectedFrequency() ).toBe( true );
	} );

	it( 'returns false when they differ', () => {
		ctx.selectedFrequency = 'monthly';
		ctx.frequency = 'annually';
		expect( storeDef.callbacks.isSelectedFrequency() ).toBe( false );
	} );
} );

// ── Tip stepper ──

describe( 'tip stepper', () => {
	// tipPercentages: [5, 10, 15, 20], default selected: 15
	// options array: [0, 5, 10, 15, 20]

	it( 'tipUp increases from 15 to 20', () => {
		expect( ctx.selectedTipPercent ).toBe( 15 );
		storeDef.actions.tipUp();
		expect( ctx.selectedTipPercent ).toBe( 20 );
	} );

	it( 'tipDown decreases from 15 to 10', () => {
		ctx.selectedTipPercent = 15;
		storeDef.actions.tipDown();
		expect( ctx.selectedTipPercent ).toBe( 10 );
	} );

	it( 'tipDown from 10 goes to 5', () => {
		ctx.selectedTipPercent = 10;
		storeDef.actions.tipDown();
		expect( ctx.selectedTipPercent ).toBe( 5 );
	} );

	it( 'tipDown from 5 goes to 0', () => {
		ctx.selectedTipPercent = 5;
		storeDef.actions.tipDown();
		expect( ctx.selectedTipPercent ).toBe( 0 );
	} );

	it( 'tipUp from 0 goes to 5', () => {
		ctx.selectedTipPercent = 0;
		storeDef.actions.tipUp();
		expect( ctx.selectedTipPercent ).toBe( 5 );
	} );

	it( 'tipUp at highest (20) stays at 20', () => {
		ctx.selectedTipPercent = 20;
		storeDef.actions.tipUp();
		expect( ctx.selectedTipPercent ).toBe( 20 );
	} );

	it( 'tipDown at lowest (0) stays at 0', () => {
		ctx.selectedTipPercent = 0;
		storeDef.actions.tipDown();
		expect( ctx.selectedTipPercent ).toBe( 0 );
	} );
} );

describe( 'tipPercentLabel', () => {
	it( 'shows percentage with % sign', () => {
		ctx.selectedTipPercent = 10;
		expect( storeDef.callbacks.tipPercentLabel() ).toBe( '10%' );
	} );

	it( 'shows 0% for no tip', () => {
		ctx.selectedTipPercent = 0;
		expect( storeDef.callbacks.tipPercentLabel() ).toBe( '0%' );
	} );
} );

// ── Amount with frequency ──

describe( 'formattedAmountWithFrequency', () => {
	it( 'shows plain amount for one_time', () => {
		ctx.selectedFrequency = 'one_time';
		ctx.selectedAmount = 5000; // $50.00
		const result = storeDef.callbacks.formattedAmountWithFrequency();
		expect( result ).toContain( '50' );
		expect( result ).not.toContain( '/mo' );
	} );

	it( 'strips .00 for whole dollar amounts', () => {
		ctx.selectedFrequency = 'one_time';
		ctx.selectedAmount = 5000; // $50.00 → $50
		const result = storeDef.callbacks.formattedAmountWithFrequency();
		expect( result ).not.toContain( '.00' );
	} );

	it( 'keeps cents for non-whole amounts', () => {
		ctx.selectedFrequency = 'one_time';
		ctx.isCustomAmount = true;
		ctx.customAmountValue = '10.50';
		const result = storeDef.callbacks.formattedAmountWithFrequency();
		expect( result ).toContain( '10.50' );
	} );

	it( 'appends /mo for monthly', () => {
		ctx.selectedFrequency = 'monthly';
		ctx.selectedAmount = 2500;
		const result = storeDef.callbacks.formattedAmountWithFrequency();
		expect( result ).toContain( '/mo' );
	} );

	it( 'appends /three months for quarterly', () => {
		ctx.selectedFrequency = 'quarterly';
		const result = storeDef.callbacks.formattedAmountWithFrequency();
		expect( result ).toContain( '/three months' );
	} );

	it( 'appends /year for annually', () => {
		ctx.selectedFrequency = 'annually';
		const result = storeDef.callbacks.formattedAmountWithFrequency();
		expect( result ).toContain( '/year' );
	} );
} );
