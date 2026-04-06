/**
 * Donation form — donor info and validation tests.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
  createCampaignWithForm,
  deleteCampaign,
  enableTestMode,
} = require( './helpers/campaign-factory' );
const { DonationFormPage } = require( './helpers/donation-form-page' );

test.describe( 'Donation Form: Donor Info', () => {
  let campaign, url;

  test.beforeAll( async ( { requestUtils } ) => {
    await enableTestMode( requestUtils );
    ( { campaign, url } = await createCampaignWithForm( requestUtils ) );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    await deleteCampaign( requestUtils, campaign.id );
  } );

  test( 'advancing to payment step shows donor fields', async ( { page } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.selectAmount( '$25' );
    await form.clickContinue();

    // Donor fields should be visible.
    await expect(
      form.form.locator( 'input[id$="first-name"]' )
    ).toBeVisible();
    await expect( form.form.locator( 'input[id$="last-name"]' ) ).toBeVisible();
    await expect( form.form.locator( 'input[id$="email"]' ) ).toBeVisible();
  } );

  test( 'donor fields are empty by default and required', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.selectAmount( '$25' );
    await form.clickContinue();

    // Fields should be empty.
    const firstName = form.form.locator( 'input[id$="first-name"]' );
    const email = form.form.locator( 'input[id$="email"]' );

    await expect( firstName ).toHaveValue( '' );
    await expect( email ).toHaveValue( '' );

    // Fields should have the required attribute.
    await expect( firstName ).toHaveAttribute( 'required', '' );
    await expect( email ).toHaveAttribute( 'required', '' );
  } );

  test( 'back button returns to amount step', async ( { page } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.selectAmount( '$50' );
    await form.clickContinue();

    // Should be on payment step.
    await expect(
      form.form.locator( '.mission-df-step-2.active' )
    ).toBeVisible();

    // Click back.
    await form.clickBack();

    // Should be on amount step again.
    await expect(
      form.form.locator( '.mission-df-step-1.active' )
    ).toBeVisible();

    // Previously selected amount should still be active.
    const activeBtn = form.form.locator( '.mission-df-amount-btn.active' );
    await expect( activeBtn ).toContainText( '$50' );
  } );
} );
