<?php
/**
 * Tests for the DonorWallEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Transaction;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * DonorWallEndpoint test class.
 */
class DonorWallEndpointTest extends WP_UnitTestCase {

	/**
	 * Use live mode so the query reads the transactions created here.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'missiondp_settings', [ 'test_mode' => false, 'currency' => 'USD' ] );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		parent::tear_down();
	}

	/**
	 * Items carry a server-formatted relative date so the front end never
	 * has to build one in English.
	 */
	public function test_items_include_formatted_date(): void {
		$campaign = new Campaign( [ 'title' => 'Wall' ] );
		$campaign->save();
		$donor = new Donor( [ 'email' => 'wall@example.com', 'first_name' => 'Wanda', 'last_name' => 'Wall' ] );
		$donor->save();
		( new Transaction(
			[
				'status'         => 'completed',
				'donor_id'       => $donor->id,
				'campaign_id'    => $campaign->id,
				'amount'         => 5000,
				'date_completed' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			]
		) )->save();

		$request = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/donor-wall' );
		$request->set_param( 'campaign_id', $campaign->id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$items = $response->get_data()['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( '2 hours ago', $items[0]['formatted_date'] );
	}
}
