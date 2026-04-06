/**
 * Donation form — amount selection tests.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
  createCampaignWithForm,
  deleteCampaign,
  enableTestMode,
} = require( './helpers/campaign-factory' );
const { DonationFormPage } = require( './helpers/donation-form-page' );

test.describe( 'Donation Form: Amount Selection', () => {
  let campaign, url;

  test.beforeAll( async ( { requestUtils } ) => {
    await enableTestMode( requestUtils );
    ( { campaign, url } = await createCampaignWithForm( requestUtils, {
      customAmount: true,
      minimumAmount: 500, // $5.00
    } ) );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    await deleteCampaign( requestUtils, campaign.id );
  } );

  test( 'preset amount buttons are visible and selectable', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    // Default amounts: $10, $25, $50, $100.
    const buttons = form.form.locator(
      '.mission-df-amount-btn:not(.mission-df-amount-btn--other)'
    );
    await expect( buttons ).toHaveCount( 4 );

    // Click $50 button.
    await form.selectAmount( '$50' );
    const selected = await form.getSelectedAmountText();
    expect( selected ).toContain( '$50' );
  } );

  test( 'clicking Other shows the custom amount input', async ( { page } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    // Other button should be visible.
    const otherBtn = form.form.locator( '.mission-df-amount-btn--other' );
    await expect( otherBtn ).toBeVisible();

    // Click it — input should appear.
    await otherBtn.click();
    await expect(
      form.form.locator( '.mission-df-other-field' )
    ).toBeVisible();
  } );

  test( 'entering a custom amount updates the selection', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.enterCustomAmount( '75' );

    // No preset button should be active.
    const activePresets = form.form.locator(
      '.mission-df-amount-btn.active:not(.mission-df-amount-btn--other)'
    );
    await expect( activePresets ).toHaveCount( 0 );

    // Continue should work with this amount.
    await form.clickContinue();
    // Should advance past step 1 (to payment step since no custom fields).
    await expect(
      form.form.locator( '.mission-df-step-2.active' )
    ).toBeVisible();
  } );

  test( 'amount below minimum shows warning and blocks continue', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    // Enter $3 (below $5 minimum).
    await form.enterCustomAmount( '3' );
    await form.clickContinue();

    // Should still be on step 1.
    await expect(
      form.form.locator( '.mission-df-step-1.active' )
    ).toBeVisible();

    // Minimum warning should be shown.
    await expect(
      form.form.locator( '.mission-df-minimum-warning' )
    ).toBeVisible();
  } );

  test( 'default amount is pre-selected on load', async ( { page } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    // Default is $25 (2500 cents).
    const activeBtn = form.form.locator( '.mission-df-amount-btn.active' );
    await expect( activeBtn ).toContainText( '$25' );
  } );
} );
