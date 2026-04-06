const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
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

// wp-scripts exports an array when --experimental-modules is used.
module.exports = Array.isArray( defaultConfig )
  ? defaultConfig.map( addAlias )
  : addAlias( defaultConfig );
