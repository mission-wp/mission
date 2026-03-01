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
// DataViews and theme CSS need sideEffects: true so webpack doesn't tree-shake them.
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
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...( defaultConfig.resolve?.alias || {} ),
			'@shared': path.resolve( __dirname, 'assets/shared' ),
			'dataviews-style': path.resolve(
				__dirname,
				'node_modules/@wordpress/dataviews/build-style/style.css'
			),
			'theme-design-tokens': path.resolve(
				__dirname,
				'node_modules/@wordpress/theme/src/prebuilt/css/design-tokens.css'
			),
		},
	},
	module: {
		...defaultConfig.module,
		rules: [
			{
				test: /[\\/]@wordpress[\\/](dataviews[\\/]build-style|theme[\\/]src[\\/]prebuilt[\\/]css)[\\/].*\.css$/,
				sideEffects: true,
			},
			...( defaultConfig.module?.rules || [] ),
		],
	},
};

module.exports = [ blocksConfig, adminConfig ];
