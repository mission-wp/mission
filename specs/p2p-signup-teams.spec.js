/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
  wpEval,
  createP2PCampaign,
  cleanupCampaignParticipants,
  openModal,
  advanceToSetup,
} = require( './helpers/p2p' );

test.describe( 'Peer-to-peer team sign-up', () => {
  let campaign;
  let url;
  let teamName;
  let teamUrl;

  test.beforeAll( async ( { requestUtils } ) => {
    ( { campaign, url } = await createP2PCampaign(
      requestUtils,
      'P2P Teams E2E'
    ) );

    // Enable teams (with creation) and seed one public team to join.
    teamName = `Sharks ${ Date.now() }`;
    const out = wpEval(
      `$c = \\MissionDP\\Models\\Campaign::find( ${ campaign.id } );` +
        ' $c->update_meta( "teams_enabled", "1" );' +
        ' $c->update_meta( "team_creation_enabled", "1" );' +
        ` $t = \\MissionDP\\Models\\Team::register( $c->id, "${ teamName }", 100000 );` +
        ' echo get_permalink( $t->post_id );'
    );
    teamUrl = out.split( '\n' ).pop().trim();
  } );

  test.afterAll( async ( { requestUtils } ) => {
    cleanupCampaignParticipants( campaign.id );

    await requestUtils.rest( {
      path: `/mission-donation-platform/v1/campaigns/${ campaign.id }`,
      method: 'DELETE',
    } );
  } );

  test( 'a new participant joins an existing team and appears on its page', async ( {
    page,
  } ) => {
    const email = `e2e-join-${ Date.now() }@example.com`;

    const modal = await openModal( page, url );
    await advanceToSetup( modal, {
      first: 'Team',
      last: 'Joiner',
      email,
      password: 'longenough1',
    } );

    // Join is the default mode; pick the seeded public team.
    await expect(
      modal.getByRole( 'button', { name: 'Join a team' } )
    ).toBeVisible();
    await modal
      .locator( '.mission-su__panel select' )
      .selectOption( { label: teamName } );
    await modal.getByRole( 'button', { name: 'Create fundraiser' } ).click();

    await expect(
      modal.getByRole( 'heading', { name: "You're a fundraiser!" } )
    ).toBeVisible();

    // The team page's member list reflects the join.
    await page.goto( teamUrl );
    await expect(
      page.locator( '.mission-tm__name', { hasText: 'Team Joiner' } )
    ).toBeVisible();
  } );

  test( 'flipping the access toggle creates a private team', async ( {
    page,
  } ) => {
    const email = `e2e-private-${ Date.now() }@example.com`;
    const newTeam = `Owls ${ Date.now() }`;

    const modal = await openModal( page, url );
    await advanceToSetup( modal, {
      first: 'Private',
      last: 'Founder',
      email,
      password: 'longenough1',
    } );

    await modal.getByRole( 'button', { name: 'Create a team' } ).click();
    await modal.getByLabel( 'Team name' ).fill( newTeam );

    // Flip the access toggle; the hint swaps to the private explanation.
    await modal.getByLabel( 'Make this team private' ).check();
    await expect(
      modal.getByText( 'Only people you invite can join.' )
    ).toBeVisible();

    await modal.getByRole( 'button', { name: 'Create fundraiser' } ).click();
    await expect(
      modal.getByRole( 'heading', { name: "You're a fundraiser!" } )
    ).toBeVisible();

    const out = wpEval(
      `foreach ( \\MissionDP\\Models\\Team::query( [ "campaign_id" => ${ campaign.id }, "per_page" => 100 ] ) as $t ) {` +
        ` if ( "${ newTeam }" === $t->name ) { echo $t->access; } }`
    );
    expect( out.split( '\n' ).pop().trim() ).toBe( 'private' );
  } );

  test( 'a new participant creates a team and becomes its captain', async ( {
    page,
  } ) => {
    const email = `e2e-create-${ Date.now() }@example.com`;
    const newTeam = `Comets ${ Date.now() }`;

    const modal = await openModal( page, url );
    await advanceToSetup( modal, {
      first: 'Team',
      last: 'Founder',
      email,
      password: 'longenough1',
    } );

    await modal.getByRole( 'button', { name: 'Create a team' } ).click();
    await modal.getByLabel( 'Team name' ).fill( newTeam );
    await modal.getByRole( 'button', { name: 'Create fundraiser' } ).click();

    await expect(
      modal.getByRole( 'heading', { name: "You're a fundraiser!" } )
    ).toBeVisible();

    // The team now exists, captained by the new user's fundraiser.
    const out = wpEval(
      `foreach ( \\MissionDP\\Models\\Team::query( [ "campaign_id" => ${ campaign.id }, "per_page" => 100 ] ) as $t ) {` +
        ` if ( "${ newTeam }" === $t->name ) {` +
        ' $cap = $t->captain();' +
        ' echo $t->name . "|" . ( $cap ? $cap->donor()->email : "" ) . "|" . ( $cap && $cap->is_team_captain ? "1" : "0" );' +
        ' } }'
    );
    expect( out.split( '\n' ).pop().trim() ).toBe(
      `${ newTeam }|${ email }|1`
    );
  } );
} );
