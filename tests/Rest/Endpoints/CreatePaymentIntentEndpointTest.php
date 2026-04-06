<?php
/**
 * Tests for the CreatePaymentIntentEndpoint class.
 *
 * @package Mission
 */

namespace Mission\Tests\Rest\Endpoints;

use Mission\Database\DatabaseModule;
use Mission\Models\Campaign;
use Mission\Models\Donor;
use Mission\Models\Transaction;
use Mission\Models\Tribute;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * CreatePaymentIntentEndpoint test class.
 */
class CreatePaymentIntentEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Campaign ID for tests.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_activity_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transaction_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_notes" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaigns" );

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		// Configure settings needed for every test.
		update_option(
			SettingsService::OPTION_NAME,
			[
				'stripe_site_token'  => 'tok_test_abc123',
				'stripe_fee_percent' => 2.9,
				'stripe_fee_fixed'   => 30,
				'test_mode'          => false,
			]
		);

		// Create a campaign for tests.
		$campaign = new Campaign(
			[
				'title'       => 'General Fund',
				'goal_amount' => 100000,
			]
		);
		$campaign->save();
		$this->campaign_id = $campaign->id;

		// Install the default HTTP mock.
		add_filter( 'pre_http_request', [ $this, 'mock_api_success' ], 10, 3 );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server, $wpdb;

		$wp_rest_server = null;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );

		delete_option( SettingsService::OPTION_NAME );

		remove_filter( 'pre_http_request', [ $this, 'mock_api_success' ], 10 );
		remove_filter( 'pre_http_request', [ $this, 'mock_api_network_failure' ], 10 );
		remove_filter( 'pre_http_request', [ $this, 'mock_api_non_200' ], 10 );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// HTTP Mocks
	// -------------------------------------------------------------------------

	/**
	 * Default mock: Mission API returns a successful PaymentIntent response.
	 *
	 * @param false|array $preempt Whether to preempt the request.
	 * @param array       $args    Request arguments.
	 * @param string      $url     Request URL.
	 * @return array|false
	 */
	public function mock_api_success( $preempt, $args, $url ) {
		if ( ! str_contains( $url, 'api.missionwp.com/create-payment-intent' ) ) {
			return $preempt;
		}

		// Store the request body for assertions.
		$this->last_api_body = json_decode( $args['body'], true );

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'client_secret'        => 'pi_test123_secret_abc456',
					'connected_account_id' => 'acct_test_connected',
				]
			),
		];
	}

	/**
	 * Mock: Mission API returns a WP_Error (network failure).
	 *
	 * @param false|array $preempt Whether to preempt the request.
	 * @param array       $args    Request arguments.
	 * @param string      $url     Request URL.
	 * @return \WP_Error|false
	 */
	public function mock_api_network_failure( $preempt, $args, $url ) {
		if ( ! str_contains( $url, 'api.missionwp.com/create-payment-intent' ) ) {
			return $preempt;
		}

		return new \WP_Error( 'http_request_failed', 'Connection timed out' );
	}

	/**
	 * Mock: Mission API returns a non-200 response.
	 *
	 * @param false|array $preempt Whether to preempt the request.
	 * @param array       $args    Request arguments.
	 * @param string      $url     Request URL.
	 * @return array|false
	 */
	public function mock_api_non_200( $preempt, $args, $url ) {
		if ( ! str_contains( $url, 'api.missionwp.com/create-payment-intent' ) ) {
			return $preempt;
		}

		return [
			'response' => [ 'code' => 422 ],
			'body'     => wp_json_encode( [ 'error' => 'Invalid card' ] ),
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * The last API request body captured by the mock.
	 *
	 * @var array|null
	 */
	private ?array $last_api_body = null;

	/**
	 * Build and dispatch a create-payment-intent request with sensible defaults.
	 *
	 * @param array $overrides Parameters to override the defaults.
	 * @return \WP_REST_Response
	 */
	private function make_request( array $overrides = [] ): \WP_REST_Response {
		$defaults = [
			'donation_amount'  => 5000,
			'tip_amount'       => 0,
			'fee_amount'       => 0,
			'currency'         => 'usd',
			'donor_email'      => 'jane@example.com',
			'donor_first_name' => 'Jane',
			'donor_last_name'  => 'Doe',
			'frequency'        => 'one_time',
			'campaign_id'      => $this->campaign_id,
		];

		$params = array_merge( $defaults, $overrides );

		$request = new WP_REST_Request( 'POST', '/mission/v1/donations/create-payment-intent' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Swap the default API mock for a different one.
	 *
	 * @param string $method_name The mock method name on this class.
	 */
	private function swap_mock( string $method_name ): void {
		remove_filter( 'pre_http_request', [ $this, 'mock_api_success' ], 10 );
		add_filter( 'pre_http_request', [ $this, $method_name ], 10, 3 );
	}

	// =========================================================================
	// Validation
	// =========================================================================

	/**
	 * Test rejects invalid email.
	 */
	public function test_rejects_invalid_email(): void {
		$response = $this->make_request( [ 'donor_email' => 'not-an-email' ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_email', $response->as_error()->get_error_code() );
	}

	/**
	 * Test rejects zero amount.
	 */
	public function test_rejects_zero_amount(): void {
		$response = $this->make_request( [ 'donation_amount' => 0 ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'donation_below_minimum', $response->as_error()->get_error_code() );
	}

	/**
	 * Test rejects amount below $1 hard floor.
	 */
	public function test_rejects_below_hard_floor(): void {
		$response = $this->make_request( [ 'donation_amount' => 99 ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'donation_below_minimum', $response->as_error()->get_error_code() );
	}

	/**
	 * Test minimum amount ($1.00) succeeds.
	 */
	public function test_minimum_amount_succeeds(): void {
		$response = $this->make_request( [ 'donation_amount' => 100 ] );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test server enforces block-level minimum amount.
	 *
	 * Simulates bypassing the frontend minimum by sending a $5 donation
	 * to a form that requires $10, with the correct source_post_id and form_id.
	 */
	public function test_enforces_block_minimum_amount(): void {
		$form_id = 'f_testmin1';

		// Create a post containing a donation form block with a $10 minimum.
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:mission/donation-form {"formId":"' . $form_id . '","minimumAmount":1000,"campaignId":' . $this->campaign_id . '} /-->',
			'post_status'  => 'publish',
		] );

		// $5 donation — above the $1 hard floor but below the $10 block minimum.
		$response = $this->make_request( [
			'donation_amount' => 500,
			'source_post_id'  => $post_id,
			'form_id'         => $form_id,
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'donation_below_minimum', $response->as_error()->get_error_code() );

		// $10 donation — meets the block minimum.
		$response = $this->make_request( [
			'donation_amount' => 1000,
			'source_post_id'  => $post_id,
			'form_id'         => $form_id,
		] );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test rejects when Stripe is not connected (empty token).
	 */
	public function test_rejects_when_stripe_not_connected(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[
				'stripe_site_token'  => '',
				'stripe_fee_percent' => 2.9,
				'stripe_fee_fixed'   => 30,
			]
		);

		$response = $this->make_request();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'stripe_not_connected', $response->as_error()->get_error_code() );
	}

	/**
	 * Test required fields rejected when missing.
	 */
	public function test_required_fields_rejected_when_missing(): void {
		// Missing donation_amount.
		$request = new WP_REST_Request( 'POST', '/mission/v1/donations/create-payment-intent' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'donor_email'      => 'jane@example.com',
			'donor_first_name' => 'Jane',
			'donor_last_name'  => 'Doe',
		] ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );

		// Missing email.
		$request2 = new WP_REST_Request( 'POST', '/mission/v1/donations/create-payment-intent' );
		$request2->set_header( 'Content-Type', 'application/json' );
		$request2->set_body( wp_json_encode( [
			'donation_amount'  => 5000,
			'donor_first_name' => 'Jane',
			'donor_last_name'  => 'Doe',
		] ) );

		$response2 = $this->server->dispatch( $request2 );

		$this->assertSame( 400, $response2->get_status() );
	}

	// =========================================================================
	// API Communication
	// =========================================================================

	/**
	 * Test returns 502 on network failure.
	 */
	public function test_returns_502_on_network_failure(): void {
		$this->swap_mock( 'mock_api_network_failure' );

		$response = $this->make_request();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'mission_api_error', $response->as_error()->get_error_code() );
	}

	/**
	 * Test returns error on API non-200 response.
	 */
	public function test_returns_error_on_api_non_200(): void {
		$this->swap_mock( 'mock_api_non_200' );

		$response = $this->make_request();

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'payment_intent_failed', $response->as_error()->get_error_code() );
	}

	/**
	 * Test tip fee absorption adjusts API payload.
	 *
	 * With donation=5000, tip=500, fee_rate=2.9%, fixed=30:
	 *   total = 5500
	 *   stripe_fee_with_tip    = round(5500 * 0.029 + 30) = round(189.5) = 190 (PHP_ROUND_HALF_UP gives 190)
	 *   stripe_fee_without_tip = round(5000 * 0.029 + 30) = round(175)   = 175
	 *   tip_stripe_fee         = 190 - 175 = 15
	 *   API donation = 5000 + 15 = 5015
	 *   API tip      = 500 - 15  = 485
	 */
	public function test_tip_fee_absorption_adjusts_api_payload(): void {
		$this->make_request( [
			'donation_amount' => 5000,
			'tip_amount'      => 500,
		] );

		$this->assertNotNull( $this->last_api_body );
		$this->assertSame( 5015, $this->last_api_body['donation_amount'] );
		$this->assertSame( 485, $this->last_api_body['tip_amount'] );
	}

	// =========================================================================
	// Response
	// =========================================================================

	/**
	 * Test returns client secret and transaction ID.
	 */
	public function test_returns_client_secret_and_transaction_id(): void {
		$response = $this->make_request();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pi_test123_secret_abc456', $data['client_secret'] );
		$this->assertSame( 'acct_test_connected', $data['connected_account_id'] );
		$this->assertArrayHasKey( 'transaction_id', $data );
		$this->assertGreaterThan( 0, $data['transaction_id'] );
	}

	// =========================================================================
	// Donor Upsert
	// =========================================================================

	/**
	 * Test creates new donor.
	 */
	public function test_creates_new_donor(): void {
		$this->make_request( [
			'donor_email'      => 'newdonor@example.com',
			'donor_first_name' => 'Alice',
			'donor_last_name'  => 'Smith',
		] );

		$donor = Donor::find_by_email( 'newdonor@example.com' );

		$this->assertNotNull( $donor );
		$this->assertSame( 'Alice', $donor->first_name );
		$this->assertSame( 'Smith', $donor->last_name );
	}

	/**
	 * Test finds existing donor (no duplicate created).
	 */
	public function test_finds_existing_donor(): void {
		$existing = new Donor( [
			'email'      => 'existing@example.com',
			'first_name' => 'Bob',
			'last_name'  => 'Jones',
		] );
		$existing->save();

		$response = $this->make_request( [
			'donor_email'      => 'existing@example.com',
			'donor_first_name' => 'Bob',
			'donor_last_name'  => 'Jones',
		] );
		$data = $response->get_data();

		// Should still be only one donor with this email.
		$donors = Donor::query( [ 'search' => 'existing@example.com' ] );
		$this->assertCount( 1, $donors );

		// Transaction should reference the existing donor.
		$txn = Transaction::find( $data['transaction_id'] );
		$this->assertSame( $existing->id, $txn->donor_id );
	}

	/**
	 * Test updates donor address when provided.
	 */
	public function test_updates_donor_address(): void {
		$this->make_request( [
			'donor_email' => 'addr@example.com',
			'address_1'   => '123 Main St',
			'address_2'   => 'Suite 4',
			'city'        => 'Springfield',
			'state'       => 'IL',
			'zip'         => '62701',
			'country'     => 'US',
		] );

		$donor = Donor::find_by_email( 'addr@example.com' );

		$this->assertSame( '123 Main St', $donor->address_1 );
		$this->assertSame( 'Suite 4', $donor->address_2 );
		$this->assertSame( 'Springfield', $donor->city );
		$this->assertSame( 'IL', $donor->state );
		$this->assertSame( '62701', $donor->zip );
		$this->assertSame( 'US', $donor->country );
	}

	/**
	 * Test does not overwrite address when not provided.
	 */
	public function test_does_not_overwrite_address_when_not_provided(): void {
		$donor = new Donor( [
			'email'      => 'keepaddr@example.com',
			'first_name' => 'Keep',
			'last_name'  => 'Address',
			'address_1'  => '456 Oak Ave',
			'city'       => 'Portland',
			'state'      => 'OR',
			'zip'        => '97201',
			'country'    => 'US',
		] );
		$donor->save();

		// Request without address fields.
		$this->make_request( [
			'donor_email'      => 'keepaddr@example.com',
			'donor_first_name' => 'Keep',
			'donor_last_name'  => 'Address',
		] );

		$updated = Donor::find_by_email( 'keepaddr@example.com' );

		$this->assertSame( '456 Oak Ave', $updated->address_1 );
		$this->assertSame( 'Portland', $updated->city );
		$this->assertSame( 'OR', $updated->state );
	}

	// =========================================================================
	// Transaction Record
	// =========================================================================

	/**
	 * Test pending transaction created with correct fields.
	 */
	public function test_pending_transaction_created_with_correct_fields(): void {
		$response = $this->make_request( [
			'donation_amount' => 5000,
			'tip_amount'      => 500,
			'fee_amount'      => 175,
		] );
		$data = $response->get_data();
		$txn  = Transaction::find( $data['transaction_id'] );

		$this->assertSame( 'pending', $txn->status );
		$this->assertSame( 'one_time', $txn->type );
		$this->assertSame( 4825, $txn->amount ); // 5000 - 175
		$this->assertSame( 500, $txn->tip_amount ); // Original tip, not post-absorption.
		$this->assertSame( 175, $txn->fee_amount );
		$this->assertSame( 5500, $txn->total_amount ); // 5000 + 500
		$this->assertSame( 'stripe', $txn->payment_gateway );
		$this->assertSame( 'pi_test123', $txn->gateway_transaction_id );
	}

	/**
	 * Test total amount equals donation plus tip.
	 */
	public function test_total_amount_equals_donation_plus_tip(): void {
		$combos = [
			[ 'donation_amount' => 5000, 'tip_amount' => 0, 'expected_total' => 5000 ],
			[ 'donation_amount' => 5000, 'tip_amount' => 250, 'expected_total' => 5250 ],
			[ 'donation_amount' => 10000, 'tip_amount' => 1500, 'expected_total' => 11500 ],
		];

		foreach ( $combos as $combo ) {
			$response = $this->make_request( [
				'donation_amount' => $combo['donation_amount'],
				'tip_amount'      => $combo['tip_amount'],
			] );
			$data = $response->get_data();
			$txn  = Transaction::find( $data['transaction_id'] );

			$this->assertSame(
				$combo['expected_total'],
				$txn->total_amount,
				"donation={$combo['donation_amount']} + tip={$combo['tip_amount']} should equal {$combo['expected_total']}"
			);
		}
	}

	/**
	 * Test fee recovery stored correctly.
	 */
	public function test_fee_recovery_stored_correctly(): void {
		$response = $this->make_request( [
			'donation_amount' => 5175,
			'fee_amount'      => 175,
		] );
		$data = $response->get_data();
		$txn  = Transaction::find( $data['transaction_id'] );

		$this->assertSame( 5000, $txn->amount ); // 5175 - 175
		$this->assertSame( 175, $txn->fee_amount );
	}

	/**
	 * Test tip amounts at various percentages on a $50 (5000 cents) donation.
	 *
	 * The transaction should store the ORIGINAL tip from the request, not the
	 * post-absorption value.
	 */
	public function test_tip_amounts_at_various_percentages(): void {
		$percentages = [
			0  => 0,
			5  => 250,
			10 => 500,
			15 => 750,
			20 => 1000,
		];

		foreach ( $percentages as $pct => $tip ) {
			$response = $this->make_request( [
				'donation_amount' => 5000,
				'tip_amount'      => $tip,
			] );
			$data = $response->get_data();
			$txn  = Transaction::find( $data['transaction_id'] );

			$this->assertSame(
				$tip,
				$txn->tip_amount,
				"Tip at {$pct}% should store original amount {$tip}"
			);
		}
	}

	/**
	 * Test test mode flag.
	 */
	public function test_test_mode_flag(): void {
		// Test mode on.
		update_option(
			SettingsService::OPTION_NAME,
			[
				'stripe_site_token'  => 'tok_test_abc123',
				'stripe_fee_percent' => 2.9,
				'stripe_fee_fixed'   => 30,
				'test_mode'          => true,
			]
		);

		$response = $this->make_request( [ 'donor_email' => 'test1@example.com' ] );
		$txn      = Transaction::find( $response->get_data()['transaction_id'] );
		$this->assertTrue( $txn->is_test );

		// Test mode off.
		update_option(
			SettingsService::OPTION_NAME,
			[
				'stripe_site_token'  => 'tok_test_abc123',
				'stripe_fee_percent' => 2.9,
				'stripe_fee_fixed'   => 30,
				'test_mode'          => false,
			]
		);

		$response2 = $this->make_request( [ 'donor_email' => 'test2@example.com' ] );
		$txn2      = Transaction::find( $response2->get_data()['transaction_id'] );
		$this->assertFalse( $txn2->is_test );
	}

	/**
	 * Test recurring frequency stored.
	 */
	public function test_recurring_frequency_stored(): void {
		$response = $this->make_request( [ 'frequency' => 'monthly' ] );
		$txn      = Transaction::find( $response->get_data()['transaction_id'] );

		$this->assertSame( 'monthly', $txn->type );
	}

	/**
	 * Test anonymous flag stored.
	 */
	public function test_anonymous_flag_stored(): void {
		$response_anon = $this->make_request( [
			'is_anonymous' => true,
			'donor_email'  => 'anon@example.com',
		] );
		$txn_anon = Transaction::find( $response_anon->get_data()['transaction_id'] );
		$this->assertTrue( $txn_anon->is_anonymous );

		$response_named = $this->make_request( [
			'is_anonymous' => false,
			'donor_email'  => 'named@example.com',
		] );
		$txn_named = Transaction::find( $response_named->get_data()['transaction_id'] );
		$this->assertFalse( $txn_named->is_anonymous );
	}

	/**
	 * Test campaign resolution with a valid ID.
	 */
	public function test_campaign_resolution_valid_id(): void {
		$response = $this->make_request( [ 'campaign_id' => $this->campaign_id ] );
		$txn      = Transaction::find( $response->get_data()['transaction_id'] );

		$this->assertSame( $this->campaign_id, $txn->campaign_id );
	}

	// =========================================================================
	// Campaign Resolution
	// =========================================================================

	/**
	 * Test campaign is null when campaign_id is 0.
	 */
	public function test_campaign_null_when_zero(): void {
		$response = $this->make_request( [ 'campaign_id' => 0 ] );
		$txn      = Transaction::find( $response->get_data()['transaction_id'] );

		$this->assertNull( $txn->campaign_id );
	}

	/**
	 * Test campaign null when invalid (non-zero) ID.
	 */
	public function test_campaign_null_when_invalid_id(): void {
		$response = $this->make_request( [ 'campaign_id' => 999999 ] );
		$txn      = Transaction::find( $response->get_data()['transaction_id'] );

		$this->assertNull( $txn->campaign_id );
	}

	// =========================================================================
	// Transaction Meta
	// =========================================================================

	/**
	 * Test tribute fields stored in tributes table.
	 */
	public function test_tribute_fields_stored(): void {
		$response = $this->make_request( [
			'tribute_type' => 'in_honor',
			'honoree_name' => 'Grandma Rose',
			'notify_name'  => 'Uncle Bob',
			'notify_email' => 'bob@example.com',
		] );
		$txn     = Transaction::find( $response->get_data()['transaction_id'] );
		$tribute = $txn->tribute();

		$this->assertNotNull( $tribute );
		$this->assertSame( 'in_honor', $tribute->tribute_type );
		$this->assertSame( 'Grandma Rose', $tribute->honoree_name );
		$this->assertSame( 'Uncle Bob', $tribute->notify_name );
		$this->assertSame( 'bob@example.com', $tribute->notify_email );
	}

	/**
	 * Test custom fields stored as meta.
	 */
	public function test_custom_fields_stored(): void {
		$response = $this->make_request( [
			'custom_fields'        => [
				'company_name' => 'Acme Corp',
				'opt_in'       => true,
				'interests'    => [ 'education', 'healthcare' ],
			],
			'custom_fields_config' => [
				[ 'id' => 'company_name', 'type' => 'text', 'label' => 'Company Name' ],
				[ 'id' => 'opt_in', 'type' => 'checkbox', 'label' => 'Opt In' ],
				[ 'id' => 'interests', 'type' => 'multiselect', 'label' => 'Interests' ],
			],
		] );
		$txn = Transaction::find( $response->get_data()['transaction_id'] );

		$this->assertSame( 'Acme Corp', $txn->get_meta( 'custom_field_company_name' ) );
		$this->assertSame( '1', $txn->get_meta( 'custom_field_opt_in' ) );
		$this->assertSame(
			wp_json_encode( [ 'education', 'healthcare' ] ),
			$txn->get_meta( 'custom_field_interests' )
		);

		// Config stored as JSON.
		$config = json_decode( $txn->get_meta( 'custom_fields_config' ), true );
		$this->assertCount( 3, $config );
		$this->assertSame( 'company_name', $config[0]['id'] );
	}

	/**
	 * Test address stored as transaction meta (point-in-time snapshot).
	 */
	public function test_address_stored_as_meta(): void {
		$response = $this->make_request( [
			'address_1' => '789 Elm St',
			'city'      => 'Denver',
			'state'     => 'CO',
			'zip'       => '80202',
			'country'   => 'US',
		] );
		$txn = Transaction::find( $response->get_data()['transaction_id'] );

		$this->assertSame( '789 Elm St', $txn->get_meta( 'address_1' ) );
		$this->assertSame( 'Denver', $txn->get_meta( 'city' ) );
		$this->assertSame( 'CO', $txn->get_meta( 'state' ) );
		$this->assertSame( '80202', $txn->get_meta( 'zip' ) );
		$this->assertSame( 'US', $txn->get_meta( 'country' ) );
	}

	/**
	 * Test Stripe fee rates stored as meta.
	 */
	public function test_stripe_fee_rates_stored(): void {
		$response = $this->make_request();
		$txn      = Transaction::find( $response->get_data()['transaction_id'] );

		$this->assertSame( '2.9', $txn->get_meta( 'stripe_fee_percent' ) );
		$this->assertSame( '30', $txn->get_meta( 'stripe_fee_fixed' ) );
	}

	// =========================================================================
	// Settings Side Effect
	// =========================================================================

	// =========================================================================
	// Platform Fee Mode
	// =========================================================================

	/**
	 * Test fee_mode defaults to 'tip' and is sent to the API.
	 */
	public function test_fee_mode_defaults_to_tip(): void {
		$this->make_request();

		$this->assertNotNull( $this->last_api_body );
		$this->assertSame( 'tip', $this->last_api_body['fee_mode'] );
	}

	/**
	 * Test fee_mode 'flat' is forwarded to the API.
	 */
	public function test_fee_mode_flat_forwarded_to_api(): void {
		$this->make_request( [ 'fee_mode' => 'flat' ] );

		$this->assertNotNull( $this->last_api_body );
		$this->assertSame( 'flat', $this->last_api_body['fee_mode'] );
	}

	/**
	 * Test fee_mode is stored as transaction meta.
	 */
	public function test_fee_mode_stored_as_meta(): void {
		$response = $this->make_request( [ 'fee_mode' => 'flat' ] );
		$txn      = Transaction::find( $response->get_data()['transaction_id'] );

		$this->assertSame( 'flat', $txn->get_meta( 'fee_mode' ) );
	}

	/**
	 * Test fee_mode 'tip' is stored as transaction meta.
	 */
	public function test_fee_mode_tip_stored_as_meta(): void {
		$response = $this->make_request( [ 'fee_mode' => 'tip' ] );
		$txn      = Transaction::find( $response->get_data()['transaction_id'] );

		$this->assertSame( 'tip', $txn->get_meta( 'fee_mode' ) );
	}

	/**
	 * Test flat fee mode does not trigger tip fee absorption.
	 *
	 * When fee_mode=flat and tip_amount=0, the donation amount sent to the
	 * API should be unchanged (no fee absorption adjustment).
	 */
	public function test_flat_fee_mode_no_absorption(): void {
		$this->make_request( [
			'donation_amount' => 5000,
			'tip_amount'      => 0,
			'fee_mode'        => 'flat',
		] );

		$this->assertNotNull( $this->last_api_body );
		$this->assertSame( 5000, $this->last_api_body['donation_amount'] );
		$this->assertSame( 0, $this->last_api_body['tip_amount'] );
	}

	// =========================================================================
	// Settings Side Effect
	// =========================================================================

	/**
	 * Test saves stripe account ID on first call and does not overwrite.
	 */
	public function test_saves_stripe_account_id_on_first_call(): void {
		// Ensure no account ID is set.
		update_option(
			SettingsService::OPTION_NAME,
			[
				'stripe_site_token'  => 'tok_test_abc123',
				'stripe_fee_percent' => 2.9,
				'stripe_fee_fixed'   => 30,
				'stripe_account_id'  => '',
			]
		);

		$this->make_request( [ 'donor_email' => 'first@example.com' ] );

		$settings = new SettingsService();
		$this->assertSame( 'acct_test_connected', $settings->get( 'stripe_account_id' ) );

		// Pre-set a different account ID — should not be overwritten.
		$settings->update( [ 'stripe_account_id' => 'acct_existing_123' ] );

		$this->make_request( [ 'donor_email' => 'second@example.com' ] );

		$this->assertSame( 'acct_existing_123', $settings->get( 'stripe_account_id' ) );
	}
}
