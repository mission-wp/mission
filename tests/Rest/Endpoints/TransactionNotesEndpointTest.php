<?php
/**
 * Tests for the TransactionNotesEndpoint class.
 *
 * @package Mission
 */

namespace Mission\Tests\Rest\Endpoints;

use Mission\Database\DatabaseModule;
use Mission\Models\Donor;
use Mission\Models\Transaction;
use Mission\Models\Note;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * TransactionNotesEndpoint test class.
 */
class TransactionNotesEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private int $subscriber_id;

	/**
	 * Default transaction for tests.
	 *
	 * @var Transaction
	 */
	private Transaction $transaction;

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

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->admin_id );

		// Create a donor and transaction for tests.
		$donor = new Donor( [
			'email'      => 'donor@example.com',
			'first_name' => 'Test',
			'last_name'  => 'Donor',
		] );
		$donor->save();

		$this->transaction = new Transaction( [
			'status'         => 'completed',
			'donor_id'       => $donor->id,
			'source_post_id' => 1,
			'amount'         => 5000,
			'total_amount'   => 5000,
		] );
		$this->transaction->save();

		update_option( SettingsService::OPTION_NAME, [
			'test_mode'         => false,
			'stripe_site_token' => 'test_site_token_123',
		] );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server, $wpdb;

		$wp_rest_server = null;
		wp_set_current_user( 0 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_activity_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transaction_history" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_notes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );
		// phpcs:enable

		delete_option( SettingsService::OPTION_NAME );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a transaction note with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return TransactionNote
	 */
	private function create_note( array $overrides = [] ): Note {
		$defaults = [
			'object_type' => 'transaction',
			'object_id'   => $this->transaction->id,
			'type'        => 'internal',
			'content'     => 'Test note content.',
			'author_id'   => $this->admin_id,
		];

		$note = new Note( array_merge( $defaults, $overrides ) );
		$note->save();

		return $note;
	}

	/**
	 * Dispatch a GET request.
	 *
	 * @param string $route  Route path.
	 * @param array  $params Query parameters.
	 * @return \WP_REST_Response
	 */
	private function dispatch_get( string $route, array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a POST request with JSON body.
	 *
	 * @param string $route Route path.
	 * @param array  $body  Body parameters.
	 * @return \WP_REST_Response
	 */
	private function dispatch_post( string $route, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a DELETE request.
	 *
	 * @param string $route Route path.
	 * @return \WP_REST_Response
	 */
	private function dispatch_delete( string $route ): \WP_REST_Response {
		$request = new WP_REST_Request( 'DELETE', $route );

		return $this->server->dispatch( $request );
	}

	// =========================================================================
	// GET /transactions/{id}/notes — 2 tests
	// =========================================================================

	/**
	 * Test GET returns notes for a transaction (both internal and donor types).
	 */
	public function test_get_returns_notes_for_transaction(): void {
		$this->create_note( [ 'type' => 'internal', 'content' => 'Internal note.' ] );
		$this->create_note( [ 'type' => 'donor', 'content' => 'Donor note.' ] );

		$response = $this->dispatch_get( "/mission/v1/transactions/{$this->transaction->id}/notes" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data );

		// Verify expected fields on each note.
		$item = $data[0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'object_type', $item );
		$this->assertArrayHasKey( 'object_id', $item );
		$this->assertArrayHasKey( 'type', $item );
		$this->assertArrayHasKey( 'content', $item );
		$this->assertArrayHasKey( 'author_id', $item );
		$this->assertArrayHasKey( 'author_name', $item );
		$this->assertArrayHasKey( 'date_created', $item );

		$this->assertSame( 'transaction', $item['object_type'] );
		$this->assertSame( $this->transaction->id, $item['object_id'] );

		// Verify both types are present.
		$types = array_column( $data, 'type' );
		$this->assertContains( 'internal', $types );
		$this->assertContains( 'donor', $types );
	}

	/**
	 * Test GET filters notes by type.
	 */
	public function test_get_filters_by_note_type(): void {
		$this->create_note( [ 'type' => 'internal', 'content' => 'Internal only.' ] );
		$this->create_note( [ 'type' => 'donor', 'content' => 'Donor visible.' ] );
		$this->create_note( [ 'type' => 'internal', 'content' => 'Another internal.' ] );

		$response = $this->dispatch_get(
			"/mission/v1/transactions/{$this->transaction->id}/notes",
			[ 'type' => 'internal' ]
		);
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data );

		foreach ( $data as $item ) {
			$this->assertSame( 'internal', $item['type'] );
		}
	}

	// =========================================================================
	// POST /transactions/{id}/notes — 3 tests
	// =========================================================================

	/**
	 * Test POST creates an internal note.
	 */
	public function test_post_creates_internal_note(): void {
		$response = $this->dispatch_post( "/mission/v1/transactions/{$this->transaction->id}/notes", [
			'content' => 'An internal note.',
			'type'    => 'internal',
		] );
		$data = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'An internal note.', $data['content'] );
		$this->assertSame( 'internal', $data['type'] );
		$this->assertSame( $this->admin_id, $data['author_id'] );
		$this->assertSame( $this->transaction->id, $data['object_id'] );

		// Verify persisted in DB.
		$note = Note::find( $data['id'] );
		$this->assertNotNull( $note );
		$this->assertSame( 'An internal note.', $note->content );
	}

	/**
	 * Test POST creates a donor-visible note.
	 */
	public function test_post_creates_donor_visible_note(): void {
		$response = $this->dispatch_post( "/mission/v1/transactions/{$this->transaction->id}/notes", [
			'content' => 'A donor-visible note.',
			'type'    => 'donor',
		] );
		$data = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'donor', $data['type'] );
		$this->assertSame( 'A donor-visible note.', $data['content'] );
	}

	/**
	 * Test POST validates transaction_id exists.
	 */
	public function test_post_validates_transaction_exists(): void {
		$response = $this->dispatch_post( '/mission/v1/transactions/999999/notes', [
			'content' => 'This transaction does not exist.',
			'type'    => 'internal',
		] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'transaction_not_found', $response->as_error()->get_error_code() );
	}

	// =========================================================================
	// DELETE /transactions/{id}/notes/{note_id} — 1 test
	// =========================================================================

	/**
	 * Test DELETE removes a note by ID.
	 */
	public function test_delete_removes_note(): void {
		$note = $this->create_note();

		$response = $this->dispatch_delete(
			"/mission/v1/transactions/{$this->transaction->id}/notes/{$note->id}"
		);
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['deleted'] );

		// Verify removed from DB.
		$this->assertNull( Note::find( $note->id ) );
	}

	/**
	 * Test DELETE returns 404 for nonexistent note.
	 */
	public function test_delete_returns_404_for_nonexistent_note(): void {
		$response = $this->dispatch_delete(
			"/mission/v1/transactions/{$this->transaction->id}/notes/999999"
		);

		$this->assertSame( 404, $response->get_status() );
	}

	// =========================================================================
	// Permissions — 1 test
	// =========================================================================

	/**
	 * Test unauthenticated requests are rejected.
	 */
	public function test_permissions_unauthenticated_rejected(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch_get( "/mission/v1/transactions/{$this->transaction->id}/notes" );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );

		// Subscriber role.
		wp_set_current_user( $this->subscriber_id );
		$response = $this->dispatch_get( "/mission/v1/transactions/{$this->transaction->id}/notes" );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}
}
