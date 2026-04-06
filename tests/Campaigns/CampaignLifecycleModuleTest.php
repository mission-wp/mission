<?php
/**
 * Tests for the CampaignLifecycleModule class.
 *
 * @package Mission
 */

namespace Mission\Tests\Campaigns;

use Mission\Campaigns\CampaignLifecycleModule;
use Mission\Campaigns\CampaignPostType;
use Mission\Database\DatabaseModule;
use Mission\Models\ActivityLog;
use Mission\Models\Campaign;
use WP_UnitTestCase;

/**
 * CampaignLifecycleModule test class.
 */
class CampaignLifecycleModuleTest extends WP_UnitTestCase {

	private CampaignLifecycleModule $lifecycle;

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$cpt = new CampaignPostType();
		$cpt->register();

		$this->lifecycle = new CampaignLifecycleModule();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_activity_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Create and save a campaign.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Campaign
	 */
	private function create_campaign( array $overrides = [] ): Campaign {
		$campaign = new Campaign( array_merge(
			[
				'title'       => 'Test Campaign',
				'description' => 'A test campaign.',
			],
			$overrides
		) );

		$campaign->save();

		return $campaign;
	}

	// -------------------------------------------------------------------------
	// process_transitions() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test cron activates scheduled campaigns whose start date has arrived.
	 */
	public function test_process_transitions_activates_scheduled_campaigns(): void {
		$campaign = $this->create_campaign( [
			'status'     => 'scheduled',
			'date_start' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
		] );

		$this->lifecycle->process_transitions();

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'active', $fresh->status );
	}

	/**
	 * Test cron does not activate scheduled campaigns whose start date is in the future.
	 */
	public function test_process_transitions_leaves_future_scheduled(): void {
		$campaign = $this->create_campaign( [
			'status'     => 'scheduled',
			'date_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
		] );

		$this->lifecycle->process_transitions();

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'scheduled', $fresh->status );
	}

	/**
	 * Test cron ends active campaigns whose end date has passed.
	 */
	public function test_process_transitions_ends_active_campaigns(): void {
		$campaign = $this->create_campaign( [
			'status'   => 'active',
			'date_end' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
		] );

		$this->lifecycle->process_transitions();

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'ended', $fresh->status );
	}

	/**
	 * Test cron does not end active campaigns whose end date is in the future.
	 */
	public function test_process_transitions_leaves_future_active(): void {
		$campaign = $this->create_campaign( [
			'status'   => 'active',
			'date_end' => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
		] );

		$this->lifecycle->process_transitions();

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'active', $fresh->status );
	}

	// -------------------------------------------------------------------------
	// check_close_on_goal() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test close-on-goal transitions campaign to ended when goal is reached.
	 */
	public function test_close_on_goal_transitions_to_ended(): void {
		$campaign = $this->create_campaign( [
			'goal_amount'  => 10000,
			'total_raised' => 10000,
		] );
		$campaign->update_meta( 'close_on_goal', true );

		$this->lifecycle->check_close_on_goal( $campaign->id );

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'ended', $fresh->status );
	}

	/**
	 * Test close-on-goal does nothing when below goal.
	 */
	public function test_close_on_goal_does_nothing_below_goal(): void {
		$campaign = $this->create_campaign( [
			'goal_amount'  => 10000,
			'total_raised' => 5000,
		] );
		$campaign->update_meta( 'close_on_goal', true );

		$this->lifecycle->check_close_on_goal( $campaign->id );

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'active', $fresh->status );
	}

	/**
	 * Test close-on-goal does nothing without the meta flag.
	 */
	public function test_close_on_goal_does_nothing_without_meta(): void {
		$campaign = $this->create_campaign( [
			'goal_amount'  => 10000,
			'total_raised' => 10000,
		] );

		$this->lifecycle->check_close_on_goal( $campaign->id );

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'active', $fresh->status );
	}

	/**
	 * Test close-on-goal ignores test mode raised amount.
	 */
	public function test_close_on_goal_ignores_test_mode_raised(): void {
		$campaign = $this->create_campaign( [
			'goal_amount'       => 10000,
			'total_raised'      => 0,
			'test_total_raised' => 10000,
		] );
		$campaign->update_meta( 'close_on_goal', true );

		$this->lifecycle->check_close_on_goal( $campaign->id );

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'active', $fresh->status );
	}

	/**
	 * Test close-on-goal does nothing for non-active campaigns.
	 */
	public function test_close_on_goal_ignores_non_active(): void {
		$campaign = $this->create_campaign( [
			'status'       => 'ended',
			'goal_amount'  => 10000,
			'total_raised' => 10000,
		] );
		$campaign->update_meta( 'close_on_goal', true );

		$this->lifecycle->check_close_on_goal( $campaign->id );

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'ended', $fresh->status );
	}

	// -------------------------------------------------------------------------
	// maybe_update_status_on_save() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test date change moves active campaign to scheduled.
	 */
	public function test_date_change_active_to_scheduled(): void {
		$campaign = $this->create_campaign( [
			'status'     => 'active',
			'date_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
		] );

		$this->lifecycle->maybe_update_status_on_save( $campaign->id, $campaign );

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'scheduled', $fresh->status );
	}

	/**
	 * Test date change moves scheduled campaign to active.
	 */
	public function test_date_change_scheduled_to_active(): void {
		$campaign = $this->create_campaign( [
			'status'     => 'scheduled',
			'date_start' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
		] );

		$this->lifecycle->maybe_update_status_on_save( $campaign->id, $campaign );

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'active', $fresh->status );
	}

	/**
	 * Test clearing end date reopens an ended campaign.
	 */
	public function test_clearing_end_date_reopens_ended_campaign(): void {
		$campaign = $this->create_campaign( [
			'status'   => 'ended',
			'date_end' => null,
		] );

		$this->lifecycle->maybe_update_status_on_save( $campaign->id, $campaign );

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'active', $fresh->status );
	}

	/**
	 * Test clearing end date with future start date sets scheduled.
	 */
	public function test_clearing_end_date_with_future_start_sets_scheduled(): void {
		$campaign = $this->create_campaign( [
			'status'     => 'ended',
			'date_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
			'date_end'   => null,
		] );

		$this->lifecycle->maybe_update_status_on_save( $campaign->id, $campaign );

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'scheduled', $fresh->status );
	}

	// -------------------------------------------------------------------------
	// set_initial_status() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test initial status is scheduled for future start date.
	 */
	public function test_initial_status_scheduled_for_future_start(): void {
		$campaign = $this->create_campaign( [
			'date_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
		] );

		// Reset to active (simulate the default) and then run initial status.
		$campaign->status = 'active';
		$campaign->save();

		$this->lifecycle->set_initial_status( $campaign );

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'scheduled', $fresh->status );
	}

	/**
	 * Test initial status stays active when no future start date.
	 */
	public function test_initial_status_active_for_no_future_start(): void {
		$campaign = $this->create_campaign();

		$this->lifecycle->set_initial_status( $campaign );

		$fresh = Campaign::find( $campaign->id );
		$this->assertSame( 'active', $fresh->status );
	}

	// -------------------------------------------------------------------------
	// transition_status() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test status_changed hook fires with correct arguments.
	 */
	public function test_status_changed_hook_fires(): void {
		$fired_args = null;

		add_action( 'mission_campaign_status_changed', function () use ( &$fired_args ) {
			$fired_args = func_get_args();
		}, 10, 4 );

		$campaign = $this->create_campaign();
		$this->lifecycle->transition_status( $campaign, 'ended', 'test_reason' );

		$this->assertNotNull( $fired_args );
		$this->assertInstanceOf( Campaign::class, $fired_args[0] );
		$this->assertSame( 'active', $fired_args[1] );
		$this->assertSame( 'ended', $fired_args[2] );
		$this->assertSame( 'test_reason', $fired_args[3] );
	}

	/**
	 * Test transition_status returns false when status is unchanged.
	 */
	public function test_transition_status_returns_false_when_unchanged(): void {
		$campaign = $this->create_campaign();

		$result = $this->lifecycle->transition_status( $campaign, 'active' );

		$this->assertFalse( $result );
	}

	/**
	 * Test that transitioning to ended logs a campaign_ended activity event.
	 */
	public function test_transition_to_ended_logs_campaign_ended(): void {
		$campaign = $this->create_campaign( [ 'title' => 'Ending Campaign' ] );

		$this->lifecycle->transition_status( $campaign, 'ended', 'cron' );

		$entries = ActivityLog::query( [ 'event' => 'campaign_ended' ] );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'campaign', $entries[0]->object_type );
		$this->assertSame( $campaign->id, $entries[0]->object_id );

		$data = json_decode( $entries[0]->data, true );
		$this->assertSame( 'Ending Campaign', $data['title'] );
		$this->assertSame( $campaign->id, $data['campaign_id'] );
	}

	/**
	 * Test that non-ended transitions do not log activity events.
	 */
	public function test_transition_to_active_does_not_log_activity(): void {
		$campaign = $this->create_campaign( [
			'status'     => 'scheduled',
			'date_start' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
		] );

		$this->lifecycle->transition_status( $campaign, 'active', 'cron' );

		$entries = ActivityLog::query( [ 'object_type' => 'campaign' ] );
		// Only campaign_created from create_campaign, no campaign_ended or campaign_status_changed.
		$events = array_map( fn( $e ) => $e->event, $entries );
		$this->assertNotContains( 'campaign_ended', $events );
		$this->assertNotContains( 'campaign_status_changed', $events );
	}

	// -------------------------------------------------------------------------
	// execute_end_actions() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test ending a campaign drafts the campaign page when stop_donations_on_end is set.
	 */
	public function test_end_actions_drafts_campaign_page(): void {
		$campaign = $this->create_campaign();
		$campaign->update_meta( 'stop_donations_on_end', true );

		$this->lifecycle->transition_status( $campaign, 'ended', 'test' );

		$this->assertSame( 'draft', get_post_status( $campaign->post_id ) );
	}

	/**
	 * Test ending a campaign drafts page by default (stop_donations_on_end defaults true).
	 */
	public function test_end_actions_drafts_page_by_default(): void {
		$campaign = $this->create_campaign();
		// No meta set — should default to drafting.

		$this->lifecycle->transition_status( $campaign, 'ended', 'test' );

		$this->assertSame( 'draft', get_post_status( $campaign->post_id ) );
	}

	/**
	 * Test ending a campaign removes from listings when configured.
	 */
	public function test_end_actions_removes_from_listings(): void {
		$campaign = $this->create_campaign();
		$campaign->update_meta( 'remove_from_listings_on_end', true );

		$this->lifecycle->transition_status( $campaign, 'ended', 'test' );

		$fresh = Campaign::find( $campaign->id );
		$this->assertFalse( $fresh->show_in_listings );
	}

	/**
	 * Test ending a campaign does not remove from listings when not configured.
	 */
	public function test_end_actions_keeps_in_listings_by_default(): void {
		$campaign = $this->create_campaign();

		$this->lifecycle->transition_status( $campaign, 'ended', 'test' );

		$fresh = Campaign::find( $campaign->id );
		$this->assertTrue( $fresh->show_in_listings );
	}
}
