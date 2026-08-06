/**
 * Snapshot/restore helpers for plugin settings pinned by specs.
 *
 * Specs pin settings (test mode, Stripe charges) in beforeAll so they don't
 * depend on suite order; these helpers let afterAll put back whatever the
 * environment had, so a spec leaves no settings drift behind.
 */

const { execSync } = require( 'child_process' );

/**
 * Read the named settings and return a restore() that writes them back.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {string[]}                                                    keys         Setting keys to snapshot.
 * @return {Promise<{restore: () => Promise<void>}>} Handle whose restore() re-posts the snapshot.
 */
async function snapshotSettings( requestUtils, keys ) {
  const settings = await requestUtils.rest( {
    path: '/mission-donation-platform/v1/settings',
  } );

  const saved = {};
  for ( const key of keys ) {
    saved[ key ] = settings[ key ];
  }

  return {
    restore: async () => {
      await requestUtils.rest( {
        path: '/mission-donation-platform/v1/settings',
        method: 'POST',
        data: saved,
      } );
    },
  };
}

/**
 * Snapshot the Stripe site token, which the REST settings endpoint neither
 * exposes nor accepts; configureStripe() writes it via WP-CLI, so the
 * snapshot and restore go through WP-CLI too.
 *
 * @return {{restore: () => void}} Handle whose restore() patches the token back (or removes it).
 */
function snapshotStripeSiteToken() {
  let token = null;
  try {
    token = execSync(
      'npx wp-env run tests-cli -- wp option pluck missiondp_settings stripe_site_token',
      { stdio: 'pipe', timeout: 15000 }
    )
      .toString()
      .trim();
  } catch {
    token = null; // Key absent.
  }

  return {
    restore: () => {
      const command = token
        ? `npx wp-env run tests-cli -- wp option patch update missiondp_settings stripe_site_token '${ token }'`
        : 'npx wp-env run tests-cli -- wp option patch delete missiondp_settings stripe_site_token';
      try {
        execSync( command, { stdio: 'pipe', timeout: 15000 } );
      } catch {
        // Deleting an already-absent key fails; nothing to restore then.
      }
    },
  };
}

module.exports = {
  snapshotSettings,
  snapshotStripeSiteToken,
};
