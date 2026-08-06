/* eslint-env jest */

/**
 * Tests for the sign-up modal's first-gift ("kickoff") step.
 *
 * Uses the @wordpress/interactivity mock (mapped via jest.config.js), which
 * merges the view.js and gift.js store definitions like the real API does.
 */

const interactivity = require( '@wordpress/interactivity' );

// Importing the view module pulls in gift.js and populates the merged store.
require( '../view' );
const { prefetchGiftPaymentConfig, teardownGiftStripe } = require( '../gift' );

const { _mockStoreDefinition: storeDef } = interactivity;

/**
 * Drive a generator action to completion, feeding each yielded step the next
 * value from `feed` (in place of awaited promises).
 *
 * @param {Object} generator The running generator.
 * @param {Array}  feed      Values to feed successive yields.
 */
function drive( generator, feed ) {
  let result = generator.next();
  for ( const value of feed ) {
    if ( result.done ) {
      break;
    }
    result = generator.next( value );
  }
  while ( ! result.done ) {
    result = generator.next();
  }
}

const BASE_STATE = {
  isOpen: true,
  currentStep: 3,
  isPending: false,
  kickoffEnabled: true,
  kickoffAmounts: [ 2500, 5000, 10000 ],
  currency: 'USD',
  tipEnabled: true,
  feeRecovery: true,
  feeMode: 'optional',
  stripeFeePercent: 2.9,
  stripeFeeFixed: 30,
  stripeAppearance: {},
  campaignId: 5,
  campaignPostId: 77,
  stripeAccountId: '',
  donorFirstName: 'Jane',
  donorLastName: 'Doe',
  donorEmail: 'jane@example.com',
  successUrl: 'https://example.org/fundraiser/jane',
  fundraiserId: 42,
  step3View: 'nudge',
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
};

beforeEach( () => {
  document.body.innerHTML = `
    <div class="mission-su">
      <div class="mission-su__payment-element"></div>
    </div>`;
  Object.assign( storeDef.state, BASE_STATE );
  interactivity._mockContext = {
    restUrl: 'https://example.org/wp-json/mission-donation-platform/v1/',
    nonce: 'nonce-1',
    i18n: {},
    shareTemplates: { email: 'mailto:?body=%s' },
  };
  global.fetch = jest.fn( () =>
    Promise.resolve( { ok: true, json: () => Promise.resolve( {} ) } )
  );
  // The Stripe holder is module-level; drop it so each test mounts fresh.
  teardownGiftStripe();
} );

afterEach( () => {
  delete global.fetch;
  delete window.Stripe;
} );

describe( 'nudge amount picker', () => {
  it( 'selecting a preset updates the amount and collapses the grid', () => {
    storeDef.state.giftPickerOpen = true;
    interactivity._mockContext.preset = 5000;

    storeDef.actions.selectGiftPreset();

    expect( storeDef.state.giftAmount ).toBe( 5000 );
    expect( storeDef.state.isCustomGift ).toBe( false );
    expect( storeDef.state.giftPickerOpen ).toBe( false );
    expect( storeDef.state.kickoffHeadlineText ).toBe(
      'Give $50 to kick off your page'
    );
  } );

  it( 'choosing Other keeps the grid open and prefills the custom input', () => {
    storeDef.state.giftPickerOpen = true;

    storeDef.actions.chooseOtherGift();

    expect( storeDef.state.isCustomGift ).toBe( true );
    expect( storeDef.state.giftPickerOpen ).toBe( true );
    expect( storeDef.state.customGiftValue ).toBe( '25' );
  } );

  it( 'a custom amount drives the headline', () => {
    storeDef.actions.chooseOtherGift();
    storeDef.actions.updateCustomGift( { target: { value: '12.34' } } );

    expect( storeDef.state.kickoffHeadlineText ).toBe(
      'Give $12.34 to kick off your page'
    );
  } );

  it( 'blurring an empty custom amount reverts to the preset button', () => {
    storeDef.actions.chooseOtherGift();
    storeDef.actions.updateCustomGift( { target: { value: '' } } );
    storeDef.actions.blurCustomGift();

    expect( storeDef.state.isCustomGift ).toBe( false );
    expect( storeDef.state.kickoffHeadlineText ).toBe(
      'Give $25 to kick off your page'
    );

    // A valid value survives the blur.
    storeDef.actions.chooseOtherGift();
    storeDef.actions.updateCustomGift( { target: { value: '40' } } );
    storeDef.actions.blurCustomGift();
    expect( storeDef.state.isCustomGift ).toBe( true );
  } );
} );

describe( 'totals', () => {
  it( 'computes amount + fee + tip for the submit label', () => {
    // $25 gift: fee $1.06 (2.9% + 30¢ grossed up), tip 15% = $3.75.
    expect( storeDef.state.giftFeeDisplay ).toBe( '$1.06' );
    expect( storeDef.state.giftSubmitLabel ).toBe( 'Donate $29.81 & launch' );
  } );

  it( 'unchecking fee coverage removes the fee from the total only', () => {
    storeDef.actions.toggleGiftFeeCovered( { target: { checked: false } } );

    expect( storeDef.state.giftSubmitLabel ).toBe( 'Donate $28.75 & launch' );
    // The struck-through fee line still shows the fee value.
    expect( storeDef.state.giftFeeDisplay ).toBe( '$1.06' );
  } );

  it( 'covers the platform rate when tips are disabled', () => {
    storeDef.state.tipEnabled = false;

    // 5.9% combined rate + 30¢ grossed up on $25.
    expect( storeDef.state.giftFeeDisplay ).toBe( '$1.89' );
    expect( storeDef.state.giftSubmitLabel ).toBe( 'Donate $26.89 & launch' );
  } );

  it( 'custom tip feeds the total and the trigger label', () => {
    storeDef.actions.selectGiftCustomTip();
    expect( storeDef.state.customGiftTipAmount ).toBe( 375 );

    storeDef.actions.giftTipUp();
    expect( storeDef.state.customGiftTipAmount ).toBe( 475 );
    expect( storeDef.state.giftTipTriggerLabel ).toBe( '19%' );

    storeDef.actions.updateGiftCustomTip( { target: { value: '2' } } );
    expect( storeDef.state.customGiftTipAmount ).toBe( 200 );
    expect( storeDef.state.giftSubmitLabel ).toBe( 'Donate $28.06 & launch' );
  } );
} );

describe( 'sub-view gating', () => {
  it( 'shows the nudge only for active fundraisers with charges enabled', () => {
    expect( storeDef.state.isNudgeView ).toBe( true );

    storeDef.state.isPending = true;
    expect( storeDef.state.isNudgeView ).toBe( false );
    expect( storeDef.state.isGiftPaymentView ).toBe( false );

    storeDef.state.isPending = false;
    storeDef.state.kickoffEnabled = false;
    expect( storeDef.state.isNudgeView ).toBe( false );
  } );

  it( 'back link returns to the nudge and clears errors', () => {
    storeDef.state.step3View = 'payment';
    storeDef.state.giftError = 'nope';

    storeDef.actions.backToNudge();

    expect( storeDef.state.step3View ).toBe( 'nudge' );
    expect( storeDef.state.giftError ).toBe( '' );
  } );
} );

describe( 'registration wiring', () => {
  it( 'submit() captures the fundraiser id and resets to the nudge', () => {
    storeDef.state.step3View = 'success';
    storeDef.state.giftAmount = 10000;
    storeDef.state.currentStep = 2;
    storeDef.state.fundraiserId = 0;

    const generator = storeDef.actions.submit();
    drive( generator, [
      { ok: true, json: jest.fn() },
      {
        fundraiser: {
          id: 9,
          url: 'https://example.org/fundraiser/new',
          status: 'active',
        },
        team: null,
      },
    ] );

    expect( storeDef.state.fundraiserId ).toBe( 9 );
    expect( storeDef.state.currentStep ).toBe( 3 );
    expect( storeDef.state.step3View ).toBe( 'nudge' );
    expect( storeDef.state.giftAmount ).toBe( 2500 );
    // The payment config is prefetched for the payment sub-view.
    expect( global.fetch ).toHaveBeenCalledWith(
      expect.stringContaining( 'donations/payment-config' )
    );
  } );

  it( 'continueAccount() marks the typed identity as signed in', () => {
    Object.assign( storeDef.state, {
      signedIn: false,
      firstName: ' Sam ',
      lastName: 'Lee',
      email: 'sam@example.com',
      password: 'secret',
      donorFirstName: '',
      donorLastName: '',
      donorEmail: '',
    } );

    const generator = storeDef.actions.continueAccount();
    drive( generator, [
      { ok: true, json: jest.fn() },
      { branch: 'authenticated', nonce: 'nonce-2' },
    ] );

    expect( storeDef.state.signedIn ).toBe( true );
    expect( storeDef.state.donorFirstName ).toBe( 'Sam' );
    expect( storeDef.state.donorLastName ).toBe( 'Lee' );
    expect( storeDef.state.donorEmail ).toBe( 'sam@example.com' );
    expect( storeDef.state.currentStep ).toBe( 2 );
    expect( interactivity._mockContext.nonce ).toBe( 'nonce-2' );
  } );
} );

describe( 'payment flow', () => {
  /**
   * Stub window.Stripe and mount the payment element via showGiftPayment.
   *
   * @return {Object} The stubbed stripe/elements/paymentElement objects.
   */
  function mountStubbedElement() {
    const paymentElement = {
      mount: jest.fn(),
      on: jest.fn(),
    };
    const elements = {
      create: jest.fn( () => paymentElement ),
      update: jest.fn(),
      submit: jest.fn( () => Promise.resolve( {} ) ),
    };
    const stripe = {
      elements: jest.fn( () => elements ),
      confirmPayment: jest.fn( () => Promise.resolve( {} ) ),
    };
    window.Stripe = jest.fn( () => stripe );
    storeDef.state.stripePublishableKey = 'pk_test_1';

    const generator = storeDef.actions.showGiftPayment();
    drive( generator, [ 'acct_1' ] );

    return { stripe, elements, paymentElement };
  }

  it( 'mounts the payment element with hidden name/email fields', () => {
    const { stripe, elements, paymentElement } = mountStubbedElement();

    expect( storeDef.state.step3View ).toBe( 'payment' );
    expect( window.Stripe ).toHaveBeenCalledWith( 'pk_test_1', {
      stripeAccount: 'acct_1',
      locale: 'auto',
    } );
    expect( stripe.elements ).toHaveBeenCalledWith(
      expect.objectContaining( {
        mode: 'payment',
        amount: 2981,
        currency: 'usd',
        paymentMethodTypes: [ 'card' ],
      } )
    );
    expect( elements.create ).toHaveBeenCalledWith(
      'payment',
      expect.objectContaining( {
        fields: {
          billingDetails: { name: 'never', email: 'never', address: 'auto' },
        },
        wallets: { link: 'never' },
      } )
    );
    expect( paymentElement.mount ).toHaveBeenCalled();
  } );

  it( 'overlapping showGiftPayment calls mount a single payment element', () => {
    const paymentElement = { mount: jest.fn(), on: jest.fn() };
    const elements = {
      create: jest.fn( () => paymentElement ),
      update: jest.fn(),
    };
    const stripe = { elements: jest.fn( () => elements ) };
    window.Stripe = jest.fn( () => stripe );
    storeDef.state.stripePublishableKey = 'pk_test_1';

    const first = storeDef.actions.showGiftPayment();
    first.next(); // Suspends on the payment-config yield.

    // A second click while the config fetch is pending bails on the guard.
    const second = storeDef.actions.showGiftPayment();
    expect( second.next().done ).toBe( true );

    first.next( 'acct_1' );

    expect( window.Stripe ).toHaveBeenCalledTimes( 1 );
    expect( paymentElement.mount ).toHaveBeenCalledTimes( 1 );
  } );

  it( 'does not mount when the modal closes before the config resolves', () => {
    const paymentElement = { mount: jest.fn(), on: jest.fn() };
    const stripe = {
      elements: jest.fn( () => ( {
        create: jest.fn( () => paymentElement ),
        update: jest.fn(),
      } ) ),
    };
    window.Stripe = jest.fn( () => stripe );
    storeDef.state.stripePublishableKey = 'pk_test_1';

    const generator = storeDef.actions.showGiftPayment();
    generator.next(); // Suspends on the payment-config yield.
    storeDef.state.isOpen = false; // Donor dismissed the modal mid-fetch.
    generator.next( 'acct_1' );

    expect( window.Stripe ).not.toHaveBeenCalled();

    // The guard is released, so reopening can mount normally.
    storeDef.state.isOpen = true;
    const retry = storeDef.actions.showGiftPayment();
    retry.next();
    retry.next( 'acct_1' );
    expect( window.Stripe ).toHaveBeenCalledTimes( 1 );
  } );

  it( 'retries the payment-config fetch after a failed prefetch', async () => {
    global.fetch = jest.fn( () => Promise.resolve( { ok: false } ) );
    await prefetchGiftPaymentConfig( interactivity._mockContext );

    const paymentElement = { mount: jest.fn(), on: jest.fn() };
    const elements = {
      create: jest.fn( () => paymentElement ),
      update: jest.fn(),
    };
    const stripe = { elements: jest.fn( () => elements ) };
    window.Stripe = jest.fn( () => stripe );
    storeDef.state.stripePublishableKey = 'pk_test_1';

    const generator = storeDef.actions.showGiftPayment();
    // The failed prefetch must not have been cached: entering the payment
    // view kicks off a fresh fetch.
    generator.next();
    generator.next( 'acct_2' );

    expect( global.fetch ).toHaveBeenCalledTimes( 2 );
    expect( paymentElement.mount ).toHaveBeenCalled();
  } );

  it( 'submitGift posts the standard donation body and shows the thanks view', () => {
    const { stripe } = mountStubbedElement();

    const generator = storeDef.actions.submitGift();
    drive( generator, [
      {}, // elements.submit() result.
      { ok: true, json: () => Promise.resolve( {} ) }, // create-payment-intent response.
      { client_secret: 'pi_123_secret_abc', transaction_id: 31 }, // its json.
      {}, // confirmPayment result.
      { ok: true, status: 200 }, // donations/confirm response.
    ] );

    const createCall = global.fetch.mock.calls.find( ( [ url ] ) =>
      url.includes( 'donations/create-payment-intent' )
    );
    expect( createCall ).toBeTruthy();
    expect( JSON.parse( createCall[ 1 ].body ) ).toEqual( {
      donation_amount: 2606,
      tip_amount: 375,
      fee_amount: 106,
      fee_mode: 'tip',
      donor_email: 'jane@example.com',
      donor_first_name: 'Jane',
      donor_last_name: 'Doe',
      frequency: 'one_time',
      campaign_id: 5,
      fundraiser_id: 42,
      team_id: 0,
      source_post_id: 77,
      form_id: 'p2p-kickoff',
      stripe_account_id: '',
      is_anonymous: false,
    } );

    expect( stripe.confirmPayment ).toHaveBeenCalledWith(
      expect.objectContaining( {
        clientSecret: 'pi_123_secret_abc',
        redirect: 'if_required',
        confirmParams: expect.objectContaining( {
          payment_method_data: {
            billing_details: {
              name: 'Jane Doe',
              email: 'jane@example.com',
            },
          },
        } ),
      } )
    );

    const confirmCall = global.fetch.mock.calls.find( ( [ url ] ) =>
      url.includes( 'donations/confirm' )
    );
    expect( JSON.parse( confirmCall[ 1 ].body ) ).toEqual( {
      transaction_id: 31,
      payment_intent_id: 'pi_123',
    } );

    expect( storeDef.state.step3View ).toBe( 'success' );
    expect( storeDef.state.isSubmittingGift ).toBe( false );
    expect( storeDef.state.giftSuccessText ).toBe(
      'Your $25 gift is in. Your page is off to a great start, so keep the momentum going by sharing it:'
    );
  } );

  it( 'shows the slow notice while a 3DS confirm is pending, then clears it', async () => {
    mountStubbedElement();
    jest.useFakeTimers();

    try {
      const generator = storeDef.actions.submitGift();
      generator.next(); // Runs to the elements.submit() yield.
      generator.next( {} ); // -> create-payment-intent yield.
      generator.next( { ok: true, json: () => Promise.resolve( {} ) } );
      generator.next( { client_secret: 'pi_1_secret_x', transaction_id: 1 } );
      // The generator is now suspended on confirmPayment (a 3DS challenge the
      // donor can sit on); flush microtasks so the pre-charge deadline timers
      // have been cleared before advancing the clock.
      await Promise.resolve();

      expect( storeDef.state.giftTakingLong ).toBe( false );
      jest.advanceTimersByTime( 30000 );
      expect( storeDef.state.giftTakingLong ).toBe( true );

      generator.next( {} ); // Confirm resolves; the finally clears the notice.
      expect( storeDef.state.giftTakingLong ).toBe( false );

      generator.next( { ok: true, status: 200 } ); // donations/confirm.
      generator.next();
      expect( storeDef.state.step3View ).toBe( 'success' );
    } finally {
      jest.useRealTimers();
    }
  } );

  it( 'a failed create-payment-intent surfaces the message and re-enables', () => {
    mountStubbedElement();

    const generator = storeDef.actions.submitGift();
    drive( generator, [
      {},
      { ok: false, json: () => Promise.resolve( {} ) },
      { message: 'Amount is below the minimum.' },
    ] );

    expect( storeDef.state.giftError ).toBe( 'Amount is below the minimum.' );
    expect( storeDef.state.isSubmittingGift ).toBe( false );
    expect( storeDef.state.step3View ).toBe( 'payment' );
  } );
} );

describe( 'tip menu', () => {
  it( 'marks the selected percentage active until a custom tip takes over', () => {
    interactivity._mockContext.tipPercent = 15;
    expect( storeDef.callbacks.isGiftTipOptionActive() ).toBe( true );

    interactivity._mockContext.tipPercent = 20;
    expect( storeDef.callbacks.isGiftTipOptionActive() ).toBe( false );

    interactivity._mockContext.tipPercent = 15;
    storeDef.actions.selectGiftCustomTip();
    expect( storeDef.callbacks.isGiftTipOptionActive() ).toBe( false );
  } );
} );

describe( 'email share', () => {
  it( 'navigates to the mailto intent with the fundraiser URL', () => {
    const original = window.location;
    delete window.location;
    window.location = { href: 'https://example.org/campaign' };

    storeDef.actions.shareEmail();

    expect( window.location.href ).toBe(
      `mailto:?body=${ encodeURIComponent(
        'https://example.org/fundraiser/jane'
      ) }`
    );
    window.location = original;
  } );
} );
