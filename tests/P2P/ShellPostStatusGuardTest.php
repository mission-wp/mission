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
}
