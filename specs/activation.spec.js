/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Admin Menu & Dashboard', () => {
  test( 'Admin menu item exists', async ( { admin, page } ) => {
    await admin.visitAdminPage( '/' );

    const menuLink = page.locator(
      '#toplevel_page_mission-donation-platform > a'
    );
    await expect( menuLink ).toBeVisible();
    await expect( menuLink ).toHaveText( /Mission/ );
  } );

  test( 'Dashboard page loads', async ( { admin, page } ) => {
    await admin.visitAdminPage( 'admin.php?page=mission-donation-platform' );

    await expect(
      page.getByRole( 'heading', { name: 'Dashboard', level: 1 } )
    ).toBeVisible();
    await expect(
      page.getByText( 'Your donation activity at a glance' )
    ).toBeVisible();
  } );
} );
