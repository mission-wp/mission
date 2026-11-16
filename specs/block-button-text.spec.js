/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Editable block button labels', () => {
  test.beforeEach( async ( { admin } ) => {
    await admin.createNewPost();
  } );

  test( 'Team Progress previews and saves custom button text', async ( {
    editor,
    page,
  } ) => {
    await editor.insertBlock( {
      name: 'mission-donation-platform/team-progress',
    } );
    await editor.openDocumentSettingsSidebar();

    await page.getByLabel( 'Donate button text' ).fill( 'Support us' );
    await page.getByLabel( 'Join button text' ).fill( 'Come aboard' );

    const buttons = editor.canvas.locator( '.mission-progress__btn' );
    await expect( buttons.nth( 0 ) ).toHaveText( 'Support us' );
    await expect( buttons.nth( 1 ) ).toHaveText( 'Come aboard' );

    const [ block ] = await editor.getBlocks();
    expect( block.attributes.donateButtonText ).toBe( 'Support us' );
    expect( block.attributes.joinButtonText ).toBe( 'Come aboard' );
  } );

  test( 'Campaign Progress offers a label for each of its buttons', async ( {
    editor,
    page,
  } ) => {
    await editor.insertBlock( {
      name: 'mission-donation-platform/campaign-progress',
    } );
    await editor.openDocumentSettingsSidebar();

    await expect( page.getByLabel( 'Donate button text' ) ).toBeVisible();
    await expect( page.getByLabel( 'Fundraiser button text' ) ).toBeVisible();

    // The label field goes away with the button it describes.
    await page
      .getByRole( 'combobox', { name: 'Donate button' } )
      .selectOption( 'hide' );
    await expect( page.getByLabel( 'Donate button text' ) ).toBeHidden();
  } );
} );
