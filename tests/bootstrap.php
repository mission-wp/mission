<?php
/**
 * PHPUnit bootstrap file for Mission plugin tests.
 *
 * @package MissionDP
 */

// Load Yoast PHPUnit Polyfills.
require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Determine the WordPress tests directory.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

// The suite runs without Action Scheduler: the plugin file skips loading it
// when MISSIONDP_TESTING is set (phpunit.xml.dist), so async work falls back
// to synchronous do_action() calls and tests fire scheduling hooks manually
// (see ImportPipelineTest).

/**
 * Manually load the plugin being tested.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/mission-donation-platform.php';
	}
);

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
