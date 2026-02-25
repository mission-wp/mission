const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

// Blocks config — replicates the previous --webpack-src-dir / --output-path behavior.
const blocksConfig = {
	...defaultConfig,
	name: 'blocks',
	entry: {
		'donation-form': './blocks/src/donation-form/index.js',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'blocks/build' ),
	},
};

// Admin config — React admin UI.
const adminConfig = {
	...defaultConfig,
	name: 'admin',
	entry: {
		'mission-admin': './admin/src/index.js',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'admin/build' ),
	},
};

module.exports = [ blocksConfig, adminConfig ];
