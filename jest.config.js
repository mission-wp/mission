const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	moduleNameMapper: {
		...( defaultConfig.moduleNameMapper || {} ),
		'^@wordpress/interactivity$':
			'<rootDir>/blocks/src/donation-form/test/mocks/interactivity.js',
	},
};
