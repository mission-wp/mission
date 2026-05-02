/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

let detailPath;

test.beforeAll( async ( { requestUtils } ) => {
  // Create a campaign for these tests.
  const campaign = await requestUtils.rest( {
    path: '/mission-donation-platform/v1/campaigns',
    method: 'POST',
    data: { title: 'Block Editor Test' },
  } );

  detailPath = `admin.php?page=mission-donation-platform-campaigns&campaign=${ campaign.id }`;
} );

test.describe( 'Campaign Block Editor', () => {
  test( 'embedded block editor loads on Edit Page tab', async ( {
    admin,
    page,
  } ) => {
    await admin.visitAdminPage( detailPath );
    await page.getByRole( 'button', { name: 'Edit Page' } ).click();

    // The block editor canvas should appear.
    const canvas = page.locator( '.mission-block-editor__canvas' );
    await expect( canvas ).toBeVisible( { timeout: 15000 } );

    // The writing flow area should be present.
    await expect( canvas.locator( '.editor-styles-wrapper' ) ).toBeVisible();
  } );

  test( 'server-side block definitions are bootstrapped', async ( {
    admin,
    page,
  } ) => {
    // Collect JS errors during page load.
    const errors = [];
    page.on( 'pageerror', ( error ) => errors.push( error.message ) );

    await admin.visitAdminPage( detailPath );
    await page.getByRole( 'button', { name: 'Edit Page' } ).click();

    await expect( page.locator( '.mission-block-editor__canvas' ) ).toBeVisible(
      { timeout: 15000 }
    );

    // core/paragraph uses the block.json registration pattern.
    // If it's in the JS registry, the server-side block definitions
    // bootstrap (unstable__bootstrapServerSideBlockDefinitions) is working.
    // This is the key test: if WordPress ever removes or renames that API,
    // this will fail, alerting us to update our bootstrapping code.
    const coreParaRegistered = await page.evaluate(
      () => !! window.wp?.blocks?.getBlockType( 'core/paragraph' )
    );
    expect( coreParaRegistered ).toBe( true );

    // No JS errors from block registration or bootstrap.
    const blockErrors = errors.filter(
      ( e ) =>
        e.includes( 'registerBlockType' ) ||
        e.includes( 'bootstrapServerSideBlockDefinitions' )
    );
    expect( blockErrors ).toHaveLength( 0 );
  } );

  test( 'donation form block script is enqueued on the page', async ( {
    admin,
    page,
  } ) => {
    await admin.visitAdminPage( detailPath );

    // Check that the donation-form editor script tag is present in the HTML.
    // This verifies BlocksModule::enqueue_block_editor_assets() works.
    const scriptTag = page.locator(
      'script#mission-donation-platform-donation-form-editor-script-js'
    );
    await expect( scriptTag ).toBeAttached();
  } );
} );
