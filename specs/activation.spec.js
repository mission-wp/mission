/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Admin Menu & Dashboard', () => {
	test( 'Admin menu item exists', async ( { admin, page } ) => {
		await admin.visitAdminPage( '/' );

		const menuLink = page.locator( '#toplevel_page_mission > a' );
		await expect( menuLink ).toBeVisible();
		await expect( menuLink ).toHaveText( /Mission/ );
	} );

	test( 'Dashboard page loads', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php?page=mission' );

		await expect(
			page.getByRole( 'heading', { name: 'Dashboard', level: 1 } )
		).toBeVisible();
		await expect(
			page.getByText( "Here's an overview of your fundraising." )
		).toBeVisible();
	} );
} );
