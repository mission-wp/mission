/**
 * Peer-to-peer sign-up — first-gift nudge on the success step.
 *
 * The paying test requires Stripe credentials (MISSIONDP_STRIPE_TEST_TOKEN +
 * MISSIONDP_STRIPE_ACCOUNT_ID in .env) and is skipped without them; the
 * nudge/amount/fee UI tests run everywhere.
 */

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
  enableTestMode,
  configureStripe,
} = require( './donation-form/helpers/campaign-factory' );
const {
  wpEval,
  createP2PCampaign,
  cleanupCampaignParticipants,
  openModal,
  clearP2PRateLimits,
  advanceToSetup,
} = require( './helpers/p2p' );

/**
 * Complete the sign-up through "Create fundraiser" and land on the nudge.
 *
 * @param {import('@playwright/test').Locator} modal   Modal root locator.
 * @param {Object}                             account Account fields (see fillAccount).
 */
async function signUp( modal, account ) {
  await advanceToSetup( modal, account );
  await modal.locator( '.mission-su__field textarea' ).fill( 'First gift.' );
  await modal.getByRole( 'button', { name: 'Create fundraiser' } ).click();
  await expect(
    modal.getByRole( 'heading', { name: "You're a fundraiser!" } )
  ).toBeVisible();
}

/**
 * Fill the Stripe Payment Element with a test card.
 *
 * Duplicates the iframe technique from the donation-form helper so this spec
 * never touches that suite's files.
 *
 * @param {import('@playwright/test').Page}    page
 * @param {import('@playwright/test').Locator} modal Modal root locator.
 * @param {string}                             card  Card number.
 */
async function fillGiftCard( page, modal, card = '4242424242424242' ) {
  await modal
    .locator( '.mission-su__payment-element' )
    .waitFor( { state: 'visible' } );

  const cardFrame = page
    .frameLocator( 'iframe[name*="__privateStripeFrame"]' )
    .first();

  const cardInput = cardFrame.locator(
    '[name="cardnumber"], [name="number"], [autocomplete="cc-number"]'
  );
  await cardInput.waitFor( { state: 'visible', timeout: 15000 } );
  await cardInput.fill( card );

  const expiryInput = cardFrame.locator(
    '[name="exp-date"], [name="expiry"], [autocomplete="cc-exp"]'
  );
  if ( await expiryInput.isVisible() ) {
    await expiryInput.fill( '12/30' );
  }

  const cvcInput = cardFrame.locator( '[name="cvc"], [autocomplete="cc-csc"]' );
  if ( await cvcInput.isVisible() ) {
    await cvcInput.fill( '123' );
  }

  const zipInput = cardFrame.locator(
    '[name="postalCode"], [name="zip"], [autocomplete="postal-code"]'
  );
  if ( await zipInput.isVisible( { timeout: 1000 } ).catch( () => false ) ) {
    await zipInput.fill( '12345' );
  }
}

/**
 * Resolve the Stripe test-mode 3D Secure challenge.
 *
 * The challenge's COMPLETE/FAIL buttons live in Stripe-hosted iframes nested
 * several levels deep, and the frame names have shifted across Stripe.js
 * versions, so every frame is scanned for the button instead of hardcoding a
 * frame chain.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          action 'complete' or 'fail'.
 */
async function resolve3DSChallenge( page, action ) {
  const name = action === 'complete' ? /^complete/i : /^fail/i;

  // Click on every retry until the challenge frame detaches: the ACS page can
  // ignore clicks that arrive before its handlers attach, and Stripe may swap
  // the frame during the handshake.
  let challengeSeen = false;
  await expect( async () => {
    const acsFrame = page
      .frames()
      .find( ( frame ) => frame.url().includes( 'testmode-acs.stripe.com' ) );
    if ( ! acsFrame ) {
      if ( ! challengeSeen ) {
        throw new Error( 'The 3DS challenge has not rendered yet.' );
      }
      return;
    }
    challengeSeen = true;
    await acsFrame
      .getByRole( 'button', { name } )
      .click( { timeout: 2000 } )
      .catch( () => {} );
    throw new Error( 'The 3DS challenge is still open.' );
  } ).toPass( { timeout: 45000, intervals: [ 1000 ] } );
}

test.describe( 'Peer-to-peer sign-up: first-gift nudge', () => {
  let campaign;
  let url;
  let approvalCampaign;
  let approvalUrl;
  let stripeReady;

  // This spec signs up more participants than the send-code limiter's window
  // allows (5 per 5 minutes), so the counters reset before every test.
  test.beforeEach( () => {
    clearP2PRateLimits();
  } );

  test.beforeAll( async ( { requestUtils } ) => {
    // The nudge renders only when Stripe charges are enabled.
    await enableTestMode( requestUtils );
    stripeReady = await configureStripe( requestUtils );

    ( { campaign, url } = await createP2PCampaign(
      requestUtils,
      'P2P First Gift E2E'
    ) );

    ( { campaign: approvalCampaign, url: approvalUrl } =
      await createP2PCampaign( requestUtils, 'P2P First Gift Approval E2E' ) );
    wpEval(
      `\\MissionDP\\Models\\Campaign::find( ${ approvalCampaign.id } )->update_meta( "approval_required", "1" );`
    );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    for ( const c of [ campaign, approvalCampaign ] ) {
      cleanupCampaignParticipants( c.id );
      await requestUtils.rest( {
        path: `/mission-donation-platform/v1/campaigns/${ c.id }`,
        method: 'DELETE',
      } );
    }
  } );

  test( 'sign-up lands on the nudge with a skip link and no share row', async ( {
    page,
  } ) => {
    const modal = await openModal( page, url );
    await signUp( modal, {
      first: 'Nudge',
      last: 'Runner',
      email: `e2e-nudge-${ Date.now() }@example.com`,
      password: 'longenough1',
    } );

    await expect(
      modal.getByRole( 'heading', { name: 'Give $25 to kick off your page' } )
    ).toBeVisible();
    await expect(
      modal.getByRole( 'button', { name: 'Make the first gift' } )
    ).toBeVisible();

    // Skipping is a plain link to the live fundraiser page.
    const skip = modal.getByRole( 'link', {
      name: 'Skip for now and view my page',
    } );
    await expect( skip ).toBeVisible();
    expect( await skip.getAttribute( 'href' ) ).toContain( 'http' );

    // Sharing happens after the gift, not on the nudge.
    await expect( modal.locator( '.mission-su__share' ) ).toBeHidden();
  } );

  test( 'changing the amount updates the headline and re-entry resets it', async ( {
    page,
  } ) => {
    const modal = await openModal( page, url );
    await signUp( modal, {
      first: 'Amount',
      last: 'Changer',
      email: `e2e-amount-${ Date.now() }@example.com`,
      password: 'longenough1',
    } );

    // Preset: updates the headline and collapses the grid.
    await modal.getByRole( 'button', { name: 'Change amount' } ).click();
    await modal.getByRole( 'button', { name: '$100', exact: true } ).click();
    await expect(
      modal.getByRole( 'heading', { name: 'Give $100 to kick off your page' } )
    ).toBeVisible();
    await expect(
      modal.getByRole( 'button', { name: '$100', exact: true } )
    ).toBeHidden();

    // Other: keeps the grid open and reveals the custom input.
    await modal.getByRole( 'button', { name: 'Change amount' } ).click();
    await modal.getByRole( 'button', { name: 'Other' } ).click();
    const custom = modal.locator( '.mission-su__amount-other-field' );
    await expect( custom ).toBeVisible();
    await expect(
      modal.getByRole( 'button', { name: '$100', exact: true } )
    ).toBeVisible();
    await custom.fill( '12.34' );
    await expect(
      modal.getByRole( 'heading', {
        name: 'Give $12.34 to kick off your page',
      } )
    ).toBeVisible();

    // Payment view has a back link to the nudge.
    await modal.getByRole( 'button', { name: 'Make the first gift' } ).click();
    await expect( modal.locator( '.mission-su__pay-amount' ) ).toHaveText(
      '$12.34'
    );
    await modal.getByRole( 'button', { name: 'Back' } ).click();
    await expect(
      modal.getByRole( 'button', { name: 'Make the first gift' } )
    ).toBeVisible();

    // Re-entering step 3 resets the nudge to the default amount.
    await modal.getByRole( 'button', { name: 'Close' } ).click();
    await page
      .getByRole( 'button', { name: 'Become a Fundraiser' } )
      .first()
      .click();
    await expect(
      modal.getByRole( 'heading', { name: 'Welcome back' } )
    ).toBeVisible();
    await modal.getByRole( 'button', { name: 'Continue' } ).click();
    await modal.getByRole( 'button', { name: 'Create fundraiser' } ).click();
    await expect(
      modal.getByRole( 'heading', { name: 'Give $25 to kick off your page' } )
    ).toBeVisible();
  } );

  test( 'the payment view shows the identity and recomputes the total live', async ( {
    page,
  } ) => {
    const email = `e2e-total-${ Date.now() }@example.com`;
    const modal = await openModal( page, url );
    await signUp( modal, {
      first: 'Fee',
      last: 'Toggler',
      email,
      password: 'longenough1',
    } );

    await modal.getByRole( 'button', { name: 'Make the first gift' } ).click();

    await expect( modal.locator( '.mission-su__pay-amount' ) ).toHaveText(
      '$25.00'
    );
    await expect( modal.locator( '.mission-su__pay-identity' ) ).toContainText(
      `Donating as Fee Toggler (${ email })`
    );

    // $25 + $1.06 fee + 15% tip.
    const submitLabel = modal.locator( '.mission-su__pay .mission-su__btn' );
    await expect( submitLabel ).toContainText( 'Donate $29.81 & launch' );

    // Unchecking the fee strikes the line and drops it from the total.
    await modal.getByRole( 'button', { name: 'Edit' } ).click();
    await modal
      .getByRole( 'checkbox', { name: 'I want to cover the fee' } )
      .uncheck();
    await expect( modal.locator( '.mission-su__pay-fee-text' ) ).toHaveClass(
      /uncovered/
    );
    await expect( submitLabel ).toContainText( 'Donate $28.75 & launch' );
  } );

  test( 'paying the first gift shows the thank-you and credits the fundraiser', async ( {
    page,
  } ) => {
    test.skip(
      ! stripeReady,
      'Set MISSIONDP_STRIPE_TEST_TOKEN to run payment tests'
    );

    const email = `e2e-gift-${ Date.now() }@example.com`;
    const modal = await openModal( page, url );
    await signUp( modal, {
      first: 'Paying',
      last: 'Founder',
      email,
      password: 'longenough1',
    } );

    await modal.getByRole( 'button', { name: 'Make the first gift' } ).click();
    await fillGiftCard( page, modal );
    await modal.getByRole( 'button', { name: /Donate .* & launch/ } ).click();

    await expect(
      modal.getByRole( 'heading', { name: 'Thank you!' } )
    ).toBeVisible( { timeout: 30000 } );
    await expect( modal.getByText( 'Your $25 gift is in' ) ).toBeVisible();
    await expect( modal.locator( '.mission-su__share' ) ).toBeVisible();
    await expect(
      modal.getByRole( 'link', { name: 'View my page' } )
    ).toBeVisible();

    // The donation is attributed to the brand-new fundraiser (donor = owner).
    const row = wpEval(
      `$d = \\MissionDP\\Models\\Donor::find_by_email( "${ email }" );` +
        ` $f = \\MissionDP\\Models\\Fundraiser::find_by_campaign_donor( ${ campaign.id }, $d->id );` +
        ' global $wpdb;' +
        ' echo wp_json_encode( $wpdb->get_row( $wpdb->prepare( "SELECT amount FROM {$wpdb->prefix}missiondp_transactions WHERE fundraiser_id = %d", $f->id ), ARRAY_A ) );'
    );
    const transaction = JSON.parse( row.split( '\n' ).pop() );
    expect( transaction ).not.toBeNull();
    expect( Number( transaction.amount ) ).toBe( 2500 );
  } );

  test( 'a 3D Secure challenge can be completed and the gift lands', async ( {
    page,
  } ) => {
    test.skip(
      ! stripeReady,
      'Set MISSIONDP_STRIPE_TEST_TOKEN to run payment tests'
    );

    const email = `e2e-3ds-ok-${ Date.now() }@example.com`;
    const modal = await openModal( page, url );
    await signUp( modal, {
      first: 'Secure',
      last: 'Giver',
      email,
      password: 'longenough1',
    } );

    await modal.getByRole( 'button', { name: 'Make the first gift' } ).click();
    await fillGiftCard( page, modal, '4000002760003184' );
    await modal.getByRole( 'button', { name: /Donate .* & launch/ } ).click();

    await resolve3DSChallenge( page, 'complete' );

    await expect(
      modal.getByRole( 'heading', { name: 'Thank you!' } )
    ).toBeVisible( { timeout: 30000 } );

    const row = wpEval(
      `$d = \\MissionDP\\Models\\Donor::find_by_email( "${ email }" );` +
        ` $f = \\MissionDP\\Models\\Fundraiser::find_by_campaign_donor( ${ campaign.id }, $d->id );` +
        ' global $wpdb;' +
        ' echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}missiondp_transactions WHERE fundraiser_id = %d", $f->id ) );'
    );
    expect( Number( row.split( '\n' ).pop() ) ).toBe( 1 );
  } );

  test( 'failing the 3D Secure challenge surfaces the error and allows retry', async ( {
    page,
  } ) => {
    test.skip(
      ! stripeReady,
      'Set MISSIONDP_STRIPE_TEST_TOKEN to run payment tests'
    );

    const modal = await openModal( page, url );
    await signUp( modal, {
      first: 'Secure',
      last: 'Failer',
      email: `e2e-3ds-fail-${ Date.now() }@example.com`,
      password: 'longenough1',
    } );

    await modal.getByRole( 'button', { name: 'Make the first gift' } ).click();
    await fillGiftCard( page, modal, '4000002760003184' );
    await modal.getByRole( 'button', { name: /Donate .* & launch/ } ).click();

    await resolve3DSChallenge( page, 'fail' );

    // Stripe's authentication-failed message lands in the error region, the
    // spinner stops, and the donate button is ready for another attempt.
    const error = modal.locator( '.mission-su__error:visible' );
    await expect( error ).toBeVisible( { timeout: 30000 } );
    await expect( error ).not.toBeEmpty();
    await expect(
      modal.getByRole( 'button', { name: /Donate .* & launch/ } )
    ).toBeEnabled();
    await expect(
      modal.getByRole( 'heading', { name: 'Thank you!' } )
    ).toBeHidden();
  } );

  test( 'an approval-required campaign shows the pending screen, never the nudge', async ( {
    page,
  } ) => {
    const modal = await openModal( page, approvalUrl );
    await advanceToSetup( modal, {
      first: 'Pending',
      last: 'Giver',
      email: `e2e-pendinggift-${ Date.now() }@example.com`,
      password: 'longenough1',
    } );
    await modal.getByRole( 'button', { name: 'Create fundraiser' } ).click();

    await expect(
      modal.getByRole( 'heading', { name: "You're almost there!" } )
    ).toBeVisible();
    await expect(
      modal.getByRole( 'button', { name: 'Make the first gift' } )
    ).toBeHidden();
    await expect(
      modal.getByRole( 'heading', { name: "You're a fundraiser!" } )
    ).toBeHidden();
  } );
} );
