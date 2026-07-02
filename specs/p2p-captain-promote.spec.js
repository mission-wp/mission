/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { wpEval, dashboardLogin } = require( './helpers/p2p' );

/**
 * Seed a captain and one regular member (both with login accounts) on a team,
 * on a P2P campaign with teams enabled.
 *
 * @return {Object} Seed data including credentials, names, dashboard URL, and IDs.
 */
function seedPromoteTeam() {
  const stamp = Date.now();
  const password = 'longenough123';
  const captainEmail = `captain+${ stamp }@example.com`;
  const memberEmail = `member+${ stamp }@example.com`;
  const teamName = `Team ${ stamp }`;

  const php =
    `$c = new \\MissionDP\\Models\\Campaign( [ "title" => "Promote E2E ${ stamp }", "type" => "p2p" ] ); $c->save();` +
    ` $c->update_meta( "teams_enabled", "1" );` +
    ` $d = new \\MissionDP\\Models\\Donor( [ "email" => "${ captainEmail }", "first_name" => "Cap", "last_name" => "Tain" ] ); $d->save();` +
    ` $uid = $d->create_user_account( "${ password }" );` +
    ` $t = \\MissionDP\\Models\\Team::register( $c->id, "${ teamName }", 100000 );` +
    ` $cap = new \\MissionDP\\Models\\Fundraiser( [ "campaign_id" => $c->id, "donor_id" => $d->id, "team_id" => $t->id, "is_team_captain" => true, "status" => "active", "goal" => 50000 ] ); $cap->save();` +
    ` $t->set_captain( $cap );` +
    ` $md = new \\MissionDP\\Models\\Donor( [ "email" => "${ memberEmail }", "first_name" => "Mem", "last_name" => "Ber" ] ); $md->save();` +
    ` $muid = $md->create_user_account( "${ password }" );` +
    ` $m = new \\MissionDP\\Models\\Fundraiser( [ "campaign_id" => $c->id, "donor_id" => $md->id, "team_id" => $t->id, "status" => "active", "goal" => 25000 ] ); $m->save();` +
    ` echo $c->id . "|" . $d->id . "|" . $cap->id . "|" . $uid . "|" . $t->id . "|" . $md->id . "|" . $m->id . "|" . $muid . "|" . get_permalink( (int) get_option( "missiondp_dashboard_page_id" ) );`;

  const out = wpEval( php ).split( '\n' ).pop().trim();
  const [
    campaign,
    donor,
    fundraiser,
    user,
    team,
    memberDonor,
    member,
    memberUser,
    dashboardUrl,
  ] = out.split( '|' );

  return {
    password,
    captainEmail,
    memberEmail,
    captainName: 'Cap Tain',
    memberName: 'Mem Ber',
    dashboardUrl,
    ids: {
      campaign,
      donor,
      fundraiser,
      user,
      team,
      memberDonor,
      member,
      memberUser,
    },
  };
}

/**
 * Remove everything seedPromoteTeam() created, via the models.
 *
 * @param {Object} ids Created entity IDs.
 */
function cleanup( ids ) {
  const {
    campaign,
    donor,
    fundraiser,
    user,
    team,
    memberDonor,
    member,
    memberUser,
  } = ids;
  const php =
    `$m = \\MissionDP\\Models\\Fundraiser::find( ${ member } ); if ( $m ) { $m->delete(); }` +
    ` $f = \\MissionDP\\Models\\Fundraiser::find( ${ fundraiser } ); if ( $f ) { $f->delete(); }` +
    ` $t = \\MissionDP\\Models\\Team::find( ${ team } ); if ( $t ) { $t->delete(); }` +
    ` $md = \\MissionDP\\Models\\Donor::find( ${ memberDonor } ); if ( $md ) { $md->delete(); }` +
    ` $d = \\MissionDP\\Models\\Donor::find( ${ donor } ); if ( $d ) { $d->delete(); }` +
    ` if ( ${ user } ) { wp_delete_user( ${ user } ); }` +
    ` if ( ${ memberUser } ) { wp_delete_user( ${ memberUser } ); }` +
    ` $c = \\MissionDP\\Models\\Campaign::find( ${ campaign } ); if ( $c ) { $c->delete(); }`;

  wpEval( php );
}

test.describe( 'P2P captain promotion', () => {
  let seed;

  test.beforeAll( () => {
    seed = seedPromoteTeam();
  } );

  test.afterAll( () => {
    cleanup( seed.ids );
  } );

  test( 'the captain promotes a member and the controls move to the new captain', async ( {
    page,
    browser,
  } ) => {
    const {
      password,
      captainEmail,
      memberEmail,
      captainName,
      memberName,
      dashboardUrl,
    } = seed;

    await page.goto( dashboardUrl );
    await dashboardLogin( page, captainEmail, password );
    await page.locator( 'button[data-panel="fundraising"]' ).click();

    const captainRow = page.locator( '.mission-dd-member', {
      hasText: captainName,
    } );
    const memberRow = page.locator( '.mission-dd-member', {
      hasText: memberName,
    } );

    // Before: the badge sits on the seeded captain, not the member.
    await expect(
      captainRow.locator( '.mission-dd-member-badge' )
    ).toBeVisible();
    await expect(
      memberRow.locator( '.mission-dd-member-badge' )
    ).toBeHidden();

    // Promote the member (confirm dialog auto-accepted).
    page.on( 'dialog', ( dialog ) => dialog.accept() );
    await memberRow.getByRole( 'button', { name: 'Make captain' } ).click();
    await expect( page.locator( '.mission-dd-toast' ) ).toContainText(
      'New captain set'
    );

    // The former captain immediately loses the team management section.
    await expect( page.locator( '.mission-dd-captain' ) ).toBeHidden();

    // The promoted member (fresh session) now has the captain controls, with
    // the badge on their own row and none on the former captain's.
    const context = await browser.newContext();
    const memberPage = await context.newPage();
    try {
      await memberPage.goto( dashboardUrl );
      await dashboardLogin( memberPage, memberEmail, password );
      await memberPage.locator( 'button[data-panel="fundraising"]' ).click();

      await expect( memberPage.locator( '.mission-dd-captain' ) ).toBeVisible();
      await expect(
        memberPage
          .locator( '.mission-dd-member', { hasText: memberName } )
          .locator( '.mission-dd-member-badge' )
      ).toBeVisible();
      await expect(
        memberPage
          .locator( '.mission-dd-member', { hasText: captainName } )
          .locator( '.mission-dd-member-badge' )
      ).toBeHidden();
    } finally {
      await context.close();
    }
  } );
} );
