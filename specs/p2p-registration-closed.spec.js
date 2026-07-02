/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { wpEval, createP2PCampaign } = require( './helpers/p2p' );

test.describe( 'Peer-to-peer sign-up with registration closed', () => {
  let campaign;
  let url;

  test.beforeAll( async ( { requestUtils } ) => {
    ( { campaign, url } = await createP2PCampaign(
      requestUtils,
      'P2P Closed E2E'
    ) );

    wpEval(
      `\\MissionDP\\Models\\Campaign::find( ${ campaign.id } )->update_meta( "registration_open", "0" );`
    );
  } );

  test.afterAll( async ( { requestUtils } ) => {
    await requestUtils.rest( {
      path: `/mission-donation-platform/v1/campaigns/${ campaign.id }`,
      method: 'DELETE',
    } );
  } );

  test( 'the become-a-fundraiser CTA and sign-up modal are absent', async ( {
    page,
  } ) => {
    await page.goto( url );

    // The campaign page itself still renders.
    await expect( page.locator( '.mission-progress' ).first() ).toBeVisible();

    // But there is no way to reach the sign-up flow: no CTA anywhere on the
    // page and the modal block is not rendered at all.
    await expect(
      page.getByRole( 'button', { name: 'Become a Fundraiser' } )
    ).toHaveCount( 0 );
    await expect( page.locator( '.mission-su' ) ).toHaveCount( 0 );
  } );
} );
