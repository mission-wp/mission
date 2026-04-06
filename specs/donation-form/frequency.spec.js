/**
 * Donation form — frequency selection tests.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
  createCampaignWithForm,
  deleteCampaign,
  enableTestMode,
} = require( './helpers/campaign-factory' );
const { DonationFormPage } = require( './helpers/donation-form-page' );

test.describe( 'Donation Form: Frequency', () => {
  let campaign, url;

  test.beforeAll( async ( { requestUtils } ) => {
    await enableTestMode( requestUtils );
    ( { campaign, url } = await createCampaignWithForm( requestUtils, {
      recurringEnabled: true,
      recurringFrequencies: [ 'monthly', 'quarterly', 'annually' ],
      recurringDefault: 'one_time',
      amountsByFrequency: {
        one_time: [ 1000, 2500, 5000, 10000 ],
        monthly: [ 500, 1000, 2500, 5000 ],
        quarterly: [ 2500, 5000, 10000, 25000 ],
        annually: [ 10000, 25000, 50000, 100000 ],
      },
      defaultAmounts: {
        one_time: 2500,
        monthly: 1000,
        quarterly: 5000,
        annually: 25000,
      },
    } ) );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    await deleteCampaign( requestUtils, campaign.id );
  } );

  test( 'one-time is selected by default', async ( { page } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    // First frequency button (One Time) should be active.
    await expect(
      form.form.locator( '.mission-df-frequency-btn' ).first()
    ).toHaveClass( /active/ );

    // Recurring dropdown should not be visible.
    await expect(
      form.form.locator( '.mission-df-recurring-dropdown' )
    ).toBeHidden();
  } );

  test( 'switching to ongoing shows the frequency dropdown', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.selectOngoing();

    // Second frequency button (Ongoing) should be active.
    await expect(
      form.form.locator( '.mission-df-frequency-btn' ).last()
    ).toHaveClass( /active/ );

    // Frequency dropdown should be visible.
    await expect(
      form.form.locator( '.mission-df-recurring-dropdown' )
    ).toBeVisible();
  } );

  test( 'switching frequency updates the amount buttons', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    // One-time amounts: $10, $25, $50, $100.
    await expect(
      form.form.locator( '.mission-df-amount-btn' ).first()
    ).toContainText( '$10' );

    // Switch to ongoing (defaults to monthly).
    await form.selectOngoing();

    // Monthly amounts: $5, $10, $25, $50.
    await expect(
      form.form.locator( '.mission-df-amount-btn' ).first()
    ).toContainText( '$5' );
  } );

  test( 'switching back to one-time restores original amounts', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.selectOngoing();
    await form.selectOneTime();

    // Should show one-time amounts again.
    await expect(
      form.form.locator( '.mission-df-amount-btn' ).first()
    ).toContainText( '$10' );
  } );
} );
