/**
 * Donation form — tribute / dedication tests.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
  createCampaignWithForm,
  deleteCampaign,
  enableTestMode,
} = require( './helpers/campaign-factory' );
const { DonationFormPage } = require( './helpers/donation-form-page' );

test.describe( 'Donation Form: Tribute', () => {
  let campaign, url;

  test.beforeAll( async ( { requestUtils } ) => {
    await enableTestMode( requestUtils );
    ( { campaign, url } = await createCampaignWithForm( requestUtils, {
      tributeEnabled: true,
    } ) );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    await deleteCampaign( requestUtils, campaign.id );
  } );

  test( 'enabling tribute shows the honoree name field', async ( { page } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    // Tribute fields should be hidden initially.
    await expect(
      form.form.locator( '.mission-df-tribute-fields' )
    ).toBeHidden();

    await form.enableTribute();

    // Tribute fields should now be visible.
    await expect(
      form.form.locator( '.mission-df-tribute-fields' )
    ).toBeVisible();

    // Honoree name input should be visible.
    await expect(
      form.form.locator( 'input[id$="honoree-name"]' )
    ).toBeVisible();
  } );

  test( 'tribute requires honoree name to continue', async ( { page } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.selectAmount( '$25' );
    await form.enableTribute();

    // Leave honoree name empty and try to continue.
    await form.clickContinue();

    // Should still be on step 1 with error on honoree field.
    await expect(
      form.form.locator( '.mission-df-step-1.active' )
    ).toBeVisible();
  } );

  test( 'email notification shows recipient name and email fields', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.enableTribute();
    await form.fillHonoreeName( 'Jane Doe' );
    await form.enableNotification();

    // Email fields should be visible (email is default method).
    await expect(
      form.form.locator( 'input[id$="notify-name"]' )
    ).toBeVisible();
    await expect(
      form.form.locator( 'input[id$="notify-email"]' )
    ).toBeVisible();
  } );

  test( 'switching to mail shows address fields', async ( { page } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.enableTribute();
    await form.fillHonoreeName( 'Jane Doe' );
    await form.enableNotification();

    // Switch to mail method.
    await form.form
      .getByRole( 'button', { name: 'Mail', exact: true } )
      .click();

    // Mail panel should be visible (second notify panel).
    await expect(
      form.form.locator( '.mission-df-notify-panel' ).last()
    ).toBeVisible();

    // Email field should be hidden.
    await expect(
      form.form.locator( 'input[id$="notify-email"]' )
    ).toBeHidden();
  } );

  test( 'tribute message field appears when notification is enabled', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.enableTribute();
    await form.fillHonoreeName( 'Jane Doe' );

    // Message should not be visible before notification is enabled.
    await expect(
      form.form.locator( 'textarea[id$="tribute-message"]' )
    ).toBeHidden();

    await form.enableNotification();

    // Message field should now be visible.
    await expect(
      form.form.locator( 'textarea[id$="tribute-message"]' )
    ).toBeVisible();
  } );
} );
