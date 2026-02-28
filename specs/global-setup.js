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
	const { storageState, baseURL } = config.projects[ 0 ].use;
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

	const mission = plugins.find( ( p ) => p.plugin === 'mission/mission' );

	if ( mission && mission.status !== 'active' ) {
		await requestUtils.rest( {
			path: `/wp/v2/plugins/mission/mission`,
			method: 'PUT',
			data: { status: 'active' },
		} );
	}

	await requestContext.dispose();
}

module.exports = globalSetup;
