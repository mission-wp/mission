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

  test( 'a fundraiser who never donated sees the fundraiser dashboard only', async ( {
    page,
  } ) => {
    const { email, password, dashboardUrl } = seed;

    await page.goto( dashboardUrl );
    await dashboardLogin( page, email, password );

    // The Fundraising nav appears because this donor owns a fundraiser…
    await expect(
      page.locator( '.mission-dd-nav-link[data-panel="fundraisers"]' )
    ).toBeVisible();

    // …and the giving panels are hidden because they never donated.
    await expect(
      page.locator( '.mission-dd-nav-link[data-panel="history"]' )
    ).toHaveCount( 0 );
    await expect(
      page.locator( '.mission-dd-nav-link[data-panel="recurring"]' )
    ).toHaveCount( 0 );
    await expect(
      page.locator( '.mission-dd-nav-link[data-panel="receipts"]' )
    ).toHaveCount( 0 );

    // The Overview shows the fundraiser stats variant.
    await expect(
      page.getByText( 'Total Raised', { exact: true } )
    ).toBeVisible();
    await expect(
      page.getByText( 'Lifetime Given', { exact: true } )
    ).toBeHidden();
  } );

  test( 'a logged-in fundraiser edits their page and the change persists', async ( {
    page,
  } ) => {
    const { email, password, headline, dashboardUrl } = seed;
    const newHeadline = `Edited headline ${ Date.now() }`;

    await page.goto( dashboardUrl );
    await dashboardLogin( page, email, password );

    // Drill in: My Fundraisers list, then the page's card.
    await page
      .locator( '.mission-dd-nav-link[data-panel="fundraisers"]' )
      .click();
    await page
      .locator( '.mission-dd-panel.active .mission-dd-fr-card' )
      .first()
      .click();

    const headlineInput = page.locator( '#mission-dd-fr-headline' );
    await expect( headlineInput ).toHaveValue( headline );

    // The goal is shown in major units (seeded at 50000 minor = 500).
    await expect( page.locator( '#mission-dd-fr-goal' ) ).toHaveValue( '500' );

    // Edit the headline and goal, then save. The goal is entered in major
    // units; the server converts to minor and hands back the major value.
    await headlineInput.fill( newHeadline );
    await page.locator( '#mission-dd-fr-goal' ).fill( '750' );
    await page.getByRole( 'button', { name: 'Save changes' } ).click();
    await expect( page.locator( '.mission-dd-toast' ) ).toContainText(
      'Fundraiser updated'
    );

    // Deep-link straight back to the detail view (session persists) and
    // confirm both values were stored, with the goal round-tripping back
    // to major units.
    await page.goto( `${ dashboardUrl }#fundraiser-${ seed.ids.fundraiser }` );
    await expect( page.locator( '.mission-dd-sidebar' ) ).toBeVisible();
    await expect( page.locator( '#mission-dd-fr-headline' ) ).toHaveValue(
      newHeadline
    );
    await expect( page.locator( '#mission-dd-fr-goal' ) ).toHaveValue( '750' );
  } );

  test( 'the legacy #fundraising hash lands on My Fundraisers', async ( {
    page,
  } ) => {
    const { email, password, dashboardUrl } = seed;

    await page.goto( dashboardUrl );
    await dashboardLogin( page, email, password );

    await page.goto( `${ dashboardUrl }#fundraising` );
    await page.reload();

    await expect(
      page.locator( '.mission-dd-panel.active .mission-dd-fr-card' ).first()
    ).toBeVisible();
    await expect( page ).toHaveURL( /#fundraisers$/ );
  } );

  test( 'progress bars are driven by the --bar-width custom property', async ( {
    page,
  } ) => {
    const { email, password, dashboardUrl, ids } = seed;

    // A supporter's donation gives the fundraiser nonzero progress. The
    // pending -> completed transition fires the total_raised rollups, and
    // is_test must match the site's mode or the dashboard reads the other
    // column.
    const out = wpEval(
      `$is_test = ! empty( get_option( "missiondp_settings", [] )["test_mode"] );` +
        ` $s = new \\MissionDP\\Models\\Donor( [ "email" => "supporter+${ Date.now() }@example.com", "first_name" => "Sup", "last_name" => "Porter" ] ); $s->save();` +
        ` $t = new \\MissionDP\\Models\\Transaction( [ "status" => "pending", "donor_id" => $s->id, "campaign_id" => ${ ids.campaign }, "fundraiser_id" => ${ ids.fundraiser }, "amount" => 12500, "is_test" => $is_test ] ); $t->save();` +
        ` $t->status = "completed"; $t->save();` +
        ` echo $s->id . "|" . $t->id;`
    )
      .split( '\n' )
      .pop()
      .trim();
    const [ supporterId, txnId ] = out.split( '|' );

    try {
      await page.goto( dashboardUrl );
      await dashboardLogin( page, email, password );

      await page
        .locator( '.mission-dd-nav-link[data-panel="fundraisers"]' )
        .click();

      const fill = page
        .locator( '.mission-dd-panel.active .mission-dd-progress-fill' )
        .first();
      await expect( fill ).toBeVisible();

      // The fill's width must come from the --bar-width custom property via
      // the stylesheet (not an inline width), so users can restyle it with
      // plain CSS.
      await expect( fill ).toHaveAttribute(
        'style',
        /--bar-width:\s*\d+(\.\d+)?%/
      );
      await expect
        .poll( () =>
          fill.evaluate( ( el ) =>
            parseFloat( window.getComputedStyle( el ).width )
          )
        )
        .toBeGreaterThan( 0 );
    } finally {
      wpEval(
        `$t = \\MissionDP\\Models\\Transaction::find( ${ txnId } ); if ( $t ) { $t->delete(); }` +
          ` $s = \\MissionDP\\Models\\Donor::find( ${ supporterId } ); if ( $s ) { $s->delete(); }`
      );
    }
  } );
} );

/**
 * Seed a member (with a login account) on a team captained by someone else,
 * plus a second fundraiser for the same donor on an ended campaign.
 *
 * @return {Object} Seed data including credentials, dashboard URL, and IDs.
 */
function seedMemberWithEndedPage() {
  const stamp = Date.now();
  const email = `member+${ stamp }@example.com`;
  const password = 'longenough123';
  const teamName = `Zoomies ${ stamp }`;

  const php =
    `$c = new \\MissionDP\\Models\\Campaign( [ "title" => "Live E2E ${ stamp }", "type" => "p2p" ] ); $c->save();` +
    ` $ended = new \\MissionDP\\Models\\Campaign( [ "title" => "Ended E2E ${ stamp }", "type" => "p2p", "status" => "ended" ] ); $ended->save();` +
    ` $t = \\MissionDP\\Models\\Team::register( $c->id, "${ teamName }", 100000 );` +
    ` $cd = new \\MissionDP\\Models\\Donor( [ "email" => "cap+${ stamp }@example.com", "first_name" => "Cap", "last_name" => "Tain" ] ); $cd->save();` +
    ` $cap = new \\MissionDP\\Models\\Fundraiser( [ "campaign_id" => $c->id, "donor_id" => $cd->id, "team_id" => $t->id, "status" => "active" ] ); $cap->save();` +
    ` $t->set_captain( $cap );` +
    ` $d = new \\MissionDP\\Models\\Donor( [ "email" => "${ email }", "first_name" => "Mem", "last_name" => "Ber" ] ); $d->save();` +
    ` $uid = $d->create_user_account( "${ password }" );` +
    ` $m = new \\MissionDP\\Models\\Fundraiser( [ "campaign_id" => $c->id, "donor_id" => $d->id, "team_id" => $t->id, "status" => "active", "goal" => 50000, "headline" => "Live page ${ stamp }" ] ); $m->save();` +
    ` $old = new \\MissionDP\\Models\\Fundraiser( [ "campaign_id" => $ended->id, "donor_id" => $d->id, "status" => "active", "goal" => 20000, "headline" => "Old page ${ stamp }" ] ); $old->save();` +
    ` echo $c->id . "|" . $ended->id . "|" . $t->id . "|" . $cd->id . "|" . $cap->id . "|" . $d->id . "|" . $uid . "|" . $m->id . "|" . $old->id . "|" . get_permalink( (int) get_option( "missiondp_dashboard_page_id" ) );`;

  const out = wpEval( php ).split( '\n' ).pop().trim();
  const [
    campaign,
    endedCampaign,
    team,
    captainDonor,
    captain,
    donor,
    user,
    member,
    endedFundraiser,
    dashboardUrl,
  ] = out.split( '|' );

  return {
    email,
    password,
    teamName,
    dashboardUrl,
    ids: {
      campaign,
      endedCampaign,
      team,
      captainDonor,
      captain,
      donor,
      user,
      member,
      endedFundraiser,
    },
  };
}

/**
 * Remove everything seedMemberWithEndedPage() created, via the models.
 *
 * @param {Object} ids Created entity IDs.
 */
function cleanupMember( ids ) {
  const {
    campaign,
    endedCampaign,
    team,
    captainDonor,
    captain,
    donor,
    user,
    member,
    endedFundraiser,
  } = ids;
  const php =
    `foreach ( [ ${ member }, ${ endedFundraiser }, ${ captain } ] as $fid ) { $f = \\MissionDP\\Models\\Fundraiser::find( $fid ); if ( $f ) { $f->delete(); } }` +
    ` $t = \\MissionDP\\Models\\Team::find( ${ team } ); if ( $t ) { $t->delete(); }` +
    ` foreach ( [ ${ donor }, ${ captainDonor } ] as $did ) { $d = \\MissionDP\\Models\\Donor::find( $did ); if ( $d ) { $d->delete(); } }` +
    ` if ( ${ user } ) { wp_delete_user( ${ user } ); }` +
    ` foreach ( [ ${ campaign }, ${ endedCampaign } ] as $cid ) { $c = \\MissionDP\\Models\\Campaign::find( $cid ); if ( $c ) { $c->delete(); } }`;

  wpEval( php );
}

test.describe( 'P2P member dashboard', () => {
  let seed;

  test.beforeAll( () => {
    seed = seedMemberWithEndedPage();
  } );

  test.afterAll( () => {
    cleanupMember( seed.ids );
  } );

  test( 'a page on an ended campaign is read-only', async ( { page } ) => {
    const { email, password, dashboardUrl } = seed;

    await page.goto( dashboardUrl );
    await dashboardLogin( page, email, password );

    await page.goto(
      `${ dashboardUrl }#fundraiser-${ seed.ids.endedFundraiser }`
    );
    await page.reload();

    await expect( page.locator( '.mission-dd-readonly-note' ) ).toBeVisible();
    await expect( page.locator( '#mission-dd-fr-headline' ) ).toBeHidden();
  } );

  test( 'a member leaves their team', async ( { page } ) => {
    const { email, password, teamName, dashboardUrl } = seed;

    await page.goto( dashboardUrl );
    await dashboardLogin( page, email, password );

    await page.locator( '.mission-dd-nav-link[data-panel="teams"]' ).click();
    await page
      .locator( '.mission-dd-panel.active .mission-dd-fr-card', {
        hasText: teamName,
      } )
      .click();

    // Members see the roster but no captain tools.
    await expect( page.locator( '#mission-dd-team-name' ) ).toBeHidden();

    page.on( 'dialog', ( dialog ) => dialog.accept() );
    await page.getByRole( 'button', { name: 'Leave this team' } ).click();

    await expect( page.locator( '.mission-dd-toast' ) ).toContainText(
      'You left the team'
    );
    await expect(
      page.locator( '.mission-dd-panel.active .mission-dd-fr-card', {
        hasText: teamName,
      } )
    ).toHaveCount( 0 );

    // The server really detached the fundraiser.
    const teamId = wpEval(
      `$f = \\MissionDP\\Models\\Fundraiser::find( ${ seed.ids.member } ); echo $f && $f->team_id ? $f->team_id : "none";`
    )
      .split( '\n' )
      .pop()
      .trim();
    expect( teamId ).toBe( 'none' );
  } );
} );
