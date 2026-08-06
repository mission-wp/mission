<?php
/**
 * Tests for the sign-up modal shell service.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\P2P;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Team;
use MissionDP\P2P\SignupModal;
use WP_UnitTestCase;

/**
 * SignupModal service test class.
 */
class SignupModalTest extends WP_UnitTestCase {

	/**
	 * Recreate the P2P tables so they carry the current schema.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		foreach ( [ 'teams', 'teammeta' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_{$table}" ); // phpcs:ignore WordPress.DB
		}

		DatabaseModule::create_tables();
	}

	/**
	 * Reset the render-once flag and payload cache between tests.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'missiondp_settings', [ 'test_mode' => false, 'currency' => 'USD' ] );
		SignupModal::reset();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		SignupModal::reset();
		parent::tear_down();
	}

	/**
	 * Create an active P2P campaign with open registration.
	 *
	 * @param array $meta Extra P2P settings meta.
	 * @return Campaign
	 */
	private function make_open_campaign( array $meta = [] ): Campaign {
		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$campaign->update_meta( 'registration_open', true );
		foreach ( $meta as $key => $value ) {
			$campaign->update_meta( $key, $value );
		}

		return $campaign;
	}

	/**
	 * render() returns the dialog shell for an open campaign.
	 */
	public function test_render_returns_shell_for_open_campaign(): void {
		$campaign = $this->make_open_campaign();

		$html = SignupModal::render( $campaign );

		$this->assertStringContainsString( 'data-wp-interactive="mission-donation-platform/p2p-signup"', $html );
		$this->assertStringContainsString( 'mission-su__overlay', $html );
		$this->assertStringContainsString( 'mission-su__step', $html );
		$this->assertStringContainsString( 'mission-su__otp-input', $html );
	}

	/**
	 * render() returns the shell only once per request.
	 */
	public function test_render_returns_shell_once_per_request(): void {
		$campaign = $this->make_open_campaign();

		$this->assertNotSame( '', SignupModal::render( $campaign ) );
		$this->assertSame( '', SignupModal::render( $campaign ) );
	}

	/**
	 * render() returns nothing when registration is closed.
	 */
	public function test_render_empty_when_registration_closed(): void {
		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$campaign->update_meta( 'registration_open', false );

		$this->assertSame( '', SignupModal::render( $campaign ) );
	}

	/**
	 * render() returns nothing on a scheduled campaign even with the toggle on.
	 */
	public function test_render_empty_when_campaign_not_active(): void {
		$campaign = new Campaign(
			[
				'title'  => 'Drive',
				'type'   => 'p2p',
				'status' => Campaign::STATUS_SCHEDULED,
			]
		);
		$campaign->save();
		$campaign->update_meta( 'registration_open', true );

		$this->assertSame( '', SignupModal::render( $campaign ) );
	}

	/**
	 * A refused render() leaves the once-flag unset so a later CTA still gets
	 * the shell.
	 */
	public function test_refused_render_does_not_consume_the_once_flag(): void {
		$closed = new Campaign( [ 'title' => 'Closed', 'type' => 'p2p' ] );
		$closed->save();
		$closed->update_meta( 'registration_open', false );
		$open = $this->make_open_campaign();

		$this->assertSame( '', SignupModal::render( $closed ) );
		$this->assertStringContainsString( 'mission-su__overlay', SignupModal::render( $open ) );
	}

	/**
	 * The payload lists public active teams only and reflects the settings.
	 */
	public function test_payload_lists_public_active_teams_only(): void {
		$campaign = $this->make_open_campaign( [ 'teams_enabled' => true ] );

		( new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Open Crew', 'status' => 'active', 'access' => Team::ACCESS_PUBLIC ] ) )->save();
		( new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Closed Crew', 'status' => 'active', 'access' => Team::ACCESS_PRIVATE ] ) )->save();

		$payload = SignupModal::payload( $campaign );

		$this->assertSame( [ 'Open Crew' ], array_column( $payload['teams'], 'name' ) );
		$this->assertTrue( $payload['showTeamChooser'] );
		$this->assertFalse( $payload['teamCreationEnabled'] );
		$this->assertSame( $campaign->id, $payload['campaignId'] );
		$this->assertSame( 'Drive', $payload['brandline'] );
	}

	/**
	 * A preselected team locks the payload to that team.
	 */
	public function test_payload_with_preselected_team(): void {
		$campaign = $this->make_open_campaign( [ 'teams_enabled' => true ] );
		$team     = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Closed Crew', 'status' => 'active', 'access' => Team::ACCESS_PRIVATE ] );
		$team->save();

		$payload = SignupModal::payload( $campaign, $team );

		$this->assertSame( $team->id, $payload['preselectedTeamId'] );
		$this->assertSame( 'Closed Crew', $payload['preselectedTeamName'] );
		$this->assertSame( 'Join Closed Crew', $payload['brandline'] );
		$this->assertFalse( $payload['showTeamChooser'] );
	}

	/**
	 * The success/pending copy filters receive the payload's campaign.
	 */
	public function test_payload_success_filter_receives_campaign(): void {
		$campaign = $this->make_open_campaign();
		$received = null;

		add_filter(
			'mission_signup_success_title',
			static function ( $title, $filter_campaign ) use ( &$received ) {
				$received = $filter_campaign;
				return 'Filtered title';
			},
			10,
			2
		);

		$payload = SignupModal::payload( $campaign );

		$this->assertSame( 'Filtered title', $payload['successTitle'] );
		$this->assertSame( $campaign->id, $received->id );
	}

	/**
	 * The payload carries the first-gift defaults inherited from the campaign
	 * form's settings resolver.
	 */
	public function test_payload_kickoff_defaults(): void {
		$campaign = $this->make_open_campaign();

		$payload = SignupModal::payload( $campaign );

		$this->assertFalse( $payload['kickoffEnabled'] );
		$this->assertSame( [ 2500, 5000, 10000, 25000, 50000 ], $payload['kickoffAmounts'] );
		$this->assertSame( 'USD', $payload['currency'] );
		$this->assertSame( $campaign->post_id, $payload['campaignPostId'] );
		$this->assertSame( '', $payload['stripeAccountId'] );
		$this->assertTrue( $payload['tipEnabled'] );
		$this->assertTrue( $payload['feeRecovery'] );
		$this->assertSame( 'optional', $payload['feeMode'] );
		$this->assertNotSame( '', $payload['kickoffMessage'] );
	}

	/**
	 * kickoffEnabled follows the Stripe charges setting.
	 */
	public function test_payload_kickoff_enabled_when_charges_enabled(): void {
		update_option(
			'missiondp_settings',
			[
				'test_mode'              => true,
				'currency'               => 'USD',
				'stripe_charges_enabled' => true,
			]
		);
		$campaign = $this->make_open_campaign();

		$this->assertTrue( SignupModal::payload( $campaign )['kickoffEnabled'] );
	}

	/**
	 * The kickoff amounts filter replaces the presets and junk is discarded.
	 */
	public function test_payload_kickoff_amounts_filter(): void {
		$campaign = $this->make_open_campaign();

		add_filter(
			'mission_p2p_kickoff_amounts',
			static fn(): array => [ 1000, 0, -5, '7500' ]
		);

		$this->assertSame( [ 1000, 7500 ], SignupModal::payload( $campaign )['kickoffAmounts'] );
	}

	/**
	 * The payload inherits payment config saved on the campaign page's
	 * donation form block.
	 */
	public function test_payload_inherits_campaign_form_attributes(): void {
		$campaign = $this->make_open_campaign();
		wp_update_post(
			[
				'ID'           => $campaign->post_id,
				'post_content' => '<!-- wp:mission-donation-platform/donation-form {"stripeAccountId":"acct_123","feeMode":"required","tipEnabled":false} /-->',
			]
		);
		$campaign = Campaign::find( $campaign->id );

		$payload = SignupModal::payload( $campaign );

		$this->assertSame( 'acct_123', $payload['stripeAccountId'] );
		$this->assertSame( 'required', $payload['feeMode'] );
		$this->assertFalse( $payload['tipEnabled'] );
	}

	/**
	 * render() outputs the first-gift panels (surviving kses) when charges are
	 * enabled, and the share-only success panel when they are not.
	 */
	public function test_render_contains_first_gift_panels(): void {
		update_option(
			'missiondp_settings',
			[
				'test_mode'              => true,
				'currency'               => 'USD',
				'stripe_charges_enabled' => true,
			]
		);
		$campaign = $this->make_open_campaign();

		$html = SignupModal::render( $campaign );

		$this->assertStringContainsString( 'data-wp-text="state.kickoffHeadlineText"', $html );
		$this->assertStringContainsString( 'mission-su__payment-element', $html );
		$this->assertStringContainsString( 'data-wp-watch="callbacks.watchGiftAmounts"', $html );
		$this->assertStringContainsString( 'actions.submitGift', $html );
		$this->assertStringContainsString( 'actions.shareEmail', $html );
		$this->assertStringContainsString( 'mission-su__share-icon--email', $html );
		$this->assertStringContainsString( 'data-wp-bind--hidden="!state.isPending"', $html );
		$this->assertStringContainsString( 'Skip for now and view my page', $html );
		// Runtime-composed strings travel through the shell context.
		$this->assertStringContainsString( 'kickoffHeadline', $html );
		$this->assertStringContainsString( 'donateAndLaunch', $html );
	}

	/**
	 * With charges disabled the success step is the share-only panel.
	 */
	public function test_render_without_charges_shows_share_only_success(): void {
		$campaign = $this->make_open_campaign();

		$html = SignupModal::render( $campaign );

		$this->assertStringNotContainsString( 'mission-su__payment-element', $html );
		$this->assertStringNotContainsString( 'actions.submitGift', $html );
		$this->assertStringContainsString( 'data-wp-text="state.successMessage"', $html );
		$this->assertStringContainsString( 'actions.shareEmail', $html );
	}

	/**
	 * render() seeds the split donor name parts and Stripe config into the
	 * store's global state.
	 */
	public function test_render_seeds_payment_state(): void {
		$campaign = $this->make_open_campaign();

		SignupModal::render( $campaign );

		$state = wp_interactivity_state( 'mission-donation-platform/p2p-signup' );

		$this->assertArrayHasKey( 'donorFirstName', $state );
		$this->assertArrayHasKey( 'donorLastName', $state );
		$this->assertArrayHasKey( 'stripePublishableKey', $state );
		$this->assertArrayHasKey( 'stripeFeePercent', $state );
		$this->assertArrayHasKey( 'stripeFeeFixed', $state );
		$this->assertFalse( $state['testMode'] );
		$this->assertFalse( $state['kickoffEnabled'] );
	}

	/**
	 * Stripe.js is enqueued with the shared handle only when the kickoff can run.
	 */
	public function test_render_enqueues_stripe_js_only_when_kickoff_enabled(): void {
		// The scripts registry persists across tests; start from a clean slate.
		wp_dequeue_script( 'mission-stripe-js' );

		$campaign = $this->make_open_campaign();
		SignupModal::render( $campaign );
		$this->assertFalse( wp_script_is( 'mission-stripe-js', 'enqueued' ) );

		update_option(
			'missiondp_settings',
			[
				'test_mode'              => true,
				'currency'               => 'USD',
				'stripe_charges_enabled' => true,
			]
		);
		SignupModal::reset();
		SignupModal::render( $campaign );
		$this->assertTrue( wp_script_is( 'mission-stripe-js', 'enqueued' ) );
	}

	/**
	 * Payloads are memoized per request: a repeat call runs no queries.
	 */
	public function test_payload_is_memoized_per_request(): void {
		global $wpdb;

		$campaign = $this->make_open_campaign( [ 'teams_enabled' => true ] );
		( new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Open Crew', 'status' => 'active', 'access' => Team::ACCESS_PUBLIC ] ) )->save();

		$first   = SignupModal::payload( $campaign );
		$queries = $wpdb->num_queries;
		$second  = SignupModal::payload( $campaign );

		$this->assertSame( $first, $second );
		$this->assertSame( $queries, $wpdb->num_queries );
	}
}
