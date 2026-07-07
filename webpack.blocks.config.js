const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const RtlCssPlugin = require( '@wordpress/scripts/plugins/rtlcss-webpack-plugin' );
const path = require( 'path' );

const sharedAlias = {
  '@shared': path.resolve( __dirname, 'assets/shared' ),
};

function addAlias( config ) {
  return {
    ...config,
    resolve: {
      ...config.resolve,
      alias: {
        ...( config.resolve?.alias || {} ),
        ...sharedAlias,
      },
    },
  };
}

// wp-scripts only adds RTL CSS output to the script build; mirror it for the
// module build so view-module styles (the signup modal shell) get RTL files.
function addRtl( config ) {
  if ( ! config.output?.module ) {
    return config;
  }
  return {
    ...config,
    plugins: [ ...config.plugins, new RtlCssPlugin() ],
  };
}

// wp-scripts exports an array when --experimental-modules is used.
module.exports = Array.isArray( defaultConfig )
  ? defaultConfig.map( ( config ) => addRtl( addAlias( config ) ) )
  : addAlias( defaultConfig );
