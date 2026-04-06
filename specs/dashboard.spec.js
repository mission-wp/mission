/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const DASHBOARD_PATH = 'admin.php?page=mission';

/**
 * Regex that matches a REST route in both /wp-json/ and ?rest_route= formats.
 *
 * @param {string} route REST route path, e.g. '/mission/v1/dashboard'.
 * @return {RegExp} Pattern for page.route().
 */
function restRoute( route ) {
  const pattern = route.slice( 1 ).replace( /\//g, '(\\/|%2F)' );
  return new RegExp( pattern );
}

/**
 * Generate a UTC timestamp string in MySQL format, offset from now.
 *
 * @param {number} offsetMs Milliseconds to subtract from now.
 * @return {string} Timestamp like "2026-03-13 14:30:00".
 */
function utcTimestamp( offsetMs = 0 ) {
  return new Date( Date.now() - offsetMs )
    .toISOString()
    .slice( 0, 19 )
    .replace( 'T', ' ' );
}

/**
 * Intercept the GET /dashboard endpoint with the given response data.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {Object}                          data Dashboard response data.
 */
async function mockDashboardApi( page, data ) {
  await page.route( restRoute( '/mission/v1/dashboard' ), async ( route ) => {
    await route.fulfill( {
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify( data ),
    } );
  } );
}

/**
 * Intercept the GET /dashboard endpoint, returning different data per period.
 *
 * @param {import('@playwright/test').Page} page    Playwright page.
 * @param {Object}                          dataMap Map of period → response data.
 */
async function mockDashboardByPeriod( page, dataMap ) {
  await page.route(
    restRoute( '/mission/v1/dashboard' ),
    async ( route, request ) => {
      const url = request.url();
      let data = dataMap.month;
      if ( url.includes( 'period=week' ) ) {
        data = dataMap.week;
      } else if ( url.includes( 'period=today' ) ) {
        data = dataMap.today;
      }

      await route.fulfill( {
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify( data ),
      } );
    }
  );
}

// --- Mock data constants ---

const MOCK_DASHBOARD = {
  stats: {
    total_donations: 150000,
    total_donors: 42,
    average_donation: 3571,
    total_donations_previous: 120000,
    total_donors_previous: 35,
    average_donation_previous: 3428,
  },
  chart: [
    { date: '2026-03-07', amount: 25000 },
    { date: '2026-03-08', amount: 18000 },
    { date: '2026-03-09', amount: 32000 },
    { date: '2026-03-10', amount: 0 },
    { date: '2026-03-11', amount: 45000 },
    { date: '2026-03-12', amount: 12000 },
    { date: '2026-03-13', amount: 18000 },
  ],
  campaigns: [
    { id: 1, title: 'Annual Fund', total_raised: 75000, goal_amount: 100000 },
    { id: 2, title: 'Building Fund', total_raised: 30000, goal_amount: 50000 },
  ],
  activity: [
    {
      id: 1,
      event: 'donation_completed',
      object_type: 'transaction',
      object_id: 10,
      data: {
        donor_name: 'Jane Smith',
        amount: 5000,
        campaign_title: 'Annual Fund',
      },
      date_created: utcTimestamp( 0 ),
    },
    {
      id: 2,
      event: 'subscription_created',
      object_type: 'subscription',
      object_id: 5,
      data: {
        donor_name: 'Bob Wilson',
        amount: 2500,
        frequency: 'monthly',
      },
      date_created: utcTimestamp( 300000 ),
    },
    {
      id: 3,
      event: 'campaign_created',
      object_type: 'campaign',
      object_id: 3,
      data: { title: 'Holiday Drive' },
      date_created: utcTimestamp( 3600000 ),
    },
  ],
  stripe_connected: true,
  currency: 'USD',
};

const EMPTY_DASHBOARD = {
  stats: {
    total_donations: 0,
    total_donors: 0,
    average_donation: 0,
    total_donations_previous: 0,
    total_donors_previous: 0,
    average_donation_previous: 0,
  },
  chart: [
    { date: '2026-03-07', amount: 0 },
    { date: '2026-03-08', amount: 0 },
    { date: '2026-03-09', amount: 0 },
    { date: '2026-03-10', amount: 0 },
    { date: '2026-03-11', amount: 0 },
    { date: '2026-03-12', amount: 0 },
    { date: '2026-03-13', amount: 0 },
  ],
  campaigns: [],
  activity: [],
  stripe_connected: true,
  currency: 'USD',
};

const MOCK_WEEK_DATA = {
  ...MOCK_DASHBOARD,
  stats: {
    ...MOCK_DASHBOARD.stats,
    total_donations: 80000,
    total_donors: 18,
    average_donation: 4444,
  },
};

const MOCK_TODAY_DATA = {
  ...MOCK_DASHBOARD,
  stats: {
    ...MOCK_DASHBOARD.stats,
    total_donations: 25000,
    total_donors: 5,
    average_donation: 5000,
  },
};

const MOCK_NEGATIVE_DELTA = {
  ...MOCK_DASHBOARD,
  stats: {
    total_donations: 100000,
    total_donors: 25,
    average_donation: 4000,
    total_donations_previous: 150000,
    total_donors_previous: 40,
    average_donation_previous: 5000,
  },
};

const MOCK_ZERO_PREVIOUS = {
  ...MOCK_DASHBOARD,
  stats: {
    total_donations: 150000,
    total_donors: 42,
    average_donation: 3571,
    total_donations_previous: 0,
    total_donors_previous: 0,
    average_donation_previous: 0,
  },
};

// Clear persisted period before each test so default is always "month".
test.beforeEach( async ( { page } ) => {
  await page.addInitScript( () => {
    localStorage.removeItem( 'mission_dashboard_period' );
  } );
} );

test.describe( 'Dashboard — Loaded State', () => {
  test( 'stats cards render correct values', async ( { admin, page } ) => {
    await mockDashboardApi( page, MOCK_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    // Total Donations: 150000 minor units = $1,500.00.
    await expect( page.getByText( '$1,500.00' ) ).toBeVisible();

    // Total Donors: 42.
    await expect(
      page.locator( '.mission-stat-card', { hasText: 'Total Donors' } )
    ).toContainText( '42' );

    // Avg. Donation: 3571 minor units = $35.71.
    await expect( page.getByText( '$35.71' ) ).toBeVisible();
  } );

  test( 'stats cards show positive delta', async ( { admin, page } ) => {
    await mockDashboardApi( page, MOCK_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    // Wait for data to load.
    await expect( page.getByText( '$1,500.00' ) ).toBeVisible();

    // All three stat cards should have positive deltas.
    const positiveDelta = page.locator(
      '.mission-stat-card__delta.is-positive'
    );
    await expect( positiveDelta ).toHaveCount( 3 );

    // First card (Total Donations): ((150000-120000)/120000)*100 = 25%.
    await expect( positiveDelta.first() ).toContainText( '25%' );
  } );

  test( 'chart renders with data', async ( { admin, page } ) => {
    await mockDashboardApi( page, MOCK_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    await expect( page.getByText( '$1,500.00' ) ).toBeVisible();

    // Canvas element should be present in the chart container.
    await expect(
      page.locator( '.mission-chart-container canvas' )
    ).toBeAttached();

    // Chart card should show the period badge.
    await expect( page.getByText( 'Last 30 days' ) ).toBeVisible();
  } );

  test( 'top campaigns render with titles and amounts', async ( {
    admin,
    page,
  } ) => {
    await mockDashboardApi( page, MOCK_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    const campaignsCard = page.locator( '.mission-dashboard-card', {
      hasText: 'Top Campaigns',
    } );

    await expect(
      campaignsCard.getByText( 'Annual Fund', { exact: true } )
    ).toBeVisible();
    await expect( campaignsCard.getByText( 'Building Fund' ) ).toBeVisible();

    // Amounts: 75000 = $750.00, 30000 = $300.00.
    await expect( campaignsCard.getByText( '$750.00' ) ).toBeVisible();
    await expect( campaignsCard.getByText( '$300.00' ) ).toBeVisible();
  } );

  test( 'top campaigns progress bars are proportional', async ( {
    admin,
    page,
  } ) => {
    await mockDashboardApi( page, MOCK_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    await expect(
      page.locator( '.mission-campaign-bar__name', { hasText: 'Annual Fund' } )
    ).toBeVisible();

    const bars = page.locator( '.mission-campaign-bar__fill' );
    await expect( bars ).toHaveCount( 2 );

    // Annual Fund: 75000/100000 = 75%.
    const firstBarWidth = await bars
      .first()
      .evaluate( ( el ) => el.style.getPropertyValue( '--bar-width' ) );
    expect( firstBarWidth ).toBe( '75%' );

    // Building Fund: 30000/50000 = 60%.
    const secondBarWidth = await bars
      .nth( 1 )
      .evaluate( ( el ) => el.style.getPropertyValue( '--bar-width' ) );
    expect( secondBarWidth ).toBe( '60%' );
  } );

  test( 'activity feed shows event descriptions', async ( { admin, page } ) => {
    await mockDashboardApi( page, MOCK_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    // donation_completed: "Jane Smith donated $50.00 to Annual Fund".
    await expect(
      page.getByText( 'Jane Smith donated $50.00 to Annual Fund' )
    ).toBeVisible();

    // subscription_created: "Bob Wilson started a $25.00/mo recurring donation".
    await expect(
      page.getByText( 'Bob Wilson started a $25.00/mo recurring donation' )
    ).toBeVisible();

    // campaign_created: 'New campaign Holiday Drive was created'.
    await expect(
      page.getByText( 'New campaign Holiday Drive was created' )
    ).toBeVisible();
  } );

  test( 'activity feed shows timestamps', async ( { admin, page } ) => {
    await mockDashboardApi( page, MOCK_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    await expect( page.getByText( 'Jane Smith' ) ).toBeVisible();

    // Each feed item should have a <time> element with text content.
    const timeElements = page.locator( '.mission-feed-time time' );
    await expect( timeElements ).toHaveCount( 3 );

    // Verify they have non-empty text (timeAgo output).
    for ( let i = 0; i < 3; i++ ) {
      await expect( timeElements.nth( i ) ).not.toBeEmpty();
    }
  } );
} );

test.describe( 'Dashboard — Empty State', () => {
  test( 'stats show zero values', async ( { admin, page } ) => {
    await mockDashboardApi( page, EMPTY_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    // Total Donations and Avg. Donation both show $0.00.
    const zeroAmounts = page.getByText( '$0.00' );
    await expect( zeroAmounts.first() ).toBeVisible();

    // Total Donors shows 0.
    await expect(
      page.locator( '.mission-stat-card', { hasText: 'Total Donors' } )
    ).toContainText( '0' );
  } );

  test( 'stats delta is neutral when previous is zero', async ( {
    admin,
    page,
  } ) => {
    await mockDashboardApi( page, EMPTY_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    await expect( page.getByText( '$0.00' ).first() ).toBeVisible();

    // All deltas should be neutral (no arrows, shows "0%").
    const neutralDeltas = page.locator(
      '.mission-stat-card__delta.is-neutral'
    );
    await expect( neutralDeltas ).toHaveCount( 3 );
    await expect( neutralDeltas.first() ).toContainText( '0%' );
  } );

  test( 'chart shows empty message', async ( { admin, page } ) => {
    await mockDashboardApi( page, EMPTY_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    await expect( page.getByText( 'No donations yet' ) ).toBeVisible();
  } );

  test( 'top campaigns shows empty message', async ( { admin, page } ) => {
    await mockDashboardApi( page, EMPTY_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    await expect( page.getByText( 'No campaigns yet' ) ).toBeVisible();
  } );

  test( 'activity feed shows empty message', async ( { admin, page } ) => {
    await mockDashboardApi( page, EMPTY_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    await expect( page.getByText( 'No activity yet' ) ).toBeVisible();
  } );
} );

test.describe( 'Dashboard — Period Toggle', () => {
  test( 'default period is month', async ( { admin, page } ) => {
    await mockDashboardApi( page, MOCK_DASHBOARD );
    await admin.visitAdminPage( DASHBOARD_PATH );

    await expect( page.getByText( '$1,500.00' ) ).toBeVisible();

    const monthButton = page.locator( '.mission-period-toggle__btn', {
      hasText: 'Month',
    } );
    await expect( monthButton ).toHaveClass( /is-active/ );
  } );

  test( 'clicking Week refetches with period=week', async ( {
    admin,
    page,
  } ) => {
    await mockDashboardByPeriod( page, {
      month: MOCK_DASHBOARD,
      week: MOCK_WEEK_DATA,
      today: MOCK_TODAY_DATA,
    } );
    await admin.visitAdminPage( DASHBOARD_PATH );

    // Wait for initial load (month data).
    await expect( page.getByText( '$1,500.00' ) ).toBeVisible();

    // Click Week.
    await page
      .locator( '.mission-period-toggle__btn', { hasText: 'Week' } )
      .click();

    // Should show week data: $800.00 (80000 minor units).
    await expect( page.getByText( '$800.00' ) ).toBeVisible();

    // Week button should now be active.
    await expect(
      page.locator( '.mission-period-toggle__btn', { hasText: 'Week' } )
    ).toHaveClass( /is-active/ );
  } );

  test( 'clicking Today refetches with period=today', async ( {
    admin,
    page,
  } ) => {
    await mockDashboardByPeriod( page, {
      month: MOCK_DASHBOARD,
      week: MOCK_WEEK_DATA,
      today: MOCK_TODAY_DATA,
    } );
    await admin.visitAdminPage( DASHBOARD_PATH );

    await expect( page.getByText( '$1,500.00' ) ).toBeVisible();

    // Click Today.
    await page
      .locator( '.mission-period-toggle__btn', { hasText: 'Today' } )
      .click();

    // Should show today data: $250.00 (25000 minor units).
    await expect( page.getByText( '$250.00' ) ).toBeVisible();

    // Today button should now be active.
    await expect(
      page.locator( '.mission-period-toggle__btn', { hasText: 'Today' } )
    ).toHaveClass( /is-active/ );
  } );
} );

test.describe( 'Dashboard — StatCard Deltas', () => {
  test( 'negative delta shows down arrow', async ( { admin, page } ) => {
    await mockDashboardApi( page, MOCK_NEGATIVE_DELTA );
    await admin.visitAdminPage( DASHBOARD_PATH );

    // Total Donations: 100000, previous: 150000 → -33.3%.
    await expect( page.getByText( '$1,000.00' ) ).toBeVisible();

    const negativeDelta = page.locator(
      '.mission-stat-card__delta.is-negative'
    );
    await expect( negativeDelta.first() ).toBeVisible();
    await expect( negativeDelta.first() ).toContainText( '33.3%' );

    // Down arrow SVG should be present.
    await expect( negativeDelta.first().locator( 'svg' ) ).toBeAttached();
  } );

  test( 'zero previous shows neutral delta', async ( { admin, page } ) => {
    await mockDashboardApi( page, MOCK_ZERO_PREVIOUS );
    await admin.visitAdminPage( DASHBOARD_PATH );

    await expect( page.getByText( '$1,500.00' ) ).toBeVisible();

    // getDelta returns { value: 0, direction: 'neutral' } when previous is 0.
    const neutralDelta = page.locator( '.mission-stat-card__delta.is-neutral' );
    await expect( neutralDelta ).toHaveCount( 3 );
    await expect( neutralDelta.first() ).toContainText( '0%' );

    // No arrow SVGs in neutral deltas.
    await expect( neutralDelta.first().locator( 'svg' ) ).not.toBeAttached();
  } );
} );

test.describe( 'Dashboard — Stripe Banner', () => {
  test( 'shows banner when Stripe is not connected', async ( {
    admin,
    page,
  } ) => {
    await mockDashboardApi( page, {
      ...MOCK_DASHBOARD,
      stripe_connected: false,
    } );
    await admin.visitAdminPage( DASHBOARD_PATH );

    // Wait for data to load.
    await expect( page.getByText( '$1,500.00' ) ).toBeVisible();

    await expect( page.getByText( 'Start accepting donations' ) ).toBeVisible();
    await expect( page.getByText( 'Connect Stripe' ) ).toBeVisible();
  } );

  test( 'hides banner when Stripe is connected', async ( { admin, page } ) => {
    await mockDashboardApi( page, {
      ...MOCK_DASHBOARD,
      stripe_connected: true,
    } );
    await admin.visitAdminPage( DASHBOARD_PATH );

    // Wait for data to load.
    await expect( page.getByText( '$1,500.00' ) ).toBeVisible();

    await expect( page.locator( '.mission-stripe-banner' ) ).not.toBeVisible();
  } );
} );
