<?php
/**
 * Demo-data seed for the WordPress.org Live Preview blueprint.
 *
 * This file is the readable source of truth. It is embedded verbatim as the
 * `runPHP` step inside blueprint.json (wp.org blueprints must be a single
 * self-contained JSON file, so the code cannot be referenced externally).
 *
 * After editing, regenerate the blueprint:
 *
 *   php .wordpress-org/blueprints/build.php
 *
 * Neither this file nor build.php ships in the plugin zip (.wordpress-org is
 * excluded by .distignore); they deploy to the wp.org SVN /assets directory.
 */

require_once '/wordpress/wp-load.php';

use MissionDP\Models\ActivityLog;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Subscription;
use MissionDP\Models\Team;
use MissionDP\Models\TeamInvitation;
use MissionDP\Models\Transaction;
use MissionDP\Database\DataStore\CampaignDataStore;
use MissionDP\Database\DataStore\DonorDataStore;
use MissionDP\Settings\SettingsService;

$now = time();
$day = DAY_IN_SECONDS;
$fmt = static function ( $ts ) { return gmdate( 'Y-m-d H:i:s', $ts ); };

// Name the demo org before campaign pages are generated (page content embeds
// it), mark onboarding complete so the setup wizard doesn't open on arrival.
$settings                         = get_option( 'missiondp_settings', [] );
$settings['org_name']             = 'Hopewell Animal Rescue';
$settings['onboarding_completed'] = true;
update_option( 'missiondp_settings', $settings );

// Seed a placeholder Stripe connection so the dashboard's "Connect Stripe"
// banner stays hidden. The token is fake and never reaches Stripe (the real
// connect flow is what notifies us of new sites); it only suppresses the
// prompt. Test mode stays on, so all seeded test data still shows.
( new SettingsService() )->add_stripe_account(
	[
		'site_id'           => 'demo',
		'site_token'        => 'demo-not-a-real-token',
		'account_id'        => 'acct_MissionDemo',
		'display_name'      => 'Hopewell Animal Rescue',
		'connection_status' => 'connected',
		'charges_enabled'   => true,
		'webhook_secret'    => '',
		'connected_at'      => gmdate( 'c' ),
	]
);

$campaign_seeds = [
	[ 'title' => 'Build the New Shelter', 'description' => 'Help us break ground on a modern shelter with room for 120 animals.', 'goal_amount' => 5000000, 'date_start' => $fmt( $now - 75 * $day ) ],
	[ 'title' => 'Emergency Vet Care Fund', 'description' => 'Covers urgent surgeries and treatments for rescues that arrive injured or sick.', 'goal_amount' => 1000000, 'date_start' => $fmt( $now - 60 * $day ) ],
	[ 'title' => 'Community Pet Food Pantry', 'description' => 'Keeps pet food on the shelves for families going through hard times.', 'goal_amount' => 250000, 'date_start' => $fmt( $now - 45 * $day ) ],
];
$campaigns = [];
foreach ( $campaign_seeds as $seed ) {
	$campaign = new Campaign( $seed );
	$campaign->save();
	$campaigns[] = $campaign;
}

$donor_seeds = [
	[ 'Amara', 'Okafor', 'Portland', 'OR' ],
	[ 'Ben', 'Castillo', 'Austin', 'TX' ],
	[ 'Claire', 'Whitfield', 'Burlington', 'VT' ],
	[ 'Devon', 'Marsh', 'Chicago', 'IL' ],
	[ 'Elena', 'Petrov', 'Raleigh', 'NC' ],
	[ 'Frank', 'Delgado', 'Tucson', 'AZ' ],
	[ 'Grace', 'Lindqvist', 'Madison', 'WI' ],
	[ 'Hiro', 'Tanaka', 'Seattle', 'WA' ],
	[ 'Imani', 'Brooks', 'Atlanta', 'GA' ],
	[ 'Jonas', 'Meyer', 'Denver', 'CO' ],
];
$donors = [];
foreach ( $donor_seeds as $d ) {
	$donor = new Donor( [
		'email'      => strtolower( $d[0] . '.' . $d[1] ) . '@example.com',
		'first_name' => $d[0],
		'last_name'  => $d[1],
		'city'       => $d[2],
		'state'      => $d[3],
		'country'    => 'US',
	] );
	$donor->save();
	$donors[] = $donor;
}

// One-time donations spread over the last ten weeks. Seeded so every preview looks the same.
mt_srand( 20260610 );
$amounts = [ 1000, 1500, 2500, 2500, 5000, 5000, 7500, 10000, 15000, 25000 ];
$transactions = [];
for ( $i = 0; $i < 34; $i++ ) {
	$donor    = $donors[ mt_rand( 0, count( $donors ) - 1 ) ];
	$campaign = $campaigns[ mt_rand( 0, count( $campaigns ) - 1 ) ];
	$amount   = $amounts[ mt_rand( 0, count( $amounts ) - 1 ) ];
	$tip      = mt_rand( 0, 2 ) ? (int) round( $amount * 0.1 ) : 0;
	$date     = $fmt( $now - mt_rand( 2, 70 * 24 ) * 3600 );

	$transaction = new Transaction( [
		'status'          => 'completed',
		'type'            => 'one_time',
		'donor_id'        => $donor->id,
		'campaign_id'     => $campaign->id,
		'source_post_id'  => $campaign->post_id,
		'amount'          => $amount,
		'tip_amount'      => $tip,
		'total_amount'    => $amount + $tip,
		'payment_gateway' => 'stripe',
		'is_anonymous'    => 0 === mt_rand( 0, 9 ),
		'is_test'         => true,
		'date_created'    => $date,
		'date_completed'  => $date,
	] );
	$transaction->save();
	$transactions[] = $transaction;
}

// Active monthly subscriptions with renewal history: [donor index, campaign index, amount, renewals].
$sub_seeds     = [ [ 0, 0, 2500, 3 ], [ 3, 1, 1000, 2 ], [ 7, 0, 5000, 1 ], [ 5, 2, 2000, 2 ] ];
$subscriptions = [];
foreach ( $sub_seeds as $s ) {
	$donor    = $donors[ $s[0] ];
	$campaign = $campaigns[ $s[1] ];
	$amount   = $s[2];
	$renewals = $s[3];
	$started  = $now - ( $renewals * 30 + mt_rand( 1, 20 ) ) * $day;

	$subscription = new Subscription( [
		'status'            => 'active',
		'donor_id'          => $donor->id,
		'campaign_id'       => $campaign->id,
		'source_post_id'    => $campaign->post_id,
		'amount'            => $amount,
		'total_amount'      => $amount,
		'frequency'         => 'monthly',
		'payment_gateway'   => 'stripe',
		'renewal_count'     => $renewals,
		'total_renewed'     => $amount * $renewals,
		'is_test'           => true,
		'date_created'      => $fmt( $started ),
		'date_next_renewal' => $fmt( $started + ( $renewals + 1 ) * 30 * $day ),
	] );
	$subscription->save();
	$subscriptions[] = $subscription;

	for ( $r = 0; $r <= $renewals; $r++ ) {
		$date        = $fmt( $started + $r * 30 * $day );
		$transaction = new Transaction( [
			'status'          => 'completed',
			'type'            => 'subscription',
			'donor_id'        => $donor->id,
			'subscription_id' => $subscription->id,
			'campaign_id'     => $campaign->id,
			'source_post_id'  => $campaign->post_id,
			'amount'          => $amount,
			'total_amount'    => $amount,
			'payment_gateway' => 'stripe',
			'is_test'         => true,
			'date_created'    => $date,
			'date_completed'  => $date,
		] );
		$transaction->save();
		if ( 0 === $r ) {
			$subscription->initial_transaction_id = $transaction->id;
			$subscription->save();
		}
		$transactions[] = $transaction;
	}
}

// Peer-to-peer campaign: a few fundraisers, a team, and attributed donations.
$p2p_campaign = new Campaign( [
	'title'       => 'Paws on the Pavement 5K',
	'description' => 'Lace up and fundraise for the animals. Start your own page or join a team.',
	'goal_amount' => 2000000,
	'type'        => 'p2p',
	'date_start'  => $fmt( $now - 30 * $day ),
] );
$p2p_campaign->save();

$p2p_campaign->update_meta( 'registration_open', true );
$p2p_campaign->update_meta( 'approval_required', false );
$p2p_campaign->update_meta( 'teams_enabled', true );
$p2p_campaign->update_meta( 'team_creation_enabled', true );
$p2p_campaign->update_meta( 'default_fundraiser_goal', 50000 );
$p2p_campaign->update_meta( 'default_team_goal', 250000 );

$team = new Team( [
	'campaign_id' => $p2p_campaign->id,
	'name'        => 'The Tail Waggers',
	'description' => 'Walking, running, and raising for the shelter.',
	'goal'        => 250000,
	'status'      => 'active',
	'access'      => 'public',
	'date_created' => $fmt( $now - 28 * $day ),
] );
$team->save();

// [donor index, goal, headline, on team?, captain?].
$fundraiser_seeds = [
	[ 0, 75000, 'Running for rescues', true, true ],
	[ 4, 50000, 'Every mile matters', true, false ],
	[ 7, 50000, 'For the pups', true, false ],
	[ 2, 60000, 'Going solo for a cause', false, false ],
];
$fundraisers = [];
foreach ( $fundraiser_seeds as $f ) {
	$fundraiser = new Fundraiser( [
		'campaign_id'     => $p2p_campaign->id,
		'donor_id'        => $donors[ $f[0] ]->id,
		'team_id'         => $f[3] ? $team->id : null,
		'is_team_captain' => $f[4],
		'status'          => 'active',
		'goal'            => $f[1],
		'headline'        => $f[2],
		'date_created'    => $fmt( $now - mt_rand( 20, 27 ) * $day ),
	] );
	$fundraiser->save();
	$fundraisers[] = $fundraiser;

	if ( $f[4] ) {
		$team->captain_id = $fundraiser->id;
		$team->save();
	}
}

// A pending invitation to the (public) team, for dashboard realism.
( new TeamInvitation( [
	'team_id'      => $team->id,
	'email'        => 'kepler.nash@example.com',
	'status'       => 'pending',
	'date_created' => $fmt( $now - 10 * $day ),
] ) )->save();

// Donations attributed to fundraisers (donors give to a participant's page).
foreach ( $fundraisers as $idx => $fundraiser ) {
	$gift_count = mt_rand( 2, 5 );
	for ( $g = 0; $g < $gift_count; $g++ ) {
		$donor  = $donors[ mt_rand( 0, count( $donors ) - 1 ) ];
		$amount = $amounts[ mt_rand( 0, count( $amounts ) - 1 ) ];
		$tip    = mt_rand( 0, 2 ) ? (int) round( $amount * 0.1 ) : 0;
		$date   = $fmt( $now - mt_rand( 2, 18 * 24 ) * 3600 );

		$transaction = new Transaction( [
			'status'          => 'completed',
			'type'            => 'one_time',
			'donor_id'        => $donor->id,
			'campaign_id'     => $p2p_campaign->id,
			'fundraiser_id'   => $fundraiser->id,
			'source_post_id'  => $p2p_campaign->post_id,
			'amount'          => $amount,
			'tip_amount'      => $tip,
			'total_amount'    => $amount + $tip,
			'payment_gateway' => 'stripe',
			'is_test'         => true,
			'date_created'    => $date,
			'date_completed'  => $date,
		] );
		$transaction->save();
		$transactions[] = $transaction;
	}
}

// Rebuild aggregate columns the same way the importer does.
$donor_store = new DonorDataStore();
foreach ( $donors as $donor ) {
	$donor_store->recompute_aggregates( $donor->id );
}
$campaign_store = new CampaignDataStore();
foreach ( $campaigns as $campaign ) {
	$campaign_store->recompute_aggregates( $campaign->id );
}
$campaign_store->recompute_aggregates( $p2p_campaign->id );
foreach ( $fundraisers as $fundraiser ) {
	Fundraiser::recompute_aggregates( $fundraiser->id );
}

// The seeding above auto-logged every insert at "now" via the activity feed
// listeners. Replace that noise with curated, backdated entries.
$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'missiondp_activity_log' );

foreach ( $campaigns as $i => $campaign ) {
	( new ActivityLog( [
		'event'        => 'campaign_created',
		'object_type'  => 'campaign',
		'object_id'    => $campaign->id,
		'actor_id'     => get_current_user_id(),
		'data'         => wp_json_encode( [ 'title' => $campaign->title ] ),
		'category'     => 'system',
		'date_created' => $campaign_seeds[ $i ]['date_start'],
	] ) )->save();
}

foreach ( $subscriptions as $subscription ) {
	$donor    = $subscription->donor();
	$campaign = $subscription->campaign();
	( new ActivityLog( [
		'event'        => 'subscription_created',
		'object_type'  => 'subscription',
		'object_id'    => $subscription->id,
		'data'         => wp_json_encode( [
			'amount'         => $subscription->amount,
			'frequency'      => 'monthly',
			'donor_id'       => $subscription->donor_id,
			'donor_name'     => $donor ? $donor->first_name . ' ' . $donor->last_name : '',
			'campaign_id'    => $subscription->campaign_id,
			'campaign_title' => $campaign ? $campaign->title : '',
		] ),
		'is_test'      => true,
		'category'     => 'subscription',
		'date_created' => $subscription->date_created,
	] ) )->save();
}

usort( $transactions, static function ( $a, $b ) { return strcmp( $b->date_completed, $a->date_completed ); } );
foreach ( array_slice( $transactions, 0, 12 ) as $transaction ) {
	$donor      = $transaction->donor();
	$campaign   = $transaction->campaign();
	$is_renewal = (bool) $transaction->subscription_id;
	$data       = [
		'amount'         => $transaction->amount,
		'donor_id'       => $transaction->donor_id,
		'donor_name'     => $donor ? $donor->first_name . ' ' . $donor->last_name : '',
		'campaign_id'    => $transaction->campaign_id,
		'campaign_title' => $campaign ? $campaign->title : '',
	];
	if ( $is_renewal ) {
		$data['frequency'] = 'monthly';
	}
	( new ActivityLog( [
		'event'        => $is_renewal ? 'recurring_donation_processed' : 'donation_completed',
		'object_type'  => 'transaction',
		'object_id'    => $transaction->id,
		'data'         => wp_json_encode( $data ),
		'is_test'      => true,
		'category'     => $is_renewal ? 'subscription' : 'payment',
		'date_created' => $transaction->date_completed,
	] ) )->save();
}
