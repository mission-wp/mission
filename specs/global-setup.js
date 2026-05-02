/**
 * External dependencies
 */
// eslint-disable-next-line import/no-extraneous-dependencies -- Provided by @wordpress/scripts.
const { request } = require( '@playwright/test' );

/**
 * WordPress dependencies
 */
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Global setup — authenticates and activates the Mission plugin.
 *
 * @param {import('@playwright/test').FullConfig} config
 * @return {Promise<void>}
 */
async function globalSetup( config ) {
  const projectUse = config.projects[ 0 ].use;
  const baseURL = projectUse.baseURL || config.use?.baseURL;
  const storageState = projectUse.storageState || config.use?.storageState;
  const storageStatePath =
    typeof storageState === 'string' ? storageState : undefined;

  const requestContext = await request.newContext( { baseURL } );

  const requestUtils = new RequestUtils( requestContext, {
    storageStatePath,
  } );

  await requestUtils.setupRest();

  // Activate the plugin if it isn't already.
  const plugins = await requestUtils.rest( {
    path: '/wp/v2/plugins',
    method: 'GET',
  } );

  const mission = plugins.find(
    ( p ) => p.plugin === 'mission/mission-donation-platform'
  );

  if ( mission && mission.status !== 'active' ) {
    await requestUtils.rest( {
      path: `/wp/v2/plugins/mission/mission-donation-platform`,
      method: 'PUT',
      data: { status: 'active' },
    } );
  }

  // Ensure a theme is active (wp-env test instances can start without one).
  try {
    await requestUtils.activateTheme( 'twentytwentyfive' );
  } catch {
    // Non-fatal — wp-env usually has a theme active already.
  }

  // Ensure pretty permalinks are enabled (needed for campaign page URLs).
  const { execSync } = require( 'child_process' );
  try {
    execSync(
      "npx wp-env run tests-cli -- wp rewrite structure '/%postname%/' --hard",
      { stdio: 'pipe', timeout: 15000 }
    );
  } catch {
    // Non-fatal — tests using ?p=ID fallback will still work.
  }

  await requestContext.dispose();
}

module.exports = globalSetup;
