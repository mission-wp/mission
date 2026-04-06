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
    amountsByFrequency: {
      one_time: [ 1000, 2500, 5000, 10000 ],
      monthly: [ 500, 1000, 2500, 5000 ],
      quarterly: [ 1500, 3000, 7500, 15000 ],
      annually: [ 5000, 10000, 25000, 50000 ],
    },
    defaultAmounts: {
      one_time: 2500,
      monthly: 1000,
    },
    selectedAmount: 2500,
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
    stepDirection: 'forward',
    leavingStep: 0,
    isCustomTip: false,
    customTipAmount: 0,
    tipMenuOpen: false,
    commentChecked: false,
    notifyMethod: 'email',
    settings: {
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

  it( 'selectOngoing resets selectedAmount to monthly default', () => {
    storeDef.actions.selectOngoing();
    expect( ctx.selectedAmount ).toBe( 1000 ); // defaultAmounts.monthly
    expect( ctx.isCustomAmount ).toBe( false );
    expect( ctx.customAmountValue ).toBe( '' );
  } );

  it( 'selectOneTime resets to one_time', () => {
    storeDef.actions.selectOngoing();
    storeDef.actions.selectOneTime();
    expect( ctx.isOngoing ).toBe( false );
    expect( ctx.selectedFrequency ).toBe( 'one_time' );
  } );

  it( 'selectOneTime resets selectedAmount to one-time default', () => {
    storeDef.actions.selectOngoing();
    storeDef.actions.selectOneTime();
    expect( ctx.selectedAmount ).toBe( 2500 ); // defaultAmounts['one_time']
  } );

  it( 'selectOngoing closes the dropdown if it was open', () => {
    ctx.frequencyDropdownOpen = true;
    storeDef.actions.selectOngoing();
    expect( ctx.frequencyDropdownOpen ).toBe( false );
  } );

  it( 'selectOngoing clears custom amount state', () => {
    ctx.isCustomAmount = true;
    ctx.customAmountValue = '42.00';
    storeDef.actions.selectOngoing();
    expect( ctx.isCustomAmount ).toBe( false );
    expect( ctx.customAmountValue ).toBe( '' );
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

  it( 'selectRecurringFrequency resets amount for chosen frequency', () => {
    ctx.isOngoing = true;
    ctx.frequency = 'quarterly';
    storeDef.actions.selectRecurringFrequency( fakeEvent );
    // No default for quarterly, falls back to first amount.
    expect( ctx.selectedAmount ).toBe( 1500 ); // quarterly[0]
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
    expect( storeDef.callbacks.selectedFrequencyLabel() ).toBe( 'Quarterly' );
  } );

  it( 'falls back to the raw key if no label exists', () => {
    ctx.selectedFrequency = 'biweekly';
    expect( storeDef.callbacks.selectedFrequencyLabel() ).toBe( 'biweekly' );
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

// ── Tip dropdown ──

describe( 'tip dropdown', () => {
  it( 'toggleTipMenu opens the menu', () => {
    expect( ctx.tipMenuOpen ).toBe( false );
    storeDef.actions.toggleTipMenu( fakeEvent );
    expect( ctx.tipMenuOpen ).toBe( true );
  } );

  it( 'selectTipPercent sets percentage and closes menu', () => {
    ctx.tipMenuOpen = true;
    ctx.tipPercent = 20;
    storeDef.actions.selectTipPercent( fakeEvent );
    expect( ctx.selectedTipPercent ).toBe( 20 );
    expect( ctx.isCustomTip ).toBe( false );
    expect( ctx.tipMenuOpen ).toBe( false );
  } );

  it( 'selectCustomTip enables custom mode and pre-fills at 15%', () => {
    ctx.selectedAmount = 10000; // $100
    storeDef.actions.selectCustomTip( fakeEvent );
    expect( ctx.isCustomTip ).toBe( true );
    expect( ctx.customTipAmount ).toBe( 1500 ); // 15% of 10000
    expect( ctx.selectedTipPercent ).toBe( 0 );
  } );

  it( 'tipCustomUp increments by $1', () => {
    ctx.isCustomTip = true;
    ctx.customTipAmount = 500;
    storeDef.actions.tipCustomUp();
    expect( ctx.customTipAmount ).toBe( 600 );
  } );

  it( 'tipCustomDown decrements by $1 with floor at 0', () => {
    ctx.isCustomTip = true;
    ctx.customTipAmount = 50;
    storeDef.actions.tipCustomDown();
    expect( ctx.customTipAmount ).toBe( 0 );
  } );

  it( 'closeTipMenu sets tipMenuOpen to false', () => {
    ctx.tipMenuOpen = true;
    storeDef.actions.closeTipMenu();
    expect( ctx.tipMenuOpen ).toBe( false );
  } );
} );

describe( 'tipTriggerLabel', () => {
  it( 'shows percentage with % sign', () => {
    ctx.selectedTipPercent = 10;
    expect( storeDef.callbacks.tipTriggerLabel() ).toBe( '10%' );
  } );

  it( 'shows 0% for no tip', () => {
    ctx.selectedTipPercent = 0;
    expect( storeDef.callbacks.tipTriggerLabel() ).toBe( '0%' );
  } );

  it( 'shows calculated percentage for custom tip', () => {
    ctx.isCustomTip = true;
    ctx.customTipAmount = 500;
    ctx.selectedAmount = 5000; // $50
    expect( storeDef.callbacks.tipTriggerLabel() ).toBe( '10%' );
  } );
} );

// ── Amount with frequency ──

describe( 'formattedAmountWithFrequency', () => {
  it( 'shows plain amount for one_time', () => {
    ctx.selectedFrequency = 'one_time';
    ctx.selectedAmount = 5000; // $50.00
    const result = storeDef.callbacks.formattedAmountWithFrequency();
    expect( result ).toContain( '50' );
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
} );

describe( 'frequencySuffix', () => {
  it( 'returns empty string for one_time', () => {
    ctx.selectedFrequency = 'one_time';
    expect( storeDef.callbacks.frequencySuffix() ).toBe( '' );
  } );

  it( 'returns /wk for weekly', () => {
    ctx.selectedFrequency = 'weekly';
    expect( storeDef.callbacks.frequencySuffix() ).toBe( '/wk' );
  } );

  it( 'returns /mo for monthly', () => {
    ctx.selectedFrequency = 'monthly';
    expect( storeDef.callbacks.frequencySuffix() ).toBe( '/mo' );
  } );

  it( 'returns /qtr for quarterly', () => {
    ctx.selectedFrequency = 'quarterly';
    expect( storeDef.callbacks.frequencySuffix() ).toBe( '/qtr' );
  } );

  it( 'returns /yr for annually', () => {
    ctx.selectedFrequency = 'annually';
    expect( storeDef.callbacks.frequencySuffix() ).toBe( '/yr' );
  } );
} );

// ── Multi-currency support ──

describe( 'multi-currency formatting', () => {
  it( 'formats zero-decimal currency (JPY) without decimals', () => {
    ctx.settings.currency = 'JPY';
    ctx.amount = 500;
    const result = storeDef.callbacks.formattedPresetAmount();
    // Should show ¥500 not ¥5.00.
    expect( result ).toContain( '500' );
    expect( result ).not.toContain( '.' );
  } );

  it( 'formats two-decimal currency (USD) with two decimals', () => {
    ctx.settings.currency = 'USD';
    ctx.amount = 1050;
    const result = storeDef.callbacks.formattedPresetAmount();
    expect( result ).toContain( '10.50' );
  } );

  it( 'converts custom amount correctly for zero-decimal currency', () => {
    ctx.settings.currency = 'JPY';
    ctx.isCustomAmount = true;
    ctx.customAmountValue = '500';
    const result = storeDef.callbacks.formattedDonationAmount();
    // 500 JPY should stay as 500, not become 50000.
    expect( result ).toContain( '500' );
    expect( result ).not.toContain( '50,000' );
    expect( result ).not.toContain( '50000' );
  } );

  it( 'shows minimum warning correctly for zero-decimal currency', () => {
    ctx.settings.currency = 'JPY';
    ctx.settings.minimumAmount = 500;
    ctx.isCustomAmount = true;
    ctx.customAmountValue = '300';
    expect( storeDef.callbacks.showMinimumWarning() ).toBe( true );
    ctx.customAmountValue = '500';
    expect( storeDef.callbacks.showMinimumWarning() ).toBe( false );
  } );
} );

// ── Per-frequency amounts ──

describe( 'currentAmounts', () => {
  it( 'returns one-time amounts when frequency is one_time', () => {
    ctx.selectedFrequency = 'one_time';
    expect( storeDef.state.currentAmounts ).toEqual( [
      1000, 2500, 5000, 10000,
    ] );
  } );

  it( 'returns monthly amounts when frequency is monthly', () => {
    ctx.selectedFrequency = 'monthly';
    expect( storeDef.state.currentAmounts ).toEqual( [
      500, 1000, 2500, 5000,
    ] );
  } );

  it( 'returns quarterly amounts when frequency is quarterly', () => {
    ctx.selectedFrequency = 'quarterly';
    expect( storeDef.state.currentAmounts ).toEqual( [
      1500, 3000, 7500, 15000,
    ] );
  } );

  it( 'returns annually amounts when frequency is annually', () => {
    ctx.selectedFrequency = 'annually';
    expect( storeDef.state.currentAmounts ).toEqual( [
      5000, 10000, 25000, 50000,
    ] );
  } );

  it( 'falls back to one-time amounts for unknown frequency', () => {
    ctx.selectedFrequency = 'biweekly';
    expect( storeDef.state.currentAmounts ).toEqual( [
      1000, 2500, 5000, 10000,
    ] );
  } );
} );

describe( 'frequency switching resets amount', () => {
  it( 'switching to ongoing uses monthly default amount', () => {
    ctx.selectedFrequency = 'one_time';
    ctx.selectedAmount = 5000;
    storeDef.actions.selectOngoing();
    expect( ctx.selectedAmount ).toBe( 1000 ); // defaultAmounts.monthly
  } );

  it( 'switching back to one-time uses one-time default amount', () => {
    storeDef.actions.selectOngoing();
    storeDef.actions.selectOneTime();
    expect( ctx.selectedAmount ).toBe( 2500 ); // defaultAmounts['one_time']
  } );

  it( 'falls back to first amount when no default for frequency', () => {
    ctx.frequency = 'annually';
    storeDef.actions.selectRecurringFrequency( fakeEvent );
    expect( ctx.selectedAmount ).toBe( 5000 ); // annually[0], no default set
  } );
} );
