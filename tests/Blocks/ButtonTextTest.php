<?php
/**
 * Tests that block button labels are editable and fall back to their defaults.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Blocks;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\P2P\SignupModal;
use WP_UnitTestCase;

/**
 * Block button text test class.
 */
class ButtonTextTest extends WP_UnitTestCase {

	/**
	 * Recreate the P2P tables so they carry the current schema.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		foreach ( [ 'fundraisers', 'fundraisermeta', 'teams', 'teammeta' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_{$table}" ); // phpcs:ignore WordPress.DB
		}

		DatabaseModule::create_tables();
	}

	/**
	 * Use live mode and a fresh signup modal for every test.
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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		parent::tear_down();
	}

	/**
	 * Render a block, failing on CI (skipping locally) when it is not built.
	 *
	 * @param string               $block      Block slug without the namespace.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	private function render( string $block, array $attributes ): string {
		$name = 'mission-donation-platform/' . $block;

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			if ( getenv( 'CI' ) ) {
				$this->fail( "{$name} is not registered. Run npm run build before the PHP suite in CI." );
			}

			$this->markTestSkipped( "{$name} is not built/registered in this environment." );
		}

		return do_blocks( sprintf( '<!-- wp:%s %s /-->', $name, wp_json_encode( $attributes ) ) );
	}

	/**
	 * Create a peer-to-peer campaign with fundraiser signup open.
	 *
	 * @return Campaign
	 */
	private function create_p2p_campaign(): Campaign {
		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$campaign->update_meta( 'registration_open', true );

		return $campaign;
	}

	/**
	 * Campaign Progress uses custom labels for both of its buttons.
	 */
	public function test_campaign_progress_uses_custom_button_text(): void {
		$campaign = $this->create_p2p_campaign();

		$html = $this->render(
			'campaign-progress',
			[
				'campaignId'           => $campaign->id,
				'donateButtonText'     => 'Je donne',
				'fundraiserButtonText' => 'Je collecte',
			]
		);

		$this->assertStringContainsString( 'Je donne', $html );
		$this->assertStringContainsString( 'Je collecte', $html );
		$this->assertStringNotContainsString( 'Donate Now', $html );
		$this->assertStringNotContainsString( 'Become a Fundraiser', $html );
	}

	/**
	 * A missing or emptied label falls back to the translatable default.
	 */
	public function test_campaign_progress_falls_back_to_default_button_text(): void {
		$campaign = $this->create_p2p_campaign();

		$unset = $this->render( 'campaign-progress', [ 'campaignId' => $campaign->id ] );
		$this->assertStringContainsString( 'Donate Now', $unset );
		$this->assertStringContainsString( 'Become a Fundraiser', $unset );

		$emptied = $this->render(
			'campaign-progress',
			[
				'campaignId'           => $campaign->id,
				'donateButtonText'     => '',
				'fundraiserButtonText' => '',
			]
		);
		$this->assertStringContainsString( 'Donate Now', $emptied );
		$this->assertStringContainsString( 'Become a Fundraiser', $emptied );
	}

	/**
	 * Custom labels are escaped on output.
	 */
	public function test_custom_button_text_is_escaped(): void {
		$campaign = $this->create_p2p_campaign();

		$html = $this->render(
			'campaign-progress',
			[
				'campaignId'       => $campaign->id,
				'donateButtonText' => 'Give <b>now</b>',
			]
		);

		$this->assertStringContainsString( 'Give &lt;b&gt;now&lt;/b&gt;', $html );
	}

	/**
	 * Fundraiser Progress uses a custom donate label.
	 */
	public function test_fundraiser_progress_uses_custom_button_text(): void {
		$campaign   = $this->create_p2p_campaign();
		$fundraiser = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => 1, 'status' => 'active', 'goal' => 10000 ] );
		$fundraiser->save();

		$html = $this->render(
			'fundraiser-progress',
			[
				'fundraiserId'     => $fundraiser->id,
				'donateButtonText' => 'Je donne',
			]
		);

		$this->assertStringContainsString( 'Je donne', $html );
		$this->assertStringNotContainsString( 'Donate Now', $html );
	}

	/**
	 * Team Progress uses custom labels for its donate and join buttons.
	 */
	public function test_team_progress_uses_custom_button_text(): void {
		$campaign = $this->create_p2p_campaign();
		$team     = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Open Crew', 'status' => 'active', 'access' => Team::ACCESS_PUBLIC ] );
		$team->save();

		$html = $this->render(
			'team-progress',
			[
				'teamId'           => $team->id,
				'donateButtonText' => 'Soutenir',
				'joinButtonText'   => 'Nous rejoindre',
			]
		);

		$this->assertStringContainsString( 'Soutenir', $html );
		$this->assertStringContainsString( 'Nous rejoindre', $html );
		$this->assertStringNotContainsString( 'Donate to the Team', $html );
		$this->assertStringNotContainsString( 'Join this Team', $html );
	}

	/**
	 * The empty-state buttons on the list blocks use custom labels.
	 *
	 * @dataProvider empty_state_blocks
	 *
	 * @param string $block     Block slug.
	 * @param string $attribute Label attribute name.
	 * @param string $fallback  Default English label.
	 */
	public function test_empty_state_button_uses_custom_text( string $block, string $attribute, string $fallback ): void {
		$campaign = $this->create_p2p_campaign();

		$custom = $this->render(
			$block,
			[
				'campaignId' => $campaign->id,
				$attribute   => 'Mon libellé',
			]
		);
		$this->assertStringContainsString( 'Mon libellé', $custom );
		$this->assertStringNotContainsString( $fallback, $custom );

		SignupModal::reset();

		$default = $this->render( $block, [ 'campaignId' => $campaign->id ] );
		$this->assertStringContainsString( $fallback, $default );
	}

	/**
	 * Blocks with an empty-state button, their label attribute and default label.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public function empty_state_blocks(): array {
		return [
			'recent donors'   => [ 'recent-donors', 'donateButtonText', 'Donate Now' ],
			'top donors'      => [ 'top-donors', 'donateButtonText', 'Be the First Donor' ],
			'top fundraisers' => [ 'top-fundraisers', 'ctaText', 'Become a Fundraiser' ],
			'top teams'       => [ 'top-teams', 'ctaText', 'Start a Team' ],
		];
	}

	/**
	 * The shortcode accepts the label as a snake_case attribute.
	 */
	public function test_shortcode_accepts_button_text(): void {
		$campaign = $this->create_p2p_campaign();

		if ( ! shortcode_exists( 'mission_campaign_progress' ) ) {
			$this->markTestSkipped( 'Shortcodes are not registered in this environment.' );
		}

		$html = do_shortcode( sprintf( '[mission_campaign_progress campaign_id="%d" donate_button_text="Je donne"]', $campaign->id ) );

		$this->assertStringContainsString( 'Je donne', $html );
		$this->assertStringNotContainsString( 'Donate Now', $html );
	}
}
