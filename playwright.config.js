const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/playwright.config' );

const config = {
	...defaultConfig,
	testDir: './specs',
	globalSetup: path.resolve( __dirname, 'specs/global-setup.js' ),
};

module.exports = config;
