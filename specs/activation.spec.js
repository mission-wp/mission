/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Admin Menu & Dashboard', () => {
	test( 'Admin menu item exists', async ( { admin, page } ) => {
		await admin.visitAdminPage( '/' );

		const menuLink = page.locator(
			'#adminmenu a[href="admin.php?page=mission"]'
		);
		await expect( menuLink ).toBeVisible();
	} );

	test( 'Dashboard page loads', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php?page=mission' );

		await expect(
			page.locator( '.wrap h1', { hasText: 'Mission' } )
		).toBeVisible();
		await expect(
			page.locator( '.wrap', { hasText: 'Dashboard coming soon.' } )
		).toBeVisible();
	} );
} );
