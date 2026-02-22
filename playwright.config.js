const defaultConfig = require( '@wordpress/scripts/config/playwright.config' );

const config = {
	...defaultConfig,
	testDir: './specs',
};

module.exports = config;
