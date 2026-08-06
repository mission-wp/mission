/**
 * Donation form — fee recovery tests.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
  createCampaignWithForm,
  deleteCampaign,
  enableTestMode,
} = require( './helpers/campaign-factory' );
const { snapshotSettings } = require( '../helpers/settings' );
const { DonationFormPage } = require( './helpers/donation-form-page' );

test.describe( 'Donation Form: Fee Recovery', () => {
  let campaign, url, settingsSnapshot;

  test.beforeAll( async ( { requestUtils } ) => {
    settingsSnapshot = await snapshotSettings( requestUtils, [
      'test_mode',
      'stripe_charges_enabled',
    ] );
    await enableTestMode( requestUtils );
    ( { campaign, url } = await createCampaignWithForm( requestUtils, {
      feeRecovery: true,
      feeMode: 'optional',
    } ) );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    await deleteCampaign( requestUtils, campaign.id );
    await settingsSnapshot.restore();
  } );

  test( 'fee recovery section is visible on the payment step', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );
    await form.selectAmount( '$25' );
    await form.clickContinue();

    // Should be on payment step with fee recovery visible.
    await expect(
      form.form.locator( '.mission-df-fee-recovery' )
    ).toBeVisible();
  } );

  test( 'toggling fee recovery updates the checkbox state', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );
    await form.selectAmount( '$25' );
    await form.clickContinue();

    // Fee amount text should be visible (fee is covered by default).
    const feeText = form.form.locator( '.mission-df-fee-amount-text' );
    await expect( feeText ).toBeVisible();

    // Toggle off — expand details panel and uncheck.
    await form.toggleFeeRecovery();

    const checkbox = form.form
      .locator( '.mission-df-fee-details' )
      .locator( 'input[type="checkbox"]' );
    await expect( checkbox ).not.toBeChecked();
  } );

  test( 'fee details toggle shows breakdown', async ( { page } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );
    await form.selectAmount( '$50' );
    await form.clickContinue();

    // Click the fee details toggle.
    const detailsToggle = form.form.locator( '.mission-df-fee-edit' );
    if ( await detailsToggle.isVisible() ) {
      await detailsToggle.click();
      await expect(
        form.form.locator( '.mission-df-fee-details' )
      ).toBeVisible();
    }
  } );
} );
