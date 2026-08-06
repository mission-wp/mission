/**
 * Donation form — multiple forms on one page.
 *
 * Regression coverage for per-form Stripe instance isolation: with two
 * forms on the same page, each must complete checkout independently.
 * Requires the same Stripe env vars as payment.spec.js.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
  enableTestMode,
  configureStripe,
} = require( './helpers/campaign-factory' );
const {
  snapshotSettings,
  snapshotStripeSiteToken,
} = require( '../helpers/settings' );

const SHORTCODE =
  '[mission_donation_form recurring_enabled="false" collect_address="false" amounts="10,25,50"]';

const TWO_FORMS_CONTENT =
  `<!-- wp:shortcode -->${ SHORTCODE }<!-- /wp:shortcode -->` +
  '<!-- wp:paragraph --><p>Second form:</p><!-- /wp:paragraph -->' +
  `<!-- wp:shortcode -->${ SHORTCODE }<!-- /wp:shortcode -->`;

/**
 * Complete a $25 card donation on the given form root.
 *
 * @param {import('@playwright/test').Locator} form  Form root locator.
 * @param {string}                             email Donor email.
 */
async function completeDonation( form, email ) {
  // Presets are server-rendered: click until the active state confirms the
  // handler was attached and the selection took (hydration race).
  const amountBtn = form.getByRole( 'button', { name: '$25.00', exact: true } );
  await expect( async () => {
    await amountBtn.click();
    await expect( amountBtn ).toHaveClass( /active/, { timeout: 1500 } );
  } ).toPass( { timeout: 15000 } );
  await form.locator( '.mission-df-btn--primary' ).first().click();

  await form.locator( 'input[id$="first-name"]' ).fill( 'Multi' );
  await form.locator( 'input[id$="last-name"]' ).fill( 'Form' );
  await form.locator( 'input[id$="email"]' ).fill( email );

  const cardFrame = form
    .frameLocator( 'iframe[name*="__privateStripeFrame"]' )
    .first();
  const cardInput = cardFrame.locator(
    '[name="cardnumber"], [name="number"], [autocomplete="cc-number"]'
  );
  await cardInput.waitFor( { state: 'visible', timeout: 15000 } );
  await cardInput.fill( '4242424242424242' );
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

  await form.locator( '.mission-df-donate-btn' ).click();
  await expect( form.locator( '.mission-df-success' ) ).toBeVisible( {
    timeout: 30000,
  } );
}

test.describe( 'Donation Form: Multiple forms on one page', () => {
  let pageId, pageUrl, stripeReady, settingsSnapshot, siteTokenSnapshot;

  test.beforeAll( async ( { requestUtils } ) => {
    settingsSnapshot = await snapshotSettings( requestUtils, [
      'test_mode',
      'stripe_charges_enabled',
      'stripe_connection_status',
      'stripe_account_id',
    ] );
    siteTokenSnapshot = snapshotStripeSiteToken();

    await enableTestMode( requestUtils );
    stripeReady = await configureStripe( requestUtils );

    const created = await requestUtils.rest( {
      path: '/wp/v2/pages',
      method: 'POST',
      data: {
        status: 'publish',
        title: 'Multiple Forms Test',
        content: TWO_FORMS_CONTENT,
      },
    } );
    pageId = created.id;
    pageUrl = created.link;
  } );

  test.afterAll( async ( { requestUtils } ) => {
    await requestUtils.rest( {
      path: `/wp/v2/pages/${ pageId }`,
      method: 'DELETE',
      params: { force: true },
    } );

    // The paying tests leave is_test transaction rows behind.
    if ( stripeReady ) {
      await requestUtils.rest( {
        path: '/mission-donation-platform/v1/cleanup/delete_test_transactions',
        method: 'POST',
      } );
    }

    await settingsSnapshot.restore();
    siteTokenSnapshot.restore();
  } );

  test( 'first form completes checkout', async ( { page } ) => {
    test.skip(
      ! stripeReady,
      'Set MISSIONDP_STRIPE_TEST_TOKEN to run payment tests'
    );

    await page.goto( pageUrl );
    const forms = page.locator( '.mission-donation-form' );
    await expect( forms ).toHaveCount( 2 );
    await completeDonation( forms.first(), 'multi-first@example.com' );
  } );

  test( 'second form completes checkout', async ( { page } ) => {
    test.skip(
      ! stripeReady,
      'Set MISSIONDP_STRIPE_TEST_TOKEN to run payment tests'
    );

    await page.goto( pageUrl );
    const forms = page.locator( '.mission-donation-form' );
    await expect( forms ).toHaveCount( 2 );
    await completeDonation( forms.nth( 1 ), 'multi-second@example.com' );
  } );
} );
