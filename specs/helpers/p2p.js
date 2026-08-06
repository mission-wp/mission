/**
 * WordPress dependencies
 */
const { expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Node dependencies
 */
const { execSync } = require( 'child_process' );

/**
 * Internal dependencies
 */
const { readOtpCode } = require( './mail' );

/**
 * Run a PHP snippet in the test container and return trimmed stdout.
 *
 * @param {string} php PHP code (without opening tag).
 * @return {string} Output.
 */
function wpEval( php ) {
  return execSync(
    `npx wp-env run tests-cli -- wp eval '${ php.replace( /\n/g, ' ' ) }'`,
    { encoding: 'utf8', timeout: 30000, stdio: [ 'pipe', 'pipe', 'pipe' ] }
  ).trim();
}

/**
 * Create a peer-to-peer campaign (its default page includes the sign-up modal).
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {string}                                                      [titlePrefix] Campaign title prefix.
 * @return {Promise<{campaign: Object, url: string}>} The created campaign and its URL.
 */
async function createP2PCampaign( requestUtils, titlePrefix = 'P2P E2E' ) {
  const campaign = await requestUtils.rest( {
    path: '/mission-donation-platform/v1/campaigns',
    method: 'POST',
    data: {
      title: `${ titlePrefix } ${ Date.now() }`,
      type: 'p2p',
      goal_amount: 100000,
    },
  } );

  return { campaign, url: campaign.url || `/?p=${ campaign.post_id }` };
}

/**
 * Remove the fundraisers (with their donor records and login accounts) and
 * teams created on a campaign during sign-up journeys. Campaign deletion does
 * not cascade to these, so specs call this before deleting the campaign.
 *
 * @param {number} campaignId Campaign ID.
 */
function cleanupCampaignParticipants( campaignId ) {
  wpEval(
    `foreach ( \\MissionDP\\Models\\Fundraiser::query( [ "campaign_id" => ${ campaignId }, "per_page" => 100 ] ) as $f ) {` +
      ' $d = $f->donor(); $f->delete();' +
      ' if ( $d ) { if ( $d->user_id ) { wp_delete_user( $d->user_id ); } $d->delete(); } }' +
      ` foreach ( \\MissionDP\\Models\\Team::query( [ "campaign_id" => ${ campaignId }, "per_page" => 100 ] ) as $t ) {` +
      ' foreach ( \\MissionDP\\Models\\TeamInvitation::query( [ "team_id" => $t->id ] ) as $inv ) { $inv->delete(); }' +
      ' $t->delete(); }'
  );
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
 * Clear the p2p endpoints' IP rate limiters (account lookup, send-code, etc.).
 *
 * Sign-up specs run many journeys from one IP inside the limiter windows, so
 * each spec clears the counters first. The limiter's own behavior is covered
 * by PHPUnit.
 */
function clearP2PRateLimits() {
  // AttemptCounter rows: missiondp_attempts_rl_p2p_<action>_<ip hash>.
  wpEval(
    'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \\"%missiondp_attempts_rl_p2p_%\\"" );'
  );
}

/**
 * Set the global Stripe charges toggle.
 *
 * The success step branches on it at render time (first-gift nudge vs the
 * share-only panel), and other spec files flip it, so signup specs pin the
 * state they expect.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {boolean}                                                     enabled      Whether charges are enabled.
 */
async function setChargesEnabled( requestUtils, enabled ) {
  await requestUtils.rest( {
    path: '/mission-donation-platform/v1/settings',
    method: 'POST',
    data: { stripe_charges_enabled: enabled },
  } );
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

/**
 * Complete step 1 for a brand-new user: fill the account form, verify the
 * emailed code, and land on the fundraiser setup step.
 *
 * @param {import('@playwright/test').Locator} modal   Modal root locator.
 * @param {Object}                             account Account fields (see fillAccount).
 */
async function advanceToSetup( modal, account ) {
  await fillAccount( modal, account );
  await modal.getByRole( 'button', { name: 'Continue' } ).click();

  await expect(
    modal.getByRole( 'heading', { name: 'Verify your email' } )
  ).toBeVisible();
  await fillOtp( modal, readOtpCode( account.email ) );
  await modal.getByRole( 'button', { name: 'Verify' } ).click();

  await expect(
    modal.getByRole( 'heading', { name: 'Set up your fundraiser' } )
  ).toBeVisible();
}

/**
 * Log into the donor dashboard through its own login form.
 *
 * Clears the login rate limiter first: it allows 5 attempts per IP per 5
 * minutes, and a full spec run logs in more often than that. The limiter's
 * own behavior is covered by PHPUnit.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          email
 * @param {string}                          password
 */
async function dashboardLogin( page, email, password ) {
  wpEval(
    'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \\"%missiondp_rl_login%\\"" );'
  );

  await page.locator( '#mission-dd-login-email' ).fill( email );
  await page.locator( '#mission-dd-login-password' ).fill( password );
  await page
    .locator(
      'form[data-wp-on--submit="actions.submitLogin"] .mission-dd-auth-submit'
    )
    .click();

  // On success the dashboard re-renders with the sidebar nav.
  await expect( page.locator( '.mission-dd-sidebar' ) ).toBeVisible( {
    timeout: 15000,
  } );
}

module.exports = {
  wpEval,
  createP2PCampaign,
  cleanupCampaignParticipants,
  openModal,
  clearP2PRateLimits,
  setChargesEnabled,
  fillAccount,
  fillOtp,
  advanceToSetup,
  dashboardLogin,
};
