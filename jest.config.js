const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
  ...defaultConfig,
  moduleNameMapper: {
    ...( defaultConfig.moduleNameMapper || {} ),
    '^@wordpress/interactivity$':
      '<rootDir>/blocks/src/donation-form/test/mocks/interactivity.js',
    '^@shared/(.*)$': '<rootDir>/assets/shared/$1',
  },
};
