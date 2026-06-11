<?php
/**
 * Regenerate blueprint.json from seed.php.
 *
 * wp.org Live Preview blueprints must be a single self-contained JSON file, so
 * the demo-data seed (seed.php) is embedded verbatim as the runPHP step. Edit
 * seed.php, then run this script to rebuild blueprint.json:
 *
 *   php .wordpress-org/blueprints/build.php
 *
 * Dev-only. Excluded from the plugin zip by .distignore.
 */

$dir  = __DIR__;
$seed = file_get_contents( "{$dir}/seed.php" );

if ( false === $seed ) {
	fwrite( STDERR, "Could not read seed.php\n" );
	exit( 1 );
}

$blueprint = [
	'$schema'             => 'https://playground.wordpress.net/blueprint-schema.json',
	'meta'                => [
		'title'       => 'Mission Donation Platform',
		'description' => 'Try Mission with a demo nonprofit, seeded campaigns, donors, and donations.',
		'author'      => 'missionwp',
	],
	'landingPage'         => '/wp-admin/admin.php?page=mission-donation-platform',
	'preferredVersions'   => [ 'php' => '8.3', 'wp' => 'latest' ],
	'phpExtensionBundles' => [ 'kitchen-sink' ],
	'features'            => [ 'networking' => true ],
	'steps'               => [
		[ 'step' => 'login', 'username' => 'admin', 'password' => 'password' ],
		[
			'step'       => 'installPlugin',
			'pluginData' => [ 'resource' => 'wordpress.org/plugins', 'slug' => 'mission-donation-platform' ],
			'options'    => [ 'activate' => true ],
		],
		[
			'step'    => 'setSiteOptions',
			'options' => [
				'blogname'        => 'Hopewell Animal Rescue',
				'blogdescription' => 'A demo nonprofit powered by the Mission donation plugin',
			],
		],
		[ 'step' => 'runPHP', 'code' => $seed ],
	],
];

$json = json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

if ( null === json_decode( $json ) ) {
	fwrite( STDERR, "Generated invalid JSON\n" );
	exit( 1 );
}

file_put_contents( "{$dir}/blueprint.json", $json );

echo 'Wrote blueprint.json (' . strlen( $json ) . " bytes)\n";
