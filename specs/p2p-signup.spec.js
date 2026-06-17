/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Node dependencies
 */
const { execSync } = require( 'child_process' );

/**
 * Create a peer-to-peer campaign (its default page includes the sign-up modal).
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @return {Promise<{campaign: Object, url: string}>} The created campaign and its URL.
 */
async function createP2PCampaign( requestUtils ) {
  const campaign = await requestUtils.rest( {
    path: '/mission-donation-platform/v1/campaigns',
    method: 'POST',
    data: {
      title: `P2P Signup E2E ${ Date.now() }`,
      type: 'p2p',
      goal_amount: 100000,
    },
  } );

  return { campaign, url: campaign.url || `/?p=${ campaign.post_id }` };
}

/**
 * Read the most recent verification code from the captured outgoing mail.
 *
 * global-setup installs a mu-plugin that stores each wp_mail() in an option;
 * the OTP renders in a distinctive letter-spacing block in the email body.
 *
 * @return {string} The 6-digit code.
 */
function readOtpCode() {
  const raw = execSync(
    'npx wp-env run tests-cli -- wp option get missiondp_e2e_last_mail --format=json',
    { encoding: 'utf8', timeout: 20000 }
  );

  const line = raw
    .split( '\n' )
    .map( ( s ) => s.trim() )
    .filter( Boolean )
    .reverse()
    .find( ( s ) => s.startsWith( '{' ) );

  const message = String( JSON.parse( line ).message || '' );
  const match = message.match( /letter-spacing:\s*8px[^>]*>\s*([0-9]{6})/ );

  if ( ! match ) {
    throw new Error( 'Could not read an OTP code from the captured email.' );
  }

  return match[ 1 ];
}

/**
 * Create a donor with a login account directly, for the existing-account branch.
 *
 * @param {string} email    Account email.
 * @param {string} password Account password.
 */
function createDonorAccount( email, password ) {
  const php =
    `$d = new \\MissionDP\\Models\\Donor( [ "email" => "${ email }", "first_name" => "Existing", "last_name" => "User" ] );` +
    ' $d->save();' +
    ` $d->create_user_account( "${ password }" );`;

  execSync( `npx wp-env run tests-cli -- wp eval '${ php }'`, {
    stdio: 'pipe',
    timeout: 20000,
  } );
}

/**
 * Open the sign-up modal from the campaign page and return its root locator.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          url  Campaign page URL.
 * @return {Promise<import('@playwright/test').Locator>} The modal root.
 */
async function openModal( page, url ) {
  await page.goto( url );
  await page
    .getByRole( 'button', { name: 'Become a Fundraiser' } )
    .first()
    .click();

  const modal = page.locator( '.mission-su' );
  await expect( modal.locator( '.mission-su__overlay' ) ).toHaveClass(
    /is-open/
  );
  return modal;
}

/**
 * Fill the step-1 account form (scoped to the modal).
 *
 * @param {import('@playwright/test').Locator} modal           Modal root locator.
 * @param {Object}                             fields          Account field values.
 * @param {string}                             fields.first    First name.
 * @param {string}                             fields.last     Last name.
 * @param {string}                             fields.email    Email address.
 * @param {string}                             fields.password Password.
 */
async function fillAccount( modal, { first, last, email, password } ) {
  await modal
    .locator( '.mission-su__field input[autocomplete="given-name"]' )
    .fill( first );
  await modal
    .locator( '.mission-su__field input[autocomplete="family-name"]' )
    .fill( last );
  await modal
    .locator( '.mission-su__field input[autocomplete="email"]' )
    .fill( email );
  // The set-new-password view shares autocomplete="new-password"; the account
  // field is the first one in the DOM.
  await modal
    .locator( '.mission-su__field input[autocomplete="new-password"]' )
    .first()
    .fill( password );
}

/**
 * Type a code into the six OTP boxes.
 *
 * @param {import('@playwright/test').Locator} modal
 * @param {string}                             code
 */
async function fillOtp( modal, code ) {
  const boxes = modal.locator( '.mission-su__otp-input' );
  for ( let i = 0; i < 6; i++ ) {
    await boxes.nth( i ).fill( code[ i ] );
  }
}

test.describe( 'Peer-to-peer fundraiser sign-up', () => {
  let campaign;
  let url;

  test.beforeAll( async ( { requestUtils } ) => {
    ( { campaign, url } = await createP2PCampaign( requestUtils ) );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    await requestUtils.rest( {
      path: `/mission-donation-platform/v1/campaigns/${ campaign.id }`,
      method: 'DELETE',
    } );
  } );

  test( 'a new participant verifies by code and reaches the success screen', async ( {
    page,
  } ) => {
    const modal = await openModal( page, url );
    await expect(
      modal.getByRole( 'heading', { name: 'Create your account' } )
    ).toBeVisible();

    await fillAccount( modal, {
      first: 'Pat',
      last: 'Runner',
      email: `e2e-new-${ Date.now() }@example.com`,
      password: 'longenough1',
    } );
    await modal.getByRole( 'button', { name: 'Continue' } ).click();

    // OTP step.
    await expect(
      modal.getByRole( 'heading', { name: 'Verify your email' } )
    ).toBeVisible();
    await fillOtp( modal, readOtpCode() );
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
    const modal = await openModal( page, url );
    await fillAccount( modal, {
      first: 'Wrong',
      last: 'Code',
      email: `e2e-badcode-${ Date.now() }@example.com`,
      password: 'longenough1',
    } );
    await modal.getByRole( 'button', { name: 'Continue' } ).click();

    await expect(
      modal.getByRole( 'heading', { name: 'Verify your email' } )
    ).toBeVisible();

    // A code guaranteed to differ from the real one.
    const real = readOtpCode();
    const wrong = real === '111111' ? '222222' : '111111';
    await fillOtp( modal, wrong );
    await modal.getByRole( 'button', { name: 'Verify' } ).click();

    const error = modal.locator( '.mission-su__error:visible' );
    await expect( error ).toHaveText(
      "That code didn't match. Please try again."
    );
    // Regression: the apostrophe must not be a raw HTML entity.
    await expect( error ).not.toContainText( '&#039;' );
  } );

  test( 'Enter submits each step, but not from the story textarea', async ( {
    page,
  } ) => {
    const modal = await openModal( page, url );
    await fillAccount( modal, {
      first: 'Enter',
      last: 'Key',
      email: `e2e-enter-${ Date.now() }@example.com`,
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
    await fillOtp( modal, readOtpCode() );
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
