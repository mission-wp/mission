/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { wpEval, dashboardLogin } = require( './helpers/p2p' );

/**
 * Seed a captain (with a login account) leading a team that has one other
 * member, on a P2P campaign with teams enabled.
 *
 * @return {Object} Seed data including credentials, dashboard URL, and IDs.
 */
function seedCaptainTeam() {
  const stamp = Date.now();
  const email = `captain+${ stamp }@example.com`;
  const password = 'longenough123';
  const teamName = `Team ${ stamp }`;
  const memberName = 'Mem Ber';

  const php =
    `$c = new \\MissionDP\\Models\\Campaign( [ "title" => "Captain E2E ${ stamp }", "type" => "p2p" ] ); $c->save();` +
    ` $c->update_meta( "teams_enabled", "1" );` +
    ` $d = new \\MissionDP\\Models\\Donor( [ "email" => "${ email }", "first_name" => "Cap", "last_name" => "Tain" ] ); $d->save();` +
    ` $uid = $d->create_user_account( "${ password }" );` +
    ` $t = \\MissionDP\\Models\\Team::register( $c->id, "${ teamName }", 100000 );` +
    ` $cap = new \\MissionDP\\Models\\Fundraiser( [ "campaign_id" => $c->id, "donor_id" => $d->id, "team_id" => $t->id, "is_team_captain" => true, "status" => "active", "goal" => 50000 ] ); $cap->save();` +
    ` $t->set_captain( $cap );` +
    ` $md = new \\MissionDP\\Models\\Donor( [ "email" => "member+${ stamp }@example.com", "first_name" => "Mem", "last_name" => "Ber" ] ); $md->save();` +
    ` $m = new \\MissionDP\\Models\\Fundraiser( [ "campaign_id" => $c->id, "donor_id" => $md->id, "team_id" => $t->id, "status" => "active", "goal" => 25000 ] ); $m->save();` +
    ` echo $c->id . "|" . $d->id . "|" . $cap->id . "|" . $uid . "|" . $t->id . "|" . $md->id . "|" . $m->id . "|" . get_permalink( (int) get_option( "missiondp_dashboard_page_id" ) );`;

  const out = wpEval( php ).split( '\n' ).pop().trim();
  const [
    campaign,
    donor,
    fundraiser,
    user,
    team,
    memberDonor,
    member,
    dashboardUrl,
  ] = out.split( '|' );

  return {
    email,
    password,
    teamName,
    memberName,
    dashboardUrl,
    ids: { campaign, donor, fundraiser, user, team, memberDonor, member },
  };
}

/**
 * Remove everything seedCaptainTeam() created, via the models.
 *
 * @param {Object} ids Created entity IDs.
 */
function cleanup( ids ) {
  const { campaign, donor, fundraiser, user, team, memberDonor, member } = ids;
  const php =
    `$m = \\MissionDP\\Models\\Fundraiser::find( ${ member } ); if ( $m ) { $m->delete(); }` +
    ` $f = \\MissionDP\\Models\\Fundraiser::find( ${ fundraiser } ); if ( $f ) { $f->delete(); }` +
    ` foreach ( \\MissionDP\\Models\\TeamInvitation::query( [ "team_id" => ${ team } ] ) as $inv ) { $inv->delete(); }` +
    ` $t = \\MissionDP\\Models\\Team::find( ${ team } ); if ( $t ) { $t->delete(); }` +
    ` $md = \\MissionDP\\Models\\Donor::find( ${ memberDonor } ); if ( $md ) { $md->delete(); }` +
    ` $d = \\MissionDP\\Models\\Donor::find( ${ donor } ); if ( $d ) { $d->delete(); }` +
    ` if ( ${ user } ) { wp_delete_user( ${ user } ); }` +
    ` $c = \\MissionDP\\Models\\Campaign::find( ${ campaign } ); if ( $c ) { $c->delete(); }`;

  wpEval( php );
}

test.describe( 'P2P team captain controls', () => {
  let seed;

  test.beforeAll( () => {
    seed = seedCaptainTeam();
  } );

  test.afterAll( () => {
    cleanup( seed.ids );
  } );

  test( 'a captain edits the team, invites a member, and removes a member', async ( {
    page,
  } ) => {
    const { email, password, teamName, memberName, dashboardUrl } = seed;

    await page.goto( dashboardUrl );
    await dashboardLogin( page, email, password );
    await page.locator( 'button[data-panel="fundraising"]' ).click();

    // The captain sub-section is shown with the team's current name.
    const nameInput = page.locator( '#mission-dd-team-name' );
    await expect( nameInput ).toHaveValue( teamName );

    // Edit and save the team name.
    const newName = `${ teamName } Renamed`;
    await nameInput.fill( newName );
    await page.getByRole( 'button', { name: 'Save changes' } ).last().click();
    await expect( page.locator( '.mission-dd-toast' ) ).toContainText(
      'Team updated'
    );

    // Invite a new member by email.
    await page
      .locator( '.mission-dd-invite input[type="email"]' )
      .fill( `invitee+${ Date.now() }@example.com` );
    await page.getByRole( 'button', { name: 'Send invite' } ).click();
    await expect( page.locator( '.mission-dd-toast' ) ).toContainText(
      'Invitation sent'
    );
    await expect( page.locator( '.mission-dd-invitation' ) ).toHaveCount( 1 );

    // Remove the seeded member, targeting their row by name so the action
    // never depends on member ordering (confirm dialog auto-accepted).
    page.on( 'dialog', ( dialog ) => dialog.accept() );
    await expect( page.locator( '.mission-dd-member' ) ).toHaveCount( 2 );
    await page
      .locator( '.mission-dd-member', { hasText: memberName } )
      .getByRole( 'button', { name: 'Remove' } )
      .click();
    await expect( page.locator( '.mission-dd-toast' ) ).toContainText(
      'Member removed'
    );
    await expect( page.locator( '.mission-dd-member' ) ).toHaveCount( 1 );
  } );
} );
