/**
 * Donation form — recurring-only forms (one-time switched off).
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
  createCampaignWithForm,
  deleteCampaign,
  enableTestMode,
} = require( './helpers/campaign-factory' );
const { snapshotSettings } = require( '../helpers/settings' );
const { DonationFormPage } = require( './helpers/donation-form-page' );

test.describe( 'Donation Form: Recurring only', () => {
  let campaign, url, settingsSnapshot;

  test.beforeAll( async ( { requestUtils } ) => {
    settingsSnapshot = await snapshotSettings( requestUtils, [
      'test_mode',
      'stripe_charges_enabled',
    ] );
    await enableTestMode( requestUtils );
    ( { campaign, url } = await createCampaignWithForm( requestUtils, {
      recurringEnabled: true,
      oneTimeEnabled: false,
      recurringFrequencies: [ 'monthly', 'annually' ],
      // Deliberately stale: a recurring-only form must ignore a one-time default.
      recurringDefault: 'one_time',
      amountsByFrequency: {
        one_time: [ 1000, 2500, 5000, 10000 ],
        monthly: [ 500, 1000, 2500, 5000 ],
        annually: [ 10000, 25000, 50000, 100000 ],
      },
      defaultAmounts: {
        one_time: 2500,
        monthly: 1000,
        annually: 25000,
      },
    } ) );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    await deleteCampaign( requestUtils, campaign.id );
    await settingsSnapshot.restore();
  } );

  test( 'offers no one-time option and starts on monthly', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    await expect(
      form.form.locator( '.mission-df-frequency-toggle' )
    ).toHaveCount( 0 );
    await expect(
      form.form.locator( '.mission-df-recurring-dropdown' )
    ).toBeVisible();
    await expect(
      form.form.locator( '.mission-df-recurring-trigger' )
    ).toContainText( 'Monthly' );

    // Monthly amounts ($5, $10, $25, $50), not the one-time ones.
    await expect(
      form.form.locator( '.mission-df-amount-btn' ).first()
    ).toContainText( '$5' );
  } );

  test( 'switching frequency updates the amount buttons', async ( {
    page,
  } ) => {
    const form = new DonationFormPage( page );
    await form.goto( url );

    await form.form.locator( '.mission-df-recurring-trigger' ).click();
    await form.form
      .locator( '.mission-df-recurring-option' )
      .filter( { hasText: 'Annually' } )
      .click();

    await expect(
      form.form.locator( '.mission-df-recurring-trigger' )
    ).toContainText( 'Annually' );
    await expect(
      form.form.locator( '.mission-df-amount-btn' ).first()
    ).toContainText( '$100' );
  } );
} );
