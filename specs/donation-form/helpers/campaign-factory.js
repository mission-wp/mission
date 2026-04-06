/**
 * Campaign factory — creates campaigns with a donation form block via REST.
 */

const DEFAULT_AMOUNTS = {
  one_time: [ 1000, 2500, 5000, 10000 ],
};

const DEFAULT_BLOCK_ATTRS = {
  amountsByFrequency: DEFAULT_AMOUNTS,
  defaultAmounts: { one_time: 2500 },
  customAmount: true,
  minimumAmount: 100,
  recurringEnabled: false,
  recurringFrequencies: [],
  recurringDefault: 'one_time',
  feeRecovery: false,
  feeMode: 'optional',
  tipEnabled: true,
  tipPercentages: [ 5, 10, 15, 20 ],
  anonymousEnabled: false,
  tributeEnabled: false,
  collectAddress: false,
  commentsEnabled: false,
  phoneRequired: false,
  confirmationType: 'message',
  customFields: [],
};

/**
 * Create a campaign with a donation form block embedded in its post content.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {Object}                                                      blockAttributes Block attribute overrides.
 * @param {Object}                                                      campaignData    Campaign field overrides.
 * @return {Promise<{campaign: Object, url: string}>} Created campaign and its frontend URL.
 */
async function createCampaignWithForm(
  requestUtils,
  blockAttributes = {},
  campaignData = {}
) {
  const attrs = { ...DEFAULT_BLOCK_ATTRS, ...blockAttributes };

  // Create the campaign via Mission REST API.
  const campaign = await requestUtils.rest( {
    path: '/mission/v1/campaigns',
    method: 'POST',
    data: {
      title: campaignData.title || `Test Campaign ${ Date.now() }`,
      excerpt: campaignData.excerpt || 'E2E test campaign.',
      goal_amount: campaignData.goal_amount || 100000,
      ...campaignData,
    },
  } );

  // Build the block markup with attributes.
  const blockAttrs = JSON.stringify( { campaignId: campaign.id, ...attrs } );
  const blockContent = `<!-- wp:mission/donation-form ${ blockAttrs } /-->`;

  // Update the campaign post content with the donation form block.
  await requestUtils.rest( {
    path: `/wp/v2/mission_campaign/${ campaign.post_id }`,
    method: 'PUT',
    data: {
      content: blockContent,
    },
  } );

  return {
    campaign,
    url: campaign.url || `/?p=${ campaign.post_id }`,
  };
}

/**
 * Delete a campaign via REST.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {number}                                                      campaignId   Campaign ID.
 */
async function deleteCampaign( requestUtils, campaignId ) {
  await requestUtils.rest( {
    path: `/mission/v1/campaigns/${ campaignId }`,
    method: 'DELETE',
  } );
}

/**
 * Ensure test mode is enabled in plugin settings.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 */
async function enableTestMode( requestUtils ) {
  await requestUtils.rest( {
    path: '/mission/v1/settings',
    method: 'POST',
    data: { test_mode: true, stripe_charges_enabled: true },
  } );
}

/**
 * Configure Stripe for payment tests using the MISSION_STRIPE_TEST_TOKEN env var.
 *
 * Sets stripe_site_token and stripe_connection_status in the plugin's settings
 * option directly (the REST endpoint blocks token writes for security).
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @return {Promise<boolean>} Whether Stripe was successfully configured.
 */
async function configureStripe( requestUtils ) {
  const token = process.env.MISSION_STRIPE_TEST_TOKEN;
  const accountId = process.env.MISSION_STRIPE_ACCOUNT_ID;
  if ( ! token || ! accountId ) {
    return false;
  }

  // Set connection status and account ID via the normal settings endpoint.
  await requestUtils.rest( {
    path: '/mission/v1/settings',
    method: 'POST',
    data: {
      stripe_connection_status: 'connected',
      stripe_account_id: accountId,
      stripe_charges_enabled: true,
    },
  } );

  // Set the token directly via WP-CLI since the REST endpoint blocks
  // stripe_site_token writes for security.
  const { execSync } = require( 'child_process' );
  try {
    execSync(
      `npx wp-env run tests-cli -- wp option patch update mission_settings stripe_site_token '${ token }'`,
      { stdio: 'pipe', timeout: 15000 }
    );
    return true;
  } catch {
    return false;
  }
}

module.exports = {
  DEFAULT_BLOCK_ATTRS,
  createCampaignWithForm,
  deleteCampaign,
  enableTestMode,
  configureStripe,
};
