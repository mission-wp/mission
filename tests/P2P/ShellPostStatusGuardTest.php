<?php
/**
 * Tests for the shell-post status drift guard.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\P2P;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use WP_UnitTestCase;

/**
 * Shell-post status guard test class.
 */
class ShellPostStatusGuardTest extends WP_UnitTestCase {

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		DatabaseModule::create_tables();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		parent::tear_down();
	}

	/**
	 * Test an external publish on an inactive fundraiser's post is reverted.
	 */
	public function test_external_publish_on_inactive_fundraiser_reverts_to_draft(): void {
		$fundraiser = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1, 'status' => 'inactive' ] );
		$fundraiser->save();

		$this->assertSame( 'draft', get_post_status( $fundraiser->post_id ) );

		wp_update_post(
			[
				'ID'          => $fundraiser->post_id,
				'post_status' => 'publish',
			]
		);

		$this->assertSame( 'draft', get_post_status( $fundraiser->post_id ) );
	}

	/**
	 * Test an external draft on an active team's post is reverted.
	 */
	public function test_external_draft_on_active_team_reverts_to_publish(): void {
		$team = new Team( [ 'campaign_id' => 1, 'name' => 'Trail Blazers', 'status' => 'active' ] );
		$team->save();

		$this->assertSame( 'publish', get_post_status( $team->post_id ) );

		wp_update_post(
			[
				'ID'          => $team->post_id,
				'post_status' => 'draft',
			]
		);

		$this->assertSame( 'publish', get_post_status( $team->post_id ) );
	}

	/**
	 * Test the revert fires the notice action with the requested and mapped statuses.
	 */
	public function test_revert_fires_notice_action(): void {
		$fundraiser = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1, 'status' => 'inactive' ] );
		$fundraiser->save();

		$fired = [];
		add_action(
			'mission_shell_post_status_reverted',
			static function ( $post_id, $requested, $mapped ) use ( &$fired ) {
				$fired = [ $post_id, $requested, $mapped ];
			},
			10,
			3
		);

		wp_update_post(
			[
				'ID'          => $fundraiser->post_id,
				'post_status' => 'publish',
			]
		);

		$this->assertSame( [ $fundraiser->post_id, 'publish', 'draft' ], $fired );

		remove_all_actions( 'mission_shell_post_status_reverted' );
	}

	/**
	 * Test the model's own approval flow still transitions the post status.
	 */
	public function test_model_approval_still_publishes(): void {
		$fundraiser = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1, 'status' => 'pending' ] );
		$fundraiser->save();

		$this->assertSame( 'pending', get_post_status( $fundraiser->post_id ) );

		$fundraiser->approve();

		$this->assertSame( 'publish', get_post_status( $fundraiser->post_id ) );
	}

	/**
	 * Test trashing a shell post is not blocked by the guard.
	 */
	public function test_trash_is_allowed(): void {
		$fundraiser = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1, 'status' => 'active' ] );
		$fundraiser->save();

		wp_trash_post( $fundraiser->post_id );

		$this->assertSame( 'trash', get_post_status( $fundraiser->post_id ) );
	}

	/**
	 * Test posts of other types are untouched by the guard.
	 */
	public function test_other_post_types_are_untouched(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * Test an external trash deactivates the fundraiser row and keeps the post trashed.
	 */
	public function test_external_trash_deactivates_fundraiser_row(): void {
		$fundraiser = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1, 'status' => 'active' ] );
		$fundraiser->save();

		$fired = did_action( 'mission_fundraiser_deactivated' );

		wp_trash_post( $fundraiser->post_id );

		$this->assertSame( 'inactive', Fundraiser::find( $fundraiser->id )->status );
		$this->assertSame( 'trash', get_post_status( $fundraiser->post_id ) );
		$this->assertSame( $fired + 1, did_action( 'mission_fundraiser_deactivated' ) );
	}

	/**
	 * Test an external trash deactivates the team row.
	 */
	public function test_external_trash_deactivates_team_row(): void {
		$team = new Team( [ 'campaign_id' => 1, 'name' => 'Trail Blazers', 'status' => 'active' ] );
		$team->save();

		wp_trash_post( $team->post_id );

		$this->assertSame( 'inactive', Team::find( $team->id )->status );
		$this->assertSame( 'trash', get_post_status( $team->post_id ) );
	}

	/**
	 * Test a direct status write to trash (bypassing wp_trash_post) deactivates the row.
	 */
	public function test_direct_trash_write_deactivates_fundraiser_row(): void {
		$fundraiser = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1, 'status' => 'active' ] );
		$fundraiser->save();

		// WP-CLI's `wp post update --post_status=trash` and cleanup plugins
		// write the status directly instead of calling wp_trash_post().
		wp_update_post(
			[
				'ID'          => $fundraiser->post_id,
				'post_status' => 'trash',
			]
		);

		$this->assertSame( 'inactive', Fundraiser::find( $fundraiser->id )->status );
		$this->assertSame( 'trash', get_post_status( $fundraiser->post_id ) );
	}

	/**
	 * Test untrashing after a direct trash write restores the mapped (draft) status.
	 */
	public function test_untrash_after_direct_trash_write_restores_draft(): void {
		$fundraiser = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1, 'status' => 'active' ] );
		$fundraiser->save();

		wp_update_post(
			[
				'ID'          => $fundraiser->post_id,
				'post_status' => 'trash',
			]
		);
		wp_untrash_post( $fundraiser->post_id );

		// The trash deactivated the row, so the restored post maps to draft
		// instead of flipping back to publish.
		$this->assertSame( 'inactive', Fundraiser::find( $fundraiser->id )->status );
		$this->assertSame( 'draft', get_post_status( $fundraiser->post_id ) );
	}

	/**
	 * Test an external force-delete deactivates the fundraiser row.
	 */
	public function test_external_delete_deactivates_fundraiser_row(): void {
		$fundraiser = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1, 'status' => 'active' ] );
		$fundraiser->save();

		wp_delete_post( $fundraiser->post_id, true );

		$this->assertSame( 'inactive', Fundraiser::find( $fundraiser->id )->status );
	}

	/**
	 * Test the model's own trash/delete still removes the row entirely.
	 */
	public function test_model_trash_and_delete_still_remove_rows(): void {
		$trashed = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1, 'status' => 'active' ] );
		$trashed->save();
		$trashed_post_id = $trashed->post_id;

		$this->assertTrue( $trashed->trash() );
		$this->assertNull( Fundraiser::find( $trashed->id ) );
		$this->assertSame( 'trash', get_post_status( $trashed_post_id ) );

		$deleted = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 2, 'status' => 'active' ] );
		$deleted->save();
		$deleted_post_id = $deleted->post_id;

		$this->assertTrue( $deleted->delete() );
		$this->assertNull( Fundraiser::find( $deleted->id ) );
		$this->assertNull( get_post( $deleted_post_id ) );
	}

	/**
	 * Test a wp_publish_post()-style direct write is reverted to the mapped status.
	 */
	public function test_direct_publish_write_is_reverted(): void {
		$fundraiser = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1, 'status' => 'inactive' ] );
		$fundraiser->save();

		$this->assertSame( 'draft', get_post_status( $fundraiser->post_id ) );

		// wp_publish_post updates the posts table directly, bypassing
		// the wp_insert_post_data filter.
		wp_publish_post( $fundraiser->post_id );

		$this->assertSame( 'draft', get_post_status( $fundraiser->post_id ) );
	}

	/**
	 * Test untrashing a shell post restores the mapped (deactivated) status.
	 */
	public function test_untrash_restores_mapped_status(): void {
		$fundraiser = new Fundraiser( [ 'campaign_id' => 1, 'donor_id' => 1, 'status' => 'active' ] );
		$fundraiser->save();

		wp_trash_post( $fundraiser->post_id );
		wp_untrash_post( $fundraiser->post_id );

		// The trash deactivated the row, so the restored post maps to draft.
		$this->assertSame( 'inactive', Fundraiser::find( $fundraiser->id )->status );
		$this->assertSame( 'draft', get_post_status( $fundraiser->post_id ) );
	}
}
