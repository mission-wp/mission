/**
 * E2E tests for the dashboard review banner.
 *
 * Sets up real data (activation date in the past, 26 live donations) so the
 * server-side eligibility check returns the banner, then exercises every
 * interaction: star hover, 1-4 click, 5-star click, dismiss, close, and
 * persistence after page reload.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { execSync } = require( 'child_process' );

const DASHBOARD_PATH = 'admin.php?page=mission-donation-platform';
const DONATION_AMOUNT = 50000; // $500 in cents.
const DONATION_COUNT = 26;
const TOTAL_RAISED = DONATION_AMOUNT * DONATION_COUNT; // 1,300,000 cents = $13,000.

/**
 * Match a REST route in both pretty-permalink and query-string formats.
 *
 * @param {string} route REST route path.
 * @return {RegExp} Pattern matching both URL formats.
 */
function restRoute( route ) {
  const pattern = route.slice( 1 ).replace( /\//g, '(\\/|%2F)' );
  return new RegExp( pattern );
}

/**
 * Run a SQL query inside the wp-env test container.
 *
 * @param {string} sql SQL statement to execute.
 * @return {string} Query output.
 */
function dbQuery( sql ) {
  return execSync(
    `npx @wordpress/env run tests-cli -- wp db query "${ sql }" --quiet`,
    { stdio: 'pipe' }
  )
    .toString()
    .trim();
}

/**
 * Run a WP-CLI command inside the wp-env test container.
 *
 * @param {string} command WP-CLI command (without the leading `wp`).
 * @return {string} Command output.
 */
function wpCli( command ) {
  return execSync( `npx @wordpress/env run tests-cli -- wp ${ command }`, {
    stdio: 'pipe',
  } )
    .toString()
    .trim();
}

/**
 * Clear the review banner dismissal for the default admin user (ID 1).
 */
function resetDismissal() {
  dbQuery(
    "DELETE FROM wp_usermeta WHERE user_id = 1 AND meta_key = 'missiondp_review_banner_dismissed'"
  );
}

test.describe( 'Review Banner', () => {
  let campaignId;

  test.beforeAll( async ( { requestUtils } ) => {
    // Clean up any stale "Review Banner Test" campaigns left over from a
    // previous interrupted run. Without this, the SUM(total_raised) the
    // banner displays accumulates across runs.
    dbQuery(
      'DELETE t FROM wp_missiondp_transactions t' +
        ' INNER JOIN wp_missiondp_campaigns c ON c.id = t.campaign_id' +
        " WHERE c.title = 'Review Banner Test'"
    );
    dbQuery(
      "DELETE FROM wp_missiondp_campaigns WHERE title = 'Review Banner Test'"
    );

    // Set the install date to 30 days ago so the 14-day gate passes.
    const thirtyDaysAgo = new Date( Date.now() - 30 * 24 * 60 * 60 * 1000 );
    const dateStr = thirtyDaysAgo
      .toISOString()
      .slice( 0, 19 )
      .replace( 'T', ' ' );
    wpCli( `option update missiondp_installed_at "${ dateStr }"` );

    // Disable test mode so transactions count as "live",
    // and mark onboarding complete so the modal doesn't block tests.
    await requestUtils.rest( {
      path: '/mission-donation-platform/v1/settings',
      method: 'POST',
      data: { test_mode: false, onboarding_completed: true },
    } );

    // Create a campaign to associate donations with.
    const campaign = await requestUtils.rest( {
      path: '/mission-donation-platform/v1/campaigns',
      method: 'POST',
      data: { title: 'Review Banner Test', goal_amount: 5000000 },
    } );
    campaignId = campaign.id;

    // Bulk-insert 26 completed live transactions ($500 each = $13,000 total).
    const rows = [];
    for ( let i = 0; i < DONATION_COUNT; i++ ) {
      rows.push(
        `(${ campaignId }, ${ DONATION_AMOUNT }, 'USD', 'completed', 0, NOW(), NOW(), NOW())`
      );
    }
    dbQuery(
      `INSERT INTO wp_missiondp_transactions (campaign_id, amount, currency, status, is_test, date_created, date_completed, date_modified) VALUES ${ rows.join(
        ','
      ) }`
    );

    // Set the campaign's total_raised to match.
    dbQuery(
      `UPDATE wp_missiondp_campaigns SET total_raised = ${ TOTAL_RAISED } WHERE id = ${ campaignId }`
    );

    // Ensure no stale dismissal from a previous run.
    resetDismissal();
  } );

  test.beforeEach( () => {
    resetDismissal();
  } );

  test.afterAll( async ( { requestUtils } ) => {
    dbQuery(
      `DELETE FROM wp_missiondp_transactions WHERE campaign_id = ${ campaignId }`
    );

    if ( campaignId ) {
      await requestUtils.rest( {
        path: `/mission-donation-platform/v1/campaigns/${ campaignId }`,
        method: 'DELETE',
      } );
    }

    await requestUtils.rest( {
      path: '/mission-donation-platform/v1/settings',
      method: 'POST',
      data: { test_mode: true },
    } );

    resetDismissal();
    wpCli( 'option delete missiondp_installed_at' );
  } );

  // ---------------------------------------------------------------------------
  // Visibility & content
  // ---------------------------------------------------------------------------

  test( 'appears when site has 25+ live donations and 14+ days since install', async ( {
    admin,
    page,
  } ) => {
    await admin.visitAdminPage( DASHBOARD_PATH );

    const banner = page.locator( '.mission-review-banner' );
    await expect( banner ).toBeVisible( { timeout: 10_000 } );
    await expect( banner.getByText( 'Enjoying Mission?' ) ).toBeVisible();
    await expect( banner.getByText( /\$13,000/ ) ).toBeVisible();
    await expect(
      banner.getByText( 'How would you rate your experience?' )
    ).toBeVisible();
  } );

  // ---------------------------------------------------------------------------
  // Star interactions
  // ---------------------------------------------------------------------------

  test( 'displays all five stars filled by default', async ( {
    admin,
    page,
  } ) => {
    await admin.visitAdminPage( DASHBOARD_PATH );

    const stars = page.locator( '.mission-review-banner__star' );
    await expect( stars.first() ).toBeVisible( { timeout: 10_000 } );
    await expect( stars ).toHaveCount( 5 );

    for ( let i = 0; i < 5; i++ ) {
      await expect( stars.nth( i ) ).toHaveClass( /is-filled/ );
    }
  } );

  test( 'hovering a star fills up to that star and empties the rest', async ( {
    admin,
    page,
  } ) => {
    await admin.visitAdminPage( DASHBOARD_PATH );

    const banner = page.locator( '.mission-review-banner' );
    await expect( banner ).toBeVisible( { timeout: 10_000 } );

    const stars = banner.locator( '.mission-review-banner__star' );

    // Hover over the 3rd star (index 2).
    await stars.nth( 2 ).hover();

    // Stars 1-3 should be filled, 4-5 should not.
    for ( let i = 0; i < 3; i++ ) {
      await expect( stars.nth( i ) ).toHaveClass( /is-filled/ );
    }
    for ( let i = 3; i < 5; i++ ) {
      await expect( stars.nth( i ) ).not.toHaveClass( /is-filled/ );
    }
  } );

  test( 'leaving the star row resets all stars to filled', async ( {
    admin,
    page,
  } ) => {
    await admin.visitAdminPage( DASHBOARD_PATH );

    const banner = page.locator( '.mission-review-banner' );
    await expect( banner ).toBeVisible( { timeout: 10_000 } );

    const stars = banner.locator( '.mission-review-banner__star' );

    // Hover, then move away.
    await stars.nth( 1 ).hover();
    await banner.locator( '.mission-review-banner__title' ).hover();

    for ( let i = 0; i < 5; i++ ) {
      await expect( stars.nth( i ) ).toHaveClass( /is-filled/ );
    }
  } );

  // ---------------------------------------------------------------------------
  // Rating submission
  // ---------------------------------------------------------------------------

  test( 'clicking 1-4 stars shows thank you message and sends rating', async ( {
    admin,
    page,
  } ) => {
    let ratePayload = null;

    await page.route(
      restRoute( '/mission-donation-platform/v1/review-banner/rate' ),
      async ( route, request ) => {
        ratePayload = request.postDataJSON();
        await route.fulfill( {
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify( { success: true } ),
        } );
      }
    );

    await admin.visitAdminPage( DASHBOARD_PATH );

    const banner = page.locator( '.mission-review-banner' );
    await expect( banner ).toBeVisible( { timeout: 10_000 } );

    // Click the 3rd star.
    await banner.locator( '.mission-review-banner__star' ).nth( 2 ).click();

    // Thank you text replaces stars + description.
    await expect(
      banner.locator( '.mission-review-banner__thankyou' )
    ).toBeVisible( { timeout: 2000 } );
    await expect(
      banner.getByText( 'Thank you for your feedback!' )
    ).toBeVisible();

    // Stars row and description should be gone.
    await expect(
      banner.locator( '.mission-review-banner__actions' )
    ).not.toBeVisible();
    await expect(
      banner.locator( '.mission-review-banner__desc' )
    ).not.toBeVisible();

    // Rating payload was sent.
    expect( ratePayload ).toMatchObject( { rating: 3 } );
  } );

  test( 'clicking 5 stars opens WordPress.org review page', async ( {
    admin,
    page,
  } ) => {
    // Mock the rate endpoint so the server doesn't fire the external API call.
    await page.route(
      restRoute( '/mission-donation-platform/v1/review-banner/rate' ),
      async ( route ) => {
        await route.fulfill( {
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify( { success: true } ),
        } );
      }
    );

    await admin.visitAdminPage( DASHBOARD_PATH );

    const banner = page.locator( '.mission-review-banner' );
    await expect( banner ).toBeVisible( { timeout: 10_000 } );

    // 5th star triggers window.open.
    const popupPromise = page.waitForEvent( 'popup' );
    await banner.locator( '.mission-review-banner__star' ).nth( 4 ).click();
    const popup = await popupPromise;

    expect( popup.url() ).toContain(
      'wordpress.org/support/plugin/mission/reviews'
    );
    await popup.close();

    // Banner hides after the 5-star action.
    await expect( banner ).not.toBeVisible( { timeout: 2000 } );
  } );

  // ---------------------------------------------------------------------------
  // Dismissal
  // ---------------------------------------------------------------------------

  test( 'dismiss text button hides the banner without sending a rating', async ( {
    admin,
    page,
  } ) => {
    let dismissCalled = false;
    let rateCalled = false;

    await page.route(
      restRoute( '/mission-donation-platform/v1/review-banner/dismiss' ),
      async ( route ) => {
        dismissCalled = true;
        await route.continue();
      }
    );
    await page.route(
      restRoute( '/mission-donation-platform/v1/review-banner/rate' ),
      async ( route ) => {
        rateCalled = true;
        await route.fulfill( {
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify( { success: true } ),
        } );
      }
    );

    await admin.visitAdminPage( DASHBOARD_PATH );

    const banner = page.locator( '.mission-review-banner' );
    await expect( banner ).toBeVisible( { timeout: 10_000 } );

    await banner.locator( '.mission-review-banner__dismiss-text' ).click();
    await expect( banner ).not.toBeVisible();

    // Give any pending requests a moment to fire.
    await page.waitForTimeout( 500 );

    expect( dismissCalled ).toBe( true );
    expect( rateCalled ).toBe( false );
  } );

  test( 'close button (X) hides the banner', async ( { admin, page } ) => {
    await admin.visitAdminPage( DASHBOARD_PATH );

    const banner = page.locator( '.mission-review-banner' );
    await expect( banner ).toBeVisible( { timeout: 10_000 } );

    await banner.locator( '.mission-review-banner__close' ).click();
    await expect( banner ).not.toBeVisible();
  } );

  // ---------------------------------------------------------------------------
  // Persistence
  // ---------------------------------------------------------------------------

  test( 'banner stays hidden after dismissal and page reload', async ( {
    admin,
    page,
  } ) => {
    await admin.visitAdminPage( DASHBOARD_PATH );

    const banner = page.locator( '.mission-review-banner' );
    await expect( banner ).toBeVisible( { timeout: 10_000 } );

    // Dismiss via close button (lets the real endpoint persist the user meta).
    await banner.locator( '.mission-review-banner__close' ).click();
    await expect( banner ).not.toBeVisible();

    // Reload the dashboard.
    await admin.visitAdminPage( DASHBOARD_PATH );

    // Wait for the page to fully load (stat cards render from the same API).
    await expect( page.locator( '.mission-stat-card' ).first() ).toBeVisible( {
      timeout: 10_000,
    } );

    // Banner should not reappear because user meta is set.
    await expect( banner ).not.toBeVisible();
  } );
} );
