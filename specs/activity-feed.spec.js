/**
 * E2E tests for the dashboard activity feed.
 *
 * Creates a campaign with a $1,000 goal, adds multiple donations via the
 * transactions REST API, then verifies the dashboard activity feed shows
 * descriptive text with donor names, amounts, campaign names, and milestone
 * events.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { execSync } = require( 'child_process' );

const DASHBOARD_PATH = 'admin.php?page=mission';

const CAMPAIGN_TITLE = 'Activity Feed Test Campaign';
const CAMPAIGN_GOAL = 100000; // $1,000 in cents.

/**
 * Donations to add. Each one crosses a new milestone threshold.
 *
 * $250 → 25%, $250 → 50%, $250 → 75%, $250 → 100%.
 */
const DONATIONS = [
  {
    donor_email: 'sarah-feed@example.com',
    donor_first_name: 'Sarah',
    donor_last_name: 'Miller',
    donation_amount: 25000,
  },
  {
    donor_email: 'james-feed@example.com',
    donor_first_name: 'James',
    donor_last_name: 'Taylor',
    donation_amount: 25000,
  },
  {
    donor_email: 'emily-feed@example.com',
    donor_first_name: 'Emily',
    donor_last_name: 'Chen',
    donation_amount: 25000,
  },
  {
    donor_email: 'robert-feed@example.com',
    donor_first_name: 'Robert',
    donor_last_name: 'Johnson',
    donation_amount: 25000,
  },
];

let campaignId;

test.describe( 'Activity Feed — donations and milestones', () => {
  test.beforeAll( async ( { requestUtils } ) => {
    // Clear stale activity log entries from previous runs.
    execSync(
      'npx @wordpress/env run tests-cli -- wp db query "DELETE FROM wp_mission_activity_log" --quiet',
      { stdio: 'pipe' }
    );

    // Disable test mode and mark onboarding complete so the dashboard is visible.
    await requestUtils.rest( {
      path: '/mission/v1/settings',
      method: 'POST',
      data: { test_mode: false, onboarding_completed: true },
    } );

    // Create a campaign with a $1,000 goal via REST.
    const campaign = await requestUtils.rest( {
      path: '/mission/v1/campaigns',
      method: 'POST',
      data: {
        title: CAMPAIGN_TITLE,
        goal_amount: CAMPAIGN_GOAL,
      },
    } );
    campaignId = campaign.id;

    // Add donations sequentially (each triggers milestone checks).
    for ( const donation of DONATIONS ) {
      await requestUtils.rest( {
        path: '/mission/v1/transactions',
        method: 'POST',
        data: {
          ...donation,
          campaign_id: campaignId,
          frequency: 'one_time',
        },
      } );
    }
  } );

  test.afterAll( async ( { requestUtils } ) => {
    if ( campaignId ) {
      await requestUtils.rest( {
        path: `/mission/v1/campaigns/${ campaignId }`,
        method: 'DELETE',
      } );
    }

    // Restore test mode for other test suites.
    await requestUtils.rest( {
      path: '/mission/v1/settings',
      method: 'POST',
      data: { test_mode: true },
    } );
  } );

  test( 'donation entries show donor name, amount, and linked campaign', async ( {
    admin,
    page,
  } ) => {
    await admin.visitAdminPage( DASHBOARD_PATH );

    // Wait for the activity feed to load.
    await expect(
      page.locator( '.mission-activity-feed .mission-feed-item' ).first()
    ).toBeVisible( { timeout: 10000 } );

    const feed = page.locator( '.mission-activity-feed' );

    // Each donation should appear with descriptive text.
    await expect(
      feed.getByText( 'Sarah Miller donated $250.00' )
    ).toBeVisible();
    await expect(
      feed.getByText( 'James Taylor donated $250.00' )
    ).toBeVisible();
    await expect(
      feed.getByText( 'Emily Chen donated $250.00' )
    ).toBeVisible();
    await expect(
      feed.getByText( 'Robert Johnson donated $250.00' )
    ).toBeVisible();

    // Campaign name should appear as a link in donation entries.
    const campaignLinks = feed.locator(
      `a.mission-feed-link[href*="campaign=${ campaignId }"]`
    );
    await expect( campaignLinks.first() ).toBeVisible();
    await expect( campaignLinks.first() ).toHaveText( CAMPAIGN_TITLE );
  } );

  test( 'milestone events appear at 25%, 50%, 75%, and 100% of goal', async ( {
    admin,
    page,
  } ) => {
    await admin.visitAdminPage( DASHBOARD_PATH );

    const feed = page.locator( '.mission-activity-feed' );

    await expect( feed.locator( '.mission-feed-item' ).first() ).toBeVisible( {
      timeout: 10000,
    } );

    // Each percentage milestone should appear.
    await expect( feed.getByText( /is 25% toward its goal/ ) ).toBeVisible();
    await expect( feed.getByText( /is 50% toward its goal/ ) ).toBeVisible();
    await expect( feed.getByText( /is 75% toward its goal/ ) ).toBeVisible();

    // The 100% milestone uses the goal-reached event with the dollar amount.
    await expect(
      feed.getByText( /reached its \$1,000\.00 goal/ )
    ).toBeVisible();
  } );

  test( 'donor names link to donor detail pages', async ( { admin, page } ) => {
    await admin.visitAdminPage( DASHBOARD_PATH );

    const feed = page.locator( '.mission-activity-feed' );

    await expect( feed.locator( '.mission-feed-item' ).first() ).toBeVisible( {
      timeout: 10000,
    } );

    // Donor names should be links to donor detail pages.
    const donorLink = feed
      .locator( 'a.mission-feed-link[href*="mission-donors"]' )
      .first();
    await expect( donorLink ).toBeVisible();
    await expect( donorLink ).toHaveAttribute( 'href', /donor=\d+/ );
  } );
} );
