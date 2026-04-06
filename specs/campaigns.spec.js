/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const CAMPAIGNS_PATH = 'admin.php?page=mission-campaigns';

/**
 * Regex that matches a REST route in both /wp-json/ and ?rest_route= formats.
 *
 * @param {string} route REST route path, e.g. '/mission/v1/campaigns'.
 * @return {RegExp} Pattern for page.route().
 */
function restRoute( route ) {
  const pattern = route.slice( 1 ).replace( /\//g, '(\\/|%2F)' );
  return new RegExp( pattern );
}

/**
 * Build a mock summary object from a campaigns array.
 *
 * @param {Array} campaigns Campaign items.
 * @return {Object} Summary response matching /campaigns/summary shape.
 */
function buildMockSummary( campaigns ) {
  const totalRaised = campaigns.reduce(
    ( sum, c ) => sum + ( c.total_raised || 0 ),
    0
  );
  return {
    total_campaigns: campaigns.length,
    active: campaigns.filter( ( c ) => c.status === 'active' ).length,
    ended: campaigns.filter( ( c ) => c.status === 'ended' ).length,
    scheduled: campaigns.filter( ( c ) => c.status === 'scheduled' ).length,
    total_raised: totalRaised,
    average_per_campaign: campaigns.length
      ? Math.round( totalRaised / campaigns.length )
      : 0,
    top_campaign_name: campaigns[ 0 ]?.title || null,
    top_campaign_raised: campaigns[ 0 ]?.total_raised || null,
  };
}

/**
 * Intercept GET /campaigns with the given response data.
 *
 * @param {import('@playwright/test').Page} page      Playwright page.
 * @param {Array}                           campaigns Campaign items to return.
 * @param {Object}                          headers   Optional response headers.
 */
async function mockCampaignsApi( page, campaigns, headers = {} ) {
  await page.route(
    restRoute( '/mission/v1/campaigns' ),
    async ( route, request ) => {
      const url = request.url();

      // Summary endpoint — return a mock summary object so stat cards render.
      if ( request.method() === 'GET' && /summary/.test( url ) ) {
        await route.fulfill( {
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify( buildMockSummary( campaigns ) ),
        } );
      } else if ( request.method() === 'GET' ) {
        await route.fulfill( {
          status: 200,
          contentType: 'application/json',
          headers: {
            'X-WP-Total': String( headers.total ?? campaigns.length ),
            'X-WP-TotalPages': String( headers.totalPages ?? 1 ),
          },
          body: JSON.stringify( campaigns ),
        } );
      } else if ( /campaigns(%2F|\/)\d+/.test( url ) ) {
        // Delete — apiFetch sends DELETE as POST with method override
        // when WordPress uses plain (?rest_route=) permalinks.
        await route.fulfill( {
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify( { deleted: true, id: 1 } ),
        } );
      } else if ( request.method() === 'POST' ) {
        const body = request.postDataJSON();
        await route.fulfill( {
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify( {
            id: 99,
            title: body.title,
            excerpt: body.excerpt || '',
            status: 'active',
            edit_url: '/wp-admin/post.php?post=99&action=edit',
            url: '/?p=99',
            date_created: '2026-02-28 12:00:00',
            goal_amount: body.goal_amount || 0,
            total_raised: 0,
            transaction_count: 0,
            currency: 'usd',
            date_start: null,
            date_end: null,
            meta: {
              close_on_goal: null,
              stop_donations_on_end: null,
              show_ended_message: null,
              remove_from_listings_on_end: null,
              recurring_end_behavior: null,
              recurring_redirect_campaign: null,
            },
          } ),
        } );
      } else {
        await route.continue();
      }
    }
  );
}

const MOCK_CAMPAIGNS = [
  {
    id: 1,
    title: 'Annual Fundraiser',
    status: 'active',
    goal_amount: 100000,
    total_raised: 45000,
    transaction_count: 23,
    edit_url: '/wp-admin/post.php?post=1&action=edit',
    date_start: '2026-01-01',
    date_end: '2026-12-31',
  },
  {
    id: 2,
    title: 'Emergency Relief',
    status: 'scheduled',
    goal_amount: 50000,
    total_raised: 0,
    transaction_count: 0,
    edit_url: '/wp-admin/post.php?post=2&action=edit',
    date_start: '2027-01-01',
    date_end: null,
  },
];

const MOCK_SINGLE_CAMPAIGN = {
  id: 1,
  title: 'Annual Fundraiser',
  excerpt: 'Our yearly fundraiser.',
  status: 'active',
  edit_url: '/wp-admin/post.php?post=1&action=edit',
  url: '/?p=1',
  post_id: 1,
  date_created: '2026-01-15 12:00:00',
  goal_amount: 100000,
  total_raised: 45000,
  transaction_count: 23,
  currency: 'usd',
  date_start: '2026-01-01',
  date_end: '2026-12-31',
  has_campaign_page: true,
  show_in_listings: true,
  slug: 'annual-fundraiser',
  milestones: [
    { id: 'created', reached: true, date: '2026-01-15' },
    { id: 'first-donation', reached: true, date: '2026-01-20' },
    { id: '25-pct', reached: true, date: '2026-02-10' },
    { id: '50-pct', reached: false, date: null },
    { id: '75-pct', reached: false, date: null },
    { id: '100-pct', reached: false, date: null },
  ],
  meta: {
    close_on_goal: null,
    stop_donations_on_end: null,
    show_ended_message: null,
    remove_from_listings_on_end: null,
    recurring_end_behavior: null,
    recurring_redirect_campaign: null,
  },
};

test.describe( 'Campaigns Page', () => {
  test( 'empty state renders with Create a Campaign button', async ( {
    admin,
    page,
  } ) => {
    await mockCampaignsApi( page, [] );
    await admin.visitAdminPage( CAMPAIGNS_PATH );

    await expect( page.getByText( 'No campaigns yet' ) ).toBeVisible();

    const createButton = page.getByRole( 'button', {
      name: 'Create a Campaign',
    } );
    await expect( createButton ).toBeVisible();
  } );

  test( 'Create a Campaign button opens modal', async ( { admin, page } ) => {
    await mockCampaignsApi( page, [] );
    await admin.visitAdminPage( CAMPAIGNS_PATH );

    await page.getByRole( 'button', { name: 'Create a Campaign' } ).click();

    const modal = page.getByRole( 'dialog' );
    await expect( modal ).toBeVisible();
    await expect(
      modal.getByRole( 'heading', { name: 'Create Campaign' } )
    ).toBeVisible();
  } );

  test( 'campaign list renders with data', async ( { admin, page } ) => {
    await mockCampaignsApi( page, MOCK_CAMPAIGNS );
    await admin.visitAdminPage( CAMPAIGNS_PATH );

    await expect(
      page.getByRole( 'heading', { name: 'Campaigns', level: 1 } )
    ).toBeVisible();

    const table = page.getByRole( 'table' );
    await expect( table.getByText( 'Annual Fundraiser' ) ).toBeVisible();
    await expect( table.getByText( 'Emergency Relief' ) ).toBeVisible();

    await expect( page.getByText( 'Active' ).first() ).toBeVisible();
    await expect( page.getByText( 'Scheduled' ).first() ).toBeVisible();
  } );

  test( 'Add Campaign button opens modal', async ( { admin, page } ) => {
    await mockCampaignsApi( page, MOCK_CAMPAIGNS );
    await admin.visitAdminPage( CAMPAIGNS_PATH );

    await page.getByRole( 'button', { name: 'Add Campaign' } ).click();

    const modal = page.getByRole( 'dialog' );
    await expect( modal ).toBeVisible();
  } );

  test( 'modal steps navigate correctly', async ( { admin, page } ) => {
    await mockCampaignsApi( page, MOCK_CAMPAIGNS );
    await admin.visitAdminPage( CAMPAIGNS_PATH );

    await page.getByRole( 'button', { name: 'Add Campaign' } ).click();

    const modal = page.getByRole( 'dialog' );

    // Step 1: Next should be disabled without title.
    const continueButton = modal.getByRole( 'button', { name: 'Continue' } );
    await expect( continueButton ).toBeDisabled();

    // Fill title and proceed.
    await modal
      .getByRole( 'textbox', { name: 'Campaign Name' } )
      .fill( 'Test Campaign' );
    await expect( continueButton ).toBeEnabled();
    await continueButton.click();

    // Step 2: Should see Goal Amount field.
    await expect(
      modal.getByRole( 'spinbutton', { name: /Fundraising Goal/ } )
    ).toBeVisible();

    // Goal is required to proceed — fill one in.
    await modal
      .getByRole( 'spinbutton', { name: /Fundraising Goal/ } )
      .fill( '1000' );
    await continueButton.click();

    // Step 3: Should see Create Campaign button.
    await expect(
      modal.getByRole( 'button', { name: 'Create Campaign' } )
    ).toBeVisible();

    // Back button should go to step 2.
    await modal.getByRole( 'button', { name: 'Back' } ).click();
    await expect(
      modal.getByRole( 'spinbutton', { name: /Fundraising Goal/ } )
    ).toBeVisible();
  } );

  test( 'campaign creation submits and redirects to detail view', async ( {
    admin,
    page,
  } ) => {
    await mockCampaignsApi( page, MOCK_CAMPAIGNS );
    await admin.visitAdminPage( CAMPAIGNS_PATH );

    await page.getByRole( 'button', { name: 'Add Campaign' } ).click();

    const modal = page.getByRole( 'dialog' );

    // Step 1.
    await modal
      .getByRole( 'textbox', { name: 'Campaign Name' } )
      .fill( 'New Test Campaign' );
    await modal.getByRole( 'button', { name: 'Continue' } ).click();

    // Step 2 — fill goal and continue.
    await modal
      .getByRole( 'spinbutton', { name: /Fundraising Goal/ } )
      .fill( '5000' );
    await modal.getByRole( 'button', { name: 'Continue' } ).click();

    // Step 3 — submit.
    const submitButton = modal.getByRole( 'button', {
      name: 'Create Campaign',
    } );

    // Expect navigation to detail view after submit.
    const navigationPromise = page.waitForURL( /campaign=99/ );
    await submitButton.click();
    await navigationPromise;

    expect( page.url() ).toContain( 'campaign=99' );
  } );

  test( 'delete action shows confirmation modal', async ( { admin, page } ) => {
    await mockCampaignsApi( page, MOCK_CAMPAIGNS );
    await admin.visitAdminPage( CAMPAIGNS_PATH );

    // Wait for data to load.
    const table = page.getByRole( 'table' );
    await expect( table.getByText( 'Annual Fundraiser' ) ).toBeVisible();

    // Open the actions menu on the first row.
    const firstRow = page.getByRole( 'row' ).nth( 1 );
    await firstRow.getByRole( 'button', { name: 'Actions' } ).click();

    // Click Delete.
    await page.getByRole( 'menuitem', { name: 'Delete' } ).click();

    // Confirmation modal should appear.
    const modal = page.getByRole( 'dialog' );
    await expect( modal ).toBeVisible();
    await expect(
      modal.getByText( 'Are you sure you want to delete this campaign?' )
    ).toBeVisible();
    await expect( modal.getByText( 'Annual Fundraiser' ) ).toBeVisible();

    // Cancel should close the modal.
    await modal.getByRole( 'button', { name: 'Cancel' } ).click();
    await expect( modal ).not.toBeVisible();
  } );

  test( 'delete confirm removes campaign without errors', async ( {
    admin,
    page,
  } ) => {
    let deleteRequested = false;

    await page.route(
      restRoute( '/mission/v1/campaigns' ),
      async ( route, request ) => {
        const url = request.url();

        if ( request.method() === 'GET' && /summary/.test( url ) ) {
          const items = deleteRequested
            ? [ MOCK_CAMPAIGNS[ 1 ] ]
            : MOCK_CAMPAIGNS;
          await route.fulfill( {
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify( buildMockSummary( items ) ),
          } );
        } else if ( request.method() === 'GET' ) {
          const items = deleteRequested
            ? [ MOCK_CAMPAIGNS[ 1 ] ]
            : MOCK_CAMPAIGNS;
          await route.fulfill( {
            status: 200,
            contentType: 'application/json',
            headers: {
              'X-WP-Total': String( items.length ),
              'X-WP-TotalPages': '1',
            },
            body: JSON.stringify( items ),
          } );
        } else if (
          // apiFetch sends DELETE as POST with method override when
          // WordPress uses plain (?rest_route=) permalinks.
          /campaigns(%2F|\/)\d+/.test( url ) &&
          ! /summary/.test( url )
        ) {
          deleteRequested = true;
          await route.fulfill( {
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify( { deleted: true, id: 1 } ),
          } );
        } else {
          await route.continue();
        }
      }
    );

    // Collect console errors.
    const errors = [];
    page.on( 'pageerror', ( error ) => errors.push( error.message ) );

    await admin.visitAdminPage( CAMPAIGNS_PATH );
    await expect(
      page.getByRole( 'table' ).getByText( 'Annual Fundraiser' )
    ).toBeVisible();

    // Open actions menu and click Delete.
    const firstRow = page.getByRole( 'row' ).nth( 1 );
    await firstRow.getByRole( 'button', { name: 'Actions' } ).click();
    await page.getByRole( 'menuitem', { name: 'Delete' } ).click();

    // Confirm deletion.
    const modal = page.getByRole( 'dialog' );
    await modal.getByRole( 'button', { name: 'Delete' } ).click();

    // Modal should close and campaign should disappear from the table.
    const table = page.getByRole( 'table' );
    await expect( modal ).not.toBeVisible();
    await expect( table.getByText( 'Annual Fundraiser' ) ).not.toBeVisible();
    await expect( table.getByText( 'Emergency Relief' ) ).toBeVisible();

    // No JS errors should have occurred.
    expect( errors ).toHaveLength( 0 );
  } );
} );

test.describe( 'Campaign Detail View', () => {
  /**
   * Mock campaign detail + transactions endpoints for detail view tests.
   * @param page
   */
  async function mockDetailApis( page ) {
    await page.route(
      restRoute( '/mission/v1/campaigns' ),
      async ( route, request ) => {
        if (
          request.method() === 'GET' &&
          /campaigns(%2F|\/)\d+/.test( request.url() ) &&
          ! /summary/.test( request.url() )
        ) {
          await route.fulfill( {
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify( MOCK_SINGLE_CAMPAIGN ),
          } );
        } else {
          await route.continue();
        }
      }
    );

    // OverviewTab fetches transactions for this campaign.
    await page.route(
      restRoute( '/mission/v1/transactions' ),
      async ( route ) => {
        await route.fulfill( {
          status: 200,
          contentType: 'application/json',
          headers: {
            'X-WP-Total': '0',
            'X-WP-TotalPages': '0',
          },
          body: JSON.stringify( [] ),
        } );
      }
    );
  }

  test( 'detail view renders hero and overview tab', async ( {
    admin,
    page,
  } ) => {
    // Ensure we land on the Overview tab.
    await page.addInitScript( () => {
      try {
        window.localStorage.removeItem( 'mission_campaign_tab_1' );
      } catch {}
    } );

    await mockDetailApis( page );
    await admin.visitAdminPage( CAMPAIGNS_PATH + '&campaign=1' );

    // Title and status.
    await expect(
      page.getByRole( 'heading', { name: 'Annual Fundraiser' } )
    ).toBeVisible();
    await expect( page.getByText( 'Active' ).first() ).toBeVisible();

    // Progress stats in hero.
    await expect( page.getByText( '$450.00' ) ).toBeVisible();
    await expect( page.getByText( '45% funded' ) ).toBeVisible();

    // Overview tab sections.
    await expect(
      page.getByRole( 'heading', { name: 'Donations' } )
    ).toBeVisible();
    await expect(
      page.getByRole( 'heading', { name: 'Milestones' } )
    ).toBeVisible();

    // Milestone items render.
    await expect( page.getByText( 'Campaign created' ) ).toBeVisible();
    await expect( page.getByText( 'First donation received' ) ).toBeVisible();
  } );

  test( 'Edit Page tab shows visibility and save controls', async ( {
    admin,
    page,
  } ) => {
    // Ensure we land on the Overview tab first.
    await page.addInitScript( () => {
      try {
        window.localStorage.removeItem( 'mission_campaign_tab_1' );
      } catch {}
    } );

    await mockDetailApis( page );
    await admin.visitAdminPage( CAMPAIGNS_PATH + '&campaign=1' );

    // Wait for the page to load.
    await expect(
      page.getByRole( 'heading', { name: 'Annual Fundraiser' } )
    ).toBeVisible();

    // Switch to Edit Page tab.
    await page.getByRole( 'button', { name: 'Edit Page' } ).click();

    // Edit Page tab content should be visible.
    await expect(
      page.getByRole( 'heading', { name: 'Visibility' } )
    ).toBeVisible();
    await expect(
      page.getByRole( 'button', { name: 'Save Changes' } )
    ).toBeVisible();
  } );
} );
