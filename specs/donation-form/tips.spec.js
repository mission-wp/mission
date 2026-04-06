/**
 * Donation form — tip selection tests.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
  createCampaignWithForm,
  deleteCampaign,
  enableTestMode,
} = require( './helpers/campaign-factory' );
const { DonationFormPage } = require( './helpers/donation-form-page' );

test.describe( 'Donation Form: Tips', () => {
  let campaign, url;

  test.beforeAll( async ( { requestUtils } ) => {
    await enableTestMode( requestUtils );
    ( { campaign, url } = await createCampaignWithForm( requestUtils ) );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    await deleteCampaign( requestUtils, campaign.id );
  } );

  test( 'tip section is visible on the payment step', async ( { page } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );
    await form.selectAmount( '$25' );
    await form.clickContinue();

    await expect( form.form.locator( '.mission-df-tip' ) ).toBeVisible();
  } );

  test( 'selecting a tip percentage updates the display', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );
    await form.selectAmount( '$50' );
    await form.clickContinue();

    await form.selectTip( 15 );

    // The tip trigger should show the selected tip info.
    const trigger = form.form.locator( '.mission-df-tip-trigger' );
    const text = await trigger.textContent();
    expect( text ).toContain( '15%' );
  } );

  test( 'entering $0 custom tip removes the tip', async ( { page } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );
    await form.selectAmount( '$25' );
    await form.clickContinue();

    // First select a tip.
    await form.selectTip( 10 );

    // Enter $0 as a custom tip.
    await form.enterCustomTip( '0' );

    // The custom tip input should be visible with value 0.
    await expect(
      form.form.locator( '.mission-df-tip-custom-input' )
    ).toHaveValue( '0.00' );
  } );
} );
