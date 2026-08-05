/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const SETTINGS_PATH = 'admin.php?page=mission-donation-platform-settings';

const DISCONNECTED_SETTINGS = {
  currency: 'USD',
  stripe_accounts: [],
  stripe_connection_status: 'disconnected',
  stripe_charges_enabled: false,
  email_from_name: 'Test Blog',
  email_from_address: 'admin@example.org',
};

const TEST_ACCOUNT = {
  account_id: 'acct_1234567890',
  display_name: 'Test Nonprofit',
  connection_status: 'connected',
  charges_enabled: true,
  is_default: true,
  connected_at: '2026-01-01 00:00:00',
};

const SECOND_ACCOUNT = {
  account_id: 'acct_0987654321',
  display_name: 'Second Nonprofit',
  connection_status: 'connected',
  charges_enabled: true,
  is_default: false,
  connected_at: '2026-01-02 00:00:00',
};

const CONNECTED_SETTINGS = {
  ...DISCONNECTED_SETTINGS,
  stripe_accounts: [ TEST_ACCOUNT ],
  stripe_connection_status: 'connected',
  stripe_charges_enabled: true,
};

/**
 * Regex that matches a REST route in both /wp-json/ and ?rest_route= formats.
 *
 * @param {string} route REST route path, e.g. '/mission-donation-platform/v1/settings'.
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
    restRoute( '/mission-donation-platform/v1/settings' ),
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
    test( 'shows Connect with Stripe button', async ( { admin, page } ) => {
      await mockSettingsGet( page, DISCONNECTED_SETTINGS );
      await admin.visitAdminPage( SETTINGS_PATH );

      const connectButton = page.getByRole( 'link', {
        name: 'Connect with Stripe',
      } );
      await expect( connectButton ).toBeVisible();
    } );

    test( 'Connect with Stripe links to the API connect URL', async ( {
      admin,
      page,
    } ) => {
      await mockSettingsGet( page, DISCONNECTED_SETTINGS );
      await admin.visitAdminPage( SETTINGS_PATH );

      const connectButton = page.getByRole( 'link', {
        name: 'Connect with Stripe',
      } );
      const href = await connectButton.getAttribute( 'href' );

      expect( href ).toContain( 'api.missionwp.com/connect/start' );
      expect( href ).toContain( 'return_url=' );
    } );

    test( 'does not show a connected account or Disconnect button', async ( {
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
        restRoute( '/mission-donation-platform/v1/stripe/connect' ),
        async ( route ) => {
          await route.fulfill( {
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify( CONNECTED_SETTINGS ),
          } );
        }
      );

      await admin.visitAdminPage(
        `${ SETTINGS_PATH }&setup_code=sc_test123&site_id=site_abc123&charges_enabled=1`
      );

      // Should show success toast.
      await expect(
        page.locator( '.mission-toast', {
          hasText: 'Stripe connected successfully!',
        } )
      ).toBeVisible();

      // Should show the connected account row.
      await expect( page.getByText( 'Test Nonprofit' ) ).toBeVisible();
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
        restRoute( '/mission-donation-platform/v1/stripe/connect' ),
        async ( route ) => {
          await route.fulfill( {
            status: 400,
            contentType: 'application/json',
            body: JSON.stringify( {
              code: 'missiondp_connect_failed',
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
        page.locator( '.mission-toast', {
          hasText: 'Invalid setup code.',
        } )
      ).toBeVisible();

      // Should still show the Connect button (not connected).
      await expect(
        page.getByRole( 'link', { name: 'Connect with Stripe' } )
      ).toBeVisible();
    } );
  } );

  test.describe( 'Connected state', () => {
    test( 'shows the connected account with display name and status', async ( {
      admin,
      page,
    } ) => {
      await mockSettingsGet( page, CONNECTED_SETTINGS );
      await admin.visitAdminPage( SETTINGS_PATH );

      await expect( page.getByText( 'Test Nonprofit' ) ).toBeVisible();
      await expect( page.getByText( 'acct_1234567890' ) ).toBeVisible();
      await expect(
        page.getByText( 'Connected', { exact: true } )
      ).toBeVisible();
    } );

    test( 'offers to connect another account instead of a first one', async ( {
      admin,
      page,
    } ) => {
      await mockSettingsGet( page, CONNECTED_SETTINGS );
      await admin.visitAdminPage( SETTINGS_PATH );

      await expect(
        page.getByRole( 'link', { name: 'Connect another Stripe account' } )
      ).toBeVisible();
      await expect(
        page.getByRole( 'link', { name: 'Connect with Stripe' } )
      ).not.toBeVisible();
    } );

    test( 'shows Default badge and Make default with multiple accounts', async ( {
      admin,
      page,
    } ) => {
      await mockSettingsGet( page, {
        ...CONNECTED_SETTINGS,
        stripe_accounts: [ TEST_ACCOUNT, SECOND_ACCOUNT ],
      } );
      await admin.visitAdminPage( SETTINGS_PATH );

      await expect(
        page.getByText( 'Default', { exact: true } )
      ).toBeVisible();
      await expect(
        page.getByRole( 'button', { name: 'Make default' } )
      ).toBeVisible();
    } );

    test( 'warns when an account cannot accept charges', async ( {
      admin,
      page,
    } ) => {
      await mockSettingsGet( page, {
        ...CONNECTED_SETTINGS,
        stripe_accounts: [ { ...TEST_ACCOUNT, charges_enabled: false } ],
        stripe_charges_enabled: false,
      } );
      await admin.visitAdminPage( SETTINGS_PATH );

      await expect(
        page.getByText(
          'Your default Stripe account isn’t ready to accept charges.'
        )
      ).toBeVisible();
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

      const modal = page.getByRole( 'dialog', { name: 'Disconnect Stripe' } );
      await expect( modal ).toBeVisible();
      await expect(
        modal.getByText( 'This is your default Stripe account' )
      ).toBeVisible();
      await expect(
        modal.getByText( 'Test Nonprofit (acct_1234567890)' )
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
      await expect( page.getByText( 'Test Nonprofit' ) ).toBeVisible();
    } );

    test( 'disconnects after confirming', async ( { admin, page } ) => {
      await mockSettingsGet( page, CONNECTED_SETTINGS );

      await page.route(
        restRoute( '/mission-donation-platform/v1/stripe/disconnect' ),
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

      // Should show success toast.
      await expect(
        page.locator( '.mission-toast', {
          hasText: 'Stripe disconnected.',
        } )
      ).toBeVisible();

      // Should show the first-connection button again.
      await expect(
        page.getByRole( 'link', { name: 'Connect with Stripe' } )
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
        page.getByRole( 'link', { name: 'Connect with Stripe' } )
      ).toBeVisible();
    } );
  } );
} );
