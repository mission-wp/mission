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

  // Activate the plugin if it isn't already. The REST plugin slug is
  // `<directory>/<main-file-without-ext>`, which for this plugin is
  // `mission-donation-platform/mission-donation-platform`.
  const pluginSlug = 'mission-donation-platform/mission-donation-platform';
  const plugins = await requestUtils.rest( {
    path: '/wp/v2/plugins',
    method: 'GET',
  } );

  const mission = plugins.find( ( p ) => p.plugin === pluginSlug );

  if ( mission && mission.status !== 'active' ) {
    await requestUtils.rest( {
      path: `/wp/v2/plugins/${ pluginSlug }`,
      method: 'PUT',
      data: { status: 'active' },
    } );
  }

  const { execSync } = require( 'child_process' );

  // Ensure a real theme is active. The REST activateTheme path can silently
  // leave the instance on a non-existent "default" theme (blank frontend, so
  // no blocks render), so activate a bundled block theme via WP-CLI.
  try {
    await requestUtils.activateTheme( 'twentytwentyfive' );
  } catch {
    // Fall through to the WP-CLI activation below.
  }
  try {
    execSync(
      'npx wp-env run tests-cli -- wp theme activate twentytwentyfour',
      { stdio: 'pipe', timeout: 20000 }
    );
  } catch {
    // Non-fatal — the REST activation above may have already succeeded.
  }

  // Ensure pretty permalinks are enabled (needed for campaign page URLs).
  try {
    execSync(
      "npx wp-env run tests-cli -- wp rewrite structure '/%postname%/' --hard",
      { stdio: 'pipe', timeout: 15000 }
    );
  } catch {
    // Non-fatal — tests using ?p=ID fallback will still work.
  }

  // Capture outgoing mail to a bounded log option (last 10 messages) so specs
  // can read OTP codes even when several emails send back-to-back, and clear
  // any stale rate-limit / code transients left by a previous run.
  const mailCapture =
    '<?php\n' +
    "add_filter( 'wp_mail', function ( $atts ) {\n" +
    "\t$log   = get_option( 'missiondp_e2e_mail_log', [] );\n" +
    '\t$log   = is_array( $log ) ? $log : [];\n' +
    '\t$log[] = $atts;\n' +
    "\tupdate_option( 'missiondp_e2e_mail_log', array_slice( $log, -10 ), false );\n" +
    '\treturn $atts;\n' +
    '} );\n';
  const encoded = Buffer.from( mailCapture ).toString( 'base64' );
  try {
    execSync(
      `npx wp-env run tests-cli -- bash -c "mkdir -p wp-content/mu-plugins && echo ${ encoded } | base64 -d > wp-content/mu-plugins/missiondp-e2e-mail.php"`,
      { stdio: 'pipe', timeout: 20000 }
    );
    // Drop mail captured by previous runs (including the old single-slot option).
    execSync(
      'npx wp-env run tests-cli -- bash -c "wp option delete missiondp_e2e_mail_log missiondp_e2e_last_mail || true"',
      { stdio: 'pipe', timeout: 20000 }
    );
    execSync( 'npx wp-env run tests-cli -- wp transient delete --all', {
      stdio: 'pipe',
      timeout: 20000,
    } );
  } catch {
    // Non-fatal — signup specs surface a clear failure if mail capture is absent.
  }

  await requestContext.dispose();
}

module.exports = globalSetup;
