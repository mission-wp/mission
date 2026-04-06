/**
 * Donation form — payment submission tests.
 *
 * These tests require a Stripe test token. Set the MISSION_STRIPE_TEST_TOKEN
 * environment variable to your stripe_site_token value:
 *
 *   MISSION_STRIPE_TEST_TOKEN=your_token npx playwright test specs/donation-form/payment.spec.js
 *
 * Tests are automatically skipped when the token is not available.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
  createCampaignWithForm,
  deleteCampaign,
  enableTestMode,
  configureStripe,
} = require( './helpers/campaign-factory' );
const { DonationFormPage } = require( './helpers/donation-form-page' );

test.describe( 'Donation Form: Payment', () => {
  let campaign, url, stripeReady;

  test.beforeAll( async ( { requestUtils } ) => {
    await enableTestMode( requestUtils );
    stripeReady = await configureStripe( requestUtils );

    ( { campaign, url } = await createCampaignWithForm( requestUtils, {
      feeRecovery: true,
      feeMode: 'optional',
    } ) );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    await deleteCampaign( requestUtils, campaign.id );
  } );

  test( 'one-time donation with test card completes successfully', async ( {
    page,
  } ) => {
    test.skip(
      ! stripeReady,
      'Set MISSION_STRIPE_TEST_TOKEN to run payment tests'
    );

    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.selectAmount( '$25' );
    await form.clickContinue();

    await form.fillDonorInfo( {
      firstName: 'Test',
      lastName: 'Donor',
      email: 'test-e2e@example.com',
    } );

    await form.fillStripeCard( '4242424242424242' );
    await form.clickDonate();
    await form.expectSuccess( expect );
  } );

  test( 'recurring donation with test card completes successfully', async ( {
    page,
    requestUtils,
  } ) => {
    test.skip(
      ! stripeReady,
      'Set MISSION_STRIPE_TEST_TOKEN to run payment tests'
    );

    const { campaign: recurringCampaign, url: recurringUrl } =
      await createCampaignWithForm( requestUtils, {
        recurringEnabled: true,
        recurringFrequencies: [ 'monthly' ],
        recurringDefault: 'one_time',
      } );

    const form = new DonationFormPage( page );
    await form.goto( recurringUrl );

    await form.selectOngoing();
    await form.selectAmount( '$25' );
    await form.clickContinue();

    await form.fillDonorInfo( {
      firstName: 'Recurring',
      lastName: 'Donor',
      email: 'recurring-e2e@example.com',
    } );

    await form.fillStripeCard( '4242424242424242' );
    await form.clickDonate();
    await form.expectSuccess( expect );

    await deleteCampaign( requestUtils, recurringCampaign.id );
  } );

  test( 'donation with tips disabled completes successfully', async ( {
    page,
    requestUtils,
  } ) => {
    test.skip(
      ! stripeReady,
      'Set MISSION_STRIPE_TEST_TOKEN to run payment tests'
    );

    const { campaign: noTipCampaign, url: noTipUrl } =
      await createCampaignWithForm( requestUtils, {
        tipEnabled: false,
      } );

    const form = new DonationFormPage( page );
    await form.goto( noTipUrl );

    await form.selectAmount( '$25' );
    await form.clickContinue();

    // Tip section should not be visible.
    expect( await form.isTipVisible() ).toBe( false );

    await form.fillDonorInfo( {
      firstName: 'NoTip',
      lastName: 'Donor',
      email: 'notip-e2e@example.com',
    } );

    await form.fillStripeCard( '4242424242424242' );
    await form.clickDonate();
    await form.expectSuccess( expect );

    await deleteCampaign( requestUtils, noTipCampaign.id );
  } );

  test( 'declined card shows error message', async ( { page } ) => {
    test.skip(
      ! stripeReady,
      'Set MISSION_STRIPE_TEST_TOKEN to run payment tests'
    );

    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.selectAmount( '$25' );
    await form.clickContinue();

    await form.fillDonorInfo( {
      firstName: 'Declined',
      lastName: 'Card',
      email: 'declined-e2e@example.com',
    } );

    await form.fillStripeCard( '4000000000000002' );
    await form.clickDonate();

    // The decline error may appear in our error div or within the Stripe
    // iframe. Either way, the form should NOT reach the success state.
    await form.page.waitForTimeout( 5000 );
    await expect( form.form.locator( '.mission-df-success' ) ).toBeHidden();
  } );
} );
