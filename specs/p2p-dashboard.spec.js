/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { wpEval, dashboardLogin } = require( './helpers/p2p' );

/**
 * Seed a donor (with a login account) who owns a fundraiser on a P2P campaign.
 *
 * @return {{email: string, password: string, headline: string, dashboardUrl: string, ids: Object}} Seed data including credentials, dashboard URL, and IDs.
 */
function seedFundraiser() {
  const stamp = Date.now();
  const email = `fundraiser+${ stamp }@example.com`;
  const password = 'longenough123';
  const headline = `Original headline ${ stamp }`;

  const php =
    `$c = new \\MissionDP\\Models\\Campaign( [ "title" => "Dash E2E ${ stamp }", "type" => "p2p" ] ); $c->save();` +
    ` $d = new \\MissionDP\\Models\\Donor( [ "email" => "${ email }", "first_name" => "Dash", "last_name" => "Runner" ] ); $d->save();` +
    ` $uid = $d->create_user_account( "${ password }" );` +
    ` $f = new \\MissionDP\\Models\\Fundraiser( [ "campaign_id" => $c->id, "donor_id" => $d->id, "status" => "active", "goal" => 50000, "headline" => "${ headline }", "story" => "My story" ] ); $f->save();` +
    ` echo $c->id . "|" . $d->id . "|" . $f->id . "|" . $uid . "|" . get_permalink( (int) get_option( "missiondp_dashboard_page_id" ) );`;

  const out = wpEval( php ).split( '\n' ).pop().trim();
  const [ campaign, donor, fundraiser, user, dashboardUrl ] = out.split( '|' );

  return {
    email,
    password,
    headline,
    dashboardUrl,
    ids: { campaign, donor, fundraiser, user },
  };
}

/**
 * Remove everything seedFundraiser() created (fundraiser + shell post, donor,
 * WP user, campaign), via the models so cascades are respected.
 *
 * @param {Object} ids Created entity IDs.
 */
function cleanupFundraiser( ids ) {
  const { campaign, donor, fundraiser, user } = ids;
  const php =
    `$f = \\MissionDP\\Models\\Fundraiser::find( ${ fundraiser } ); if ( $f ) { $f->delete(); }` +
    ` $d = \\MissionDP\\Models\\Donor::find( ${ donor } ); if ( $d ) { $d->delete(); }` +
    ` if ( ${ user } ) { wp_delete_user( ${ user } ); }` +
    ` $c = \\MissionDP\\Models\\Campaign::find( ${ campaign } ); if ( $c ) { $c->delete(); }`;

  wpEval( php );
}

test.describe( 'P2P fundraiser dashboard', () => {
  let seed;

  test.beforeAll( () => {
    seed = seedFundraiser();
  } );

  test.afterAll( () => {
    cleanupFundraiser( seed.ids );
  } );

  test( 'a logged-in fundraiser edits their page and the change persists', async ( {
    page,
  } ) => {
    const { email, password, headline, dashboardUrl } = seed;
    const newHeadline = `Edited headline ${ Date.now() }`;

    await page.goto( dashboardUrl );
    await dashboardLogin( page, email, password );

    // The Fundraising tab appears because this donor owns a fundraiser.
    await page.locator( 'button[data-panel="fundraising"]' ).click();

    const headlineInput = page.locator( '#mission-fd-headline' );
    await expect( headlineInput ).toHaveValue( headline );

    // The goal is shown in major units (seeded at 50000 minor = 500).
    await expect( page.locator( '#mission-fd-goal' ) ).toHaveValue( '500' );

    // Edit the headline and goal, then save. The goal is entered in major
    // units; the server converts to minor and hands back the major value.
    await headlineInput.fill( newHeadline );
    await page.locator( '#mission-fd-goal' ).fill( '750' );
    await page.getByRole( 'button', { name: 'Save changes' } ).click();
    await expect( page.locator( '.mission-dd-toast' ) ).toContainText(
      'Fundraiser updated'
    );

    // Reload (session persists) and confirm both values were stored, with the
    // goal round-tripping back to major units.
    await page.goto( `${ dashboardUrl }#fundraising` );
    await expect( page.locator( '.mission-dd-sidebar' ) ).toBeVisible();
    await page.locator( 'button[data-panel="fundraising"]' ).click();
    await expect( page.locator( '#mission-fd-headline' ) ).toHaveValue(
      newHeadline
    );
    await expect( page.locator( '#mission-fd-goal' ) ).toHaveValue( '750' );
  } );
} );
