<?php
/**
 * Tests for the DonorNotesEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Donor;
use MissionDP\Models\Note;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * DonorNotesEndpoint test class.
 */
class DonorNotesEndpointTest extends WP_UnitTestCase {

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
	 * Default donor for tests.
	 *
	 * @var Donor
	 */
	private Donor $donor;

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_activity_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transaction_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_notes" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_campaigns" );

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

		// Create users.
		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->admin_id );

		// Create a default donor.
		$this->donor = new Donor( [
			'email'      => 'jane@example.com',
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
		] );
		$this->donor->save();

		// Configure settings.
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

		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_activity_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transaction_history" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_notes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );

		delete_option( SettingsService::OPTION_NAME );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a donor with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Donor
	 */
	private function create_donor( array $overrides = [] ): Donor {
		static $counter = 0;
		++$counter;

		$defaults = [
			'email'      => "donor{$counter}@example.com",
			'first_name' => "Donor{$counter}",
			'last_name'  => 'Test',
		];

		$donor = new Donor( array_merge( $defaults, $overrides ) );
		$donor->save();

		return $donor;
	}

	/**
	 * Create a donor note with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return DonorNote
	 */
	private function create_note( array $overrides = [] ): Note {
		$defaults = [
			'object_type' => 'donor',
			'object_id'   => $this->donor->id,
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
	// GET /donors/{id}/notes — 2 tests
	// =========================================================================

	/**
	 * Test GET returns notes for a donor.
	 */
	public function test_get_returns_notes_for_donor(): void {
		$note1 = $this->create_note( [ 'content' => 'First note.' ] );
		$note2 = $this->create_note( [ 'content' => 'Second note.' ] );

		$response = $this->dispatch_get( "/mission-donation-platform/v1/donors/{$this->donor->id}/notes" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data );

		// Verify expected fields on each note.
		$item = $data[0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'object_type', $item );
		$this->assertArrayHasKey( 'object_id', $item );
		$this->assertArrayHasKey( 'content', $item );
		$this->assertArrayHasKey( 'author_id', $item );
		$this->assertArrayHasKey( 'author_name', $item );
		$this->assertArrayHasKey( 'date_created', $item );

		$this->assertSame( 'donor', $item['object_type'] );
		$this->assertSame( $this->donor->id, $item['object_id'] );
		$this->assertSame( $this->admin_id, $item['author_id'] );
	}

	/**
	 * Test GET returns 404 when donor does not exist.
	 */
	public function test_get_404_when_donor_not_found(): void {
		$response = $this->dispatch_get( '/mission-donation-platform/v1/donors/999999/notes' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'donor_not_found', $response->as_error()->get_error_code() );
	}

	// =========================================================================
	// POST /donors/{id}/notes — 3 tests
	// =========================================================================

	/**
	 * Test POST creates a note with content and author.
	 */
	public function test_post_creates_note_with_content_and_author(): void {
		$response = $this->dispatch_post( "/mission-donation-platform/v1/donors/{$this->donor->id}/notes", [
			'content' => 'Important donor note.',
		] );
		$data = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Important donor note.', $data['content'] );
		$this->assertSame( $this->admin_id, $data['author_id'] );
		$this->assertSame( $this->donor->id, $data['object_id'] );

		// Verify persisted in DB.
		$note = Note::find( $data['id'] );
		$this->assertNotNull( $note );
		$this->assertSame( 'Important donor note.', $note->content );
		$this->assertSame( $this->admin_id, $note->author_id );
	}

	/**
	 * Test POST validates donor exists.
	 */
	public function test_post_validates_donor_exists(): void {
		$response = $this->dispatch_post( '/mission-donation-platform/v1/donors/999999/notes', [
			'content' => 'This donor does not exist.',
		] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'donor_not_found', $response->as_error()->get_error_code() );
	}

	/**
	 * Test POST rejects empty content.
	 */
	public function test_post_rejects_empty_content(): void {
		$response = $this->dispatch_post( "/mission-donation-platform/v1/donors/{$this->donor->id}/notes", [
			'content' => '   ',
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'empty_content', $response->as_error()->get_error_code() );
	}

	// =========================================================================
	// DELETE /donors/{id}/notes/{note_id} — 2 tests
	// =========================================================================

	/**
	 * Test DELETE removes a note.
	 */
	public function test_delete_removes_note(): void {
		$note = $this->create_note();

		$response = $this->dispatch_delete( "/mission-donation-platform/v1/donors/{$this->donor->id}/notes/{$note->id}" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['deleted'] );

		// Verify removed from DB.
		$this->assertNull( Note::find( $note->id ) );
	}

	/**
	 * Test DELETE returns 404 for nonexistent note.
	 */
	public function test_delete_404_for_nonexistent_note(): void {
		$response = $this->dispatch_delete( "/mission-donation-platform/v1/donors/{$this->donor->id}/notes/999999" );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'note_not_found', $response->as_error()->get_error_code() );
	}

	// =========================================================================
	// Permissions — 1 test
	// =========================================================================

	/**
	 * Test unauthenticated and subscriber requests are rejected.
	 */
	public function test_permissions_unauthenticated_rejected(): void {
		// Unauthenticated (user 0).
		wp_set_current_user( 0 );
		$response = $this->dispatch_get( "/mission-donation-platform/v1/donors/{$this->donor->id}/notes" );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );

		// Subscriber role.
		wp_set_current_user( $this->subscriber_id );
		$response = $this->dispatch_get( "/mission-donation-platform/v1/donors/{$this->donor->id}/notes" );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}
}
