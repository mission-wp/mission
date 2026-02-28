/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const SETTINGS_PATH = 'admin.php?page=mission-settings';

const DISCONNECTED_SETTINGS = {
	currency: 'USD',
	stripe_site_id: '',
	stripe_account_id: '',
	stripe_connection_status: 'disconnected',
	stripe_display_name: '',
	email_from_name: 'Test Blog',
	email_from_address: 'admin@example.org',
};

const CONNECTED_SETTINGS = {
	...DISCONNECTED_SETTINGS,
	stripe_site_id: 'site_abc123',
	stripe_account_id: 'acct_1234567890',
	stripe_connection_status: 'connected',
	stripe_display_name: 'Test Nonprofit',
};

/**
 * Regex that matches a REST route in both /wp-json/ and ?rest_route= formats.
 *
 * @param {string} route REST route path, e.g. '/mission/v1/settings'.
 * @return {RegExp} Pattern for page.route().
 */
function restRoute( route ) {
	// Strip leading slash, replace / with a pattern matching both / and %2F.
	const pattern = route.slice( 1 ).replace( /\//g, '(\\/|%2F)' );
	return new RegExp( pattern );
}

/**
 * Intercept the GET /settings endpoint with the given response.
 *
 * @param {import('@playwright/test').Page} page     Playwright page.
 * @param {Object}                          settings Settings object to return.
 */
async function mockSettingsGet( page, settings ) {
	await page.route(
		restRoute( '/mission/v1/settings' ),
		async ( route, request ) => {
			if ( request.method() === 'GET' ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( settings ),
				} );
			} else {
				await route.continue();
			}
		}
	);
}

test.describe( 'Settings — Stripe Connection', () => {
	test.describe( 'Disconnected state', () => {
		test( 'shows Connect to Stripe button', async ( { admin, page } ) => {
			await mockSettingsGet( page, DISCONNECTED_SETTINGS );
			await admin.visitAdminPage( SETTINGS_PATH );

			const connectButton = page.getByRole( 'link', {
				name: 'Connect to Stripe',
			} );
			await expect( connectButton ).toBeVisible();
		} );

		test( 'Connect to Stripe links to the API connect URL', async ( {
			admin,
			page,
		} ) => {
			await mockSettingsGet( page, DISCONNECTED_SETTINGS );
			await admin.visitAdminPage( SETTINGS_PATH );

			const connectButton = page.getByRole( 'link', {
				name: 'Connect to Stripe',
			} );
			const href = await connectButton.getAttribute( 'href' );

			expect( href ).toContain( 'api.missionwp.com/connect/start' );
			expect( href ).toContain( 'return_url=' );
		} );

		test( 'does not show connected badge or Disconnect button', async ( {
			admin,
			page,
		} ) => {
			await mockSettingsGet( page, DISCONNECTED_SETTINGS );
			await admin.visitAdminPage( SETTINGS_PATH );

			await expect(
				page.getByText( 'Connected', { exact: true } )
			).not.toBeVisible();
			await expect(
				page.getByRole( 'button', { name: 'Disconnect' } )
			).not.toBeVisible();
		} );
	} );

	test.describe( 'OAuth return flow', () => {
		test( 'exchanges setup_code for connection and shows success', async ( {
			admin,
			page,
		} ) => {
			await mockSettingsGet( page, DISCONNECTED_SETTINGS );

			await page.route(
				restRoute( '/mission/v1/stripe/connect' ),
				async ( route ) => {
					await route.fulfill( {
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify( CONNECTED_SETTINGS ),
					} );
				}
			);

			await admin.visitAdminPage(
				`${ SETTINGS_PATH }&setup_code=sc_test123&site_id=site_abc123`
			);

			// Should show success notice.
			await expect(
				page.locator( '.components-notice__content', {
					hasText: 'Stripe connected successfully!',
				} )
			).toBeVisible();

			// Should show connected state.
			await expect(
				page.getByText( 'Connected — Test Nonprofit' )
			).toBeVisible();
			await expect( page.getByText( 'acct_1234567890' ) ).toBeVisible();

			// URL should be cleaned (no setup_code/site_id).
			expect( page.url() ).not.toContain( 'setup_code' );
			expect( page.url() ).not.toContain( 'site_id' );
		} );

		test( 'shows error when connect API call fails', async ( {
			admin,
			page,
		} ) => {
			await mockSettingsGet( page, DISCONNECTED_SETTINGS );

			await page.route(
				restRoute( '/mission/v1/stripe/connect' ),
				async ( route ) => {
					await route.fulfill( {
						status: 400,
						contentType: 'application/json',
						body: JSON.stringify( {
							code: 'mission_connect_failed',
							message: 'Invalid setup code.',
							data: { status: 400 },
						} ),
					} );
				}
			);

			await admin.visitAdminPage(
				`${ SETTINGS_PATH }&setup_code=sc_invalid&site_id=site_abc123`
			);

			await expect(
				page.locator( '.components-notice__content', {
					hasText: 'Invalid setup code.',
				} )
			).toBeVisible();

			// Should still show the Connect button (not connected).
			await expect(
				page.getByRole( 'link', { name: 'Connect to Stripe' } )
			).toBeVisible();
		} );
	} );

	test.describe( 'Connected state', () => {
		test( 'shows connected badge with display name', async ( {
			admin,
			page,
		} ) => {
			await mockSettingsGet( page, CONNECTED_SETTINGS );
			await admin.visitAdminPage( SETTINGS_PATH );

			await expect(
				page.getByText( 'Connected — Test Nonprofit' )
			).toBeVisible();
		} );

		test( 'shows account ID', async ( { admin, page } ) => {
			await mockSettingsGet( page, CONNECTED_SETTINGS );
			await admin.visitAdminPage( SETTINGS_PATH );

			await expect( page.getByText( 'acct_1234567890' ) ).toBeVisible();
		} );

		test( 'does not show Connect to Stripe button', async ( {
			admin,
			page,
		} ) => {
			await mockSettingsGet( page, CONNECTED_SETTINGS );
			await admin.visitAdminPage( SETTINGS_PATH );

			await expect(
				page.getByRole( 'link', { name: 'Connect to Stripe' } )
			).not.toBeVisible();
		} );
	} );

	test.describe( 'Disconnect flow', () => {
		test( 'opens confirmation modal on Disconnect click', async ( {
			admin,
			page,
		} ) => {
			await mockSettingsGet( page, CONNECTED_SETTINGS );
			await admin.visitAdminPage( SETTINGS_PATH );

			await page.getByRole( 'button', { name: 'Disconnect' } ).click();

			await expect(
				page.getByRole( 'dialog', { name: 'Disconnect Stripe' } )
			).toBeVisible();
			await expect(
				page.getByText( 'You will not be able to process donations' )
			).toBeVisible();
		} );

		test( 'closes modal on Cancel', async ( { admin, page } ) => {
			await mockSettingsGet( page, CONNECTED_SETTINGS );
			await admin.visitAdminPage( SETTINGS_PATH );

			await page.getByRole( 'button', { name: 'Disconnect' } ).click();

			const modal = page.getByRole( 'dialog', {
				name: 'Disconnect Stripe',
			} );
			await expect( modal ).toBeVisible();

			await modal.getByRole( 'button', { name: 'Cancel' } ).click();

			await expect( modal ).not.toBeVisible();

			// Should still be connected.
			await expect(
				page.getByText( 'Connected — Test Nonprofit' )
			).toBeVisible();
		} );

		test( 'disconnects after confirming', async ( { admin, page } ) => {
			await mockSettingsGet( page, CONNECTED_SETTINGS );

			await page.route(
				restRoute( '/mission/v1/stripe/disconnect' ),
				async ( route ) => {
					await route.fulfill( {
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify( DISCONNECTED_SETTINGS ),
					} );
				}
			);

			await admin.visitAdminPage( SETTINGS_PATH );

			await page.getByRole( 'button', { name: 'Disconnect' } ).click();

			const modal = page.getByRole( 'dialog', {
				name: 'Disconnect Stripe',
			} );

			// Click the Disconnect button inside the modal.
			await modal.getByRole( 'button', { name: 'Disconnect' } ).click();

			// Modal should close.
			await expect( modal ).not.toBeVisible();

			// Should show success notice.
			const notice = page.locator( '.components-notice__content', {
				hasText: 'Stripe disconnected.',
			} );
			await expect( notice ).toBeVisible();

			// Should show Connect button again.
			await expect(
				page.getByRole( 'link', { name: 'Connect to Stripe' } )
			).toBeVisible();
		} );
	} );

	test.describe( 'Error state', () => {
		test( 'shows error indicator when connection status is error', async ( {
			admin,
			page,
		} ) => {
			await mockSettingsGet( page, {
				...DISCONNECTED_SETTINGS,
				stripe_connection_status: 'error',
			} );

			await admin.visitAdminPage( SETTINGS_PATH );

			await expect( page.getByText( 'Connection error' ) ).toBeVisible();

			// Should still show Connect button to retry.
			await expect(
				page.getByRole( 'link', { name: 'Connect to Stripe' } )
			).toBeVisible();
		} );
	} );
} );
