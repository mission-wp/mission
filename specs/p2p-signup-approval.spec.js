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
  clearP2PRateLimits,
  setChargesEnabled,
  advanceToSetup,
} = require( './helpers/p2p' );

test.describe( 'Peer-to-peer sign-up with approval required', () => {
  let campaign;
  let url;

  test.beforeAll( async ( { requestUtils } ) => {
    clearP2PRateLimits();
    // Pin the share-only success screen (the nudge has its own spec, which
    // also covers the pending outcome with charges enabled).
    await setChargesEnabled( requestUtils, false );

    ( { campaign, url } = await createP2PCampaign(
      requestUtils,
      'P2P Approval E2E'
    ) );

    wpEval(
      `\\MissionDP\\Models\\Campaign::find( ${ campaign.id } )->update_meta( "approval_required", "1" );`
    );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    cleanupCampaignParticipants( campaign.id );

    await requestUtils.rest( {
      path: `/mission-donation-platform/v1/campaigns/${ campaign.id }`,
      method: 'DELETE',
    } );
  } );

  test( 'a completed sign-up lands on the pending screen without a live share link', async ( {
    page,
  } ) => {
    const email = `e2e-pending-${ Date.now() }@example.com`;

    const modal = await openModal( page, url );
    await advanceToSetup( modal, {
      first: 'Pending',
      last: 'Runner',
      email,
      password: 'longenough1',
    } );

    await modal.locator( '.mission-su__field textarea' ).fill( 'Reviewing.' );
    await modal.getByRole( 'button', { name: 'Create fundraiser' } ).click();

    // The under-review outcome, not the live one.
    await expect(
      modal.getByRole( 'heading', { name: "You're almost there!" } )
    ).toBeVisible();
    await expect( modal.getByText( 'submitted for review' ) ).toBeVisible();

    // No live-page link or share actions while approval is pending.
    await expect(
      modal.getByRole( 'heading', { name: "You're a fundraiser!" } )
    ).toBeHidden();
    await expect(
      modal.getByRole( 'link', { name: 'View my page' } )
    ).toBeHidden();
  } );
} );
