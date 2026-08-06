/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { readOtpCode } = require( './helpers/mail' );
const {
  wpEval,
  createP2PCampaign,
  cleanupCampaignParticipants,
  openModal,
  clearP2PRateLimits,
  setChargesEnabled,
  fillAccount,
  fillOtp,
} = require( './helpers/p2p' );
const { snapshotSettings } = require( './helpers/settings' );

/**
 * Create a donor with a login account directly, for the existing-account branch.
 *
 * @param {string} email    Account email.
 * @param {string} password Account password.
 */
function createDonorAccount( email, password ) {
  wpEval(
    `$d = new \\MissionDP\\Models\\Donor( [ "email" => "${ email }", "first_name" => "Existing", "last_name" => "User" ] );` +
      ' $d->save();' +
      ` $d->create_user_account( "${ password }" );`
  );
}

test.describe( 'Peer-to-peer fundraiser sign-up', () => {
  let campaign;
  let url;
  let settingsSnapshot;

  test.beforeAll( async ( { requestUtils } ) => {
    clearP2PRateLimits();
    // These specs cover the share-only success screen; the first-gift nudge
    // (charges enabled) is covered by p2p-signup-first-gift.spec.js.
    settingsSnapshot = await snapshotSettings( requestUtils, [
      'stripe_charges_enabled',
    ] );
    await setChargesEnabled( requestUtils, false );

    ( { campaign, url } = await createP2PCampaign(
      requestUtils,
      'P2P Signup E2E'
    ) );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    // Remove the participants created during sign-up (their fundraiser pages,
    // donor records, and login accounts) before deleting the campaign, since
    // campaign deletion does not cascade to fundraisers.
    cleanupCampaignParticipants( campaign.id );

    await requestUtils.rest( {
      path: `/mission-donation-platform/v1/campaigns/${ campaign.id }`,
      method: 'DELETE',
    } );

    await settingsSnapshot.restore();
  } );

  test( 'a new participant verifies by code and reaches the success screen', async ( {
    page,
  } ) => {
    const email = `e2e-new-${ Date.now() }@example.com`;
    const modal = await openModal( page, url );
    await expect(
      modal.getByRole( 'heading', { name: 'Create your account' } )
    ).toBeVisible();

    await fillAccount( modal, {
      first: 'Pat',
      last: 'Runner',
      email,
      password: 'longenough1',
    } );
    await modal.getByRole( 'button', { name: 'Continue' } ).click();

    // OTP step.
    await expect(
      modal.getByRole( 'heading', { name: 'Verify your email' } )
    ).toBeVisible();
    await fillOtp( modal, readOtpCode( email ) );
    await modal.getByRole( 'button', { name: 'Verify' } ).click();

    // Fundraiser setup.
    await expect(
      modal.getByRole( 'heading', { name: 'Set up your fundraiser' } )
    ).toBeVisible();
    await modal.locator( '.mission-su__field textarea' ).fill( 'Helping out.' );
    await modal.getByRole( 'button', { name: 'Create fundraiser' } ).click();

    // Success — the register call (with the post-login nonce) succeeded.
    await expect(
      modal.getByRole( 'heading', { name: "You're a fundraiser!" } )
    ).toBeVisible();
    await expect(
      modal.getByRole( 'link', { name: 'View my page' } )
    ).toBeVisible();
  } );

  test( 'an existing account logs in and registers without a code', async ( {
    page,
  } ) => {
    const email = `e2e-existing-${ Date.now() }@example.com`;
    createDonorAccount( email, 'longenough1' );

    const modal = await openModal( page, url );
    await fillAccount( modal, {
      first: 'Existing',
      last: 'User',
      email,
      password: 'longenough1',
    } );
    await modal.getByRole( 'button', { name: 'Continue' } ).click();

    // Goes straight to setup (no OTP), then registers under the session.
    await expect(
      modal.getByRole( 'heading', { name: 'Set up your fundraiser' } )
    ).toBeVisible();
    await modal.locator( '.mission-su__field textarea' ).fill( 'Back again.' );
    await modal.getByRole( 'button', { name: 'Create fundraiser' } ).click();

    await expect(
      modal.getByRole( 'heading', { name: "You're a fundraiser!" } )
    ).toBeVisible();
  } );

  test( 'clicking outside the modal does not close it, but the X does', async ( {
    page,
  } ) => {
    const modal = await openModal( page, url );
    const overlay = modal.locator( '.mission-su__overlay' );

    // Click the backdrop, clear of both the centered dialog and the admin bar.
    await overlay.click( { position: { x: 20, y: 300 } } );
    await expect( overlay ).toHaveClass( /is-open/ );

    await modal.getByRole( 'button', { name: 'Close' } ).click();
    await expect( overlay ).not.toHaveClass( /is-open/ );
  } );

  test( 'an invalid code shows a correctly rendered error', async ( {
    page,
  } ) => {
    const email = `e2e-badcode-${ Date.now() }@example.com`;
    const modal = await openModal( page, url );
    await fillAccount( modal, {
      first: 'Wrong',
      last: 'Code',
      email,
      password: 'longenough1',
    } );
    await modal.getByRole( 'button', { name: 'Continue' } ).click();

    await expect(
      modal.getByRole( 'heading', { name: 'Verify your email' } )
    ).toBeVisible();

    // A code guaranteed to differ from the real one.
    const real = readOtpCode( email );
    const wrong = real === '111111' ? '222222' : '111111';
    await fillOtp( modal, wrong );
    await modal.getByRole( 'button', { name: 'Verify' } ).click();

    const error = modal.locator( '.mission-su__error:visible' );
    await expect( error ).toHaveText(
      "That code didn't work. Request a new one and try again."
    );
    // Regression: the apostrophe must not be a raw HTML entity.
    await expect( error ).not.toContainText( '&#039;' );
  } );

  test( 'Enter submits each step, but not from the story textarea', async ( {
    page,
  } ) => {
    const email = `e2e-enter-${ Date.now() }@example.com`;
    const modal = await openModal( page, url );
    await fillAccount( modal, {
      first: 'Enter',
      last: 'Key',
      email,
      password: 'longenough1',
    } );

    // Enter from the password field advances (Continue).
    await modal
      .locator( '.mission-su__field input[autocomplete="new-password"]' )
      .first()
      .press( 'Enter' );
    await expect(
      modal.getByRole( 'heading', { name: 'Verify your email' } )
    ).toBeVisible();

    // Enter from an OTP box verifies.
    await fillOtp( modal, readOtpCode( email ) );
    await modal.locator( '.mission-su__otp-input' ).last().press( 'Enter' );
    await expect(
      modal.getByRole( 'heading', { name: 'Set up your fundraiser' } )
    ).toBeVisible();

    // Enter inside the story textarea must NOT submit.
    const story = modal.locator( '.mission-su__field textarea' );
    await story.click();
    await story.press( 'Enter' );
    await expect(
      modal.getByRole( 'heading', { name: 'Set up your fundraiser' } )
    ).toBeVisible();

    // Enter from the goal field submits (Create fundraiser).
    await modal
      .locator( '.mission-su__field input[type="number"]' )
      .press( 'Enter' );
    await expect(
      modal.getByRole( 'heading', { name: "You're a fundraiser!" } )
    ).toBeVisible();
  } );
} );
