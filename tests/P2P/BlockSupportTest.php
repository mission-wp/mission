<?php
/**
 * Unit tests for the shared P2P block rendering helpers.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\P2P;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\P2P\BlockSupport;
use WP_UnitTestCase;

/**
 * BlockSupport helper test class.
 */
class BlockSupportTest extends WP_UnitTestCase {

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
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		unset( $GLOBALS['post'] );

		parent::tear_down();
	}

	/**
	 * Create a p2p campaign.
	 *
	 * @return Campaign
	 */
	private function create_campaign(): Campaign {
		$campaign = new Campaign( [ 'title' => 'Winter Drive', 'type' => 'p2p' ] );
		$campaign->save();

		return $campaign;
	}

	/**
	 * An explicit campaignId attribute wins regardless of the queried post.
	 */
	public function test_resolve_campaign_from_attribute(): void {
		$campaign = $this->create_campaign();

		$resolved = BlockSupport::resolve_campaign( [ 'campaignId' => $campaign->id ] );

		$this->assertSame( $campaign->id, $resolved->id );
	}

	/**
	 * A queried campaign page resolves to its campaign.
	 */
	public function test_resolve_campaign_from_campaign_post(): void {
		$campaign         = $this->create_campaign();
		$GLOBALS['post'] = get_post( $campaign->post_id );

		$resolved = BlockSupport::resolve_campaign( [] );

		$this->assertSame( $campaign->id, $resolved->id );
	}

	/**
	 * A queried fundraiser shell page resolves to the parent campaign.
	 */
	public function test_resolve_campaign_from_fundraiser_shell_post(): void {
		$campaign   = $this->create_campaign();
		$fundraiser = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => 1, 'status' => 'active' ] );
		$fundraiser->save();
		$GLOBALS['post'] = get_post( $fundraiser->post_id );

		$resolved = BlockSupport::resolve_campaign( [] );

		$this->assertSame( $campaign->id, $resolved->id );
	}

	/**
	 * A queried team shell page resolves to the parent campaign.
	 */
	public function test_resolve_campaign_from_team_shell_post(): void {
		$campaign = $this->create_campaign();
		$team     = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Trail Blazers', 'status' => 'active' ] );
		$team->save();
		$GLOBALS['post'] = get_post( $team->post_id );

		$resolved = BlockSupport::resolve_campaign( [] );

		$this->assertSame( $campaign->id, $resolved->id );
	}

	/**
	 * An unrelated queried post resolves to nothing.
	 */
	public function test_resolve_campaign_null_on_unrelated_post(): void {
		$this->create_campaign();
		$GLOBALS['post'] = get_post( self::factory()->post->create() );

		$this->assertNull( BlockSupport::resolve_campaign( [] ) );
	}

	/**
	 * The default precision rounds to a whole int and clamps at 100.
	 */
	public function test_progress_percent_int(): void {
		$this->assertSame( 0, BlockSupport::progress_percent( 500, 0 ) );
		$this->assertSame( 25, BlockSupport::progress_percent( 2500, 10000 ) );
		$this->assertSame( 43, BlockSupport::progress_percent( 4250, 9900 ) );
		$this->assertSame( 100, BlockSupport::progress_percent( 15000, 10000 ) );
	}

	/**
	 * A positive precision returns a clamped float with that many decimals.
	 */
	public function test_progress_percent_float(): void {
		$this->assertSame( 0.0, BlockSupport::progress_percent( 500, 0, 2 ) );
		$this->assertSame( 42.93, BlockSupport::progress_percent( 4250, 9900, 2 ) );
		$this->assertSame( 100.0, BlockSupport::progress_percent( 15000, 10000, 2 ) );
	}

	/**
	 * Empty attributes produce no inline style.
	 */
	public function test_image_inline_style_empty(): void {
		$this->assertSame( '', BlockSupport::image_inline_style( [] ) );
	}

	/**
	 * Aspect ratio, dimensions, and scale render as image styles.
	 */
	public function test_image_inline_style_sizing(): void {
		$style = BlockSupport::image_inline_style(
			[
				'aspectRatio' => '16/9',
				'width'       => '300',
				'height'      => '200',
				'scale'       => 'contain',
			]
		);

		$this->assertStringContainsString( 'aspect-ratio:16/9', $style );
		$this->assertStringContainsString( 'object-fit:contain', $style );
		$this->assertStringContainsString( 'width:300px', $style );
		$this->assertStringContainsString( 'height:200px', $style );
	}

	/**
	 * An invalid scale falls back to cover.
	 */
	public function test_image_inline_style_invalid_scale_falls_back(): void {
		$style = BlockSupport::image_inline_style(
			[
				'aspectRatio' => '1/1',
				'scale'       => 'bogus',
			]
		);

		$this->assertStringContainsString( 'object-fit:cover', $style );
	}

	/**
	 * Border and shadow presets expand to CSS custom property references.
	 */
	public function test_image_inline_style_border_and_shadow_presets(): void {
		$style = BlockSupport::image_inline_style(
			[
				'style' => [
					'border' => [
						'radius' => '8px',
						'width'  => '2px',
						'style'  => 'solid',
						'color'  => 'var:preset|color|accent',
					],
					'shadow' => 'var:preset|shadow|natural',
				],
			]
		);

		$this->assertStringContainsString( 'border-radius:8px', $style );
		$this->assertStringContainsString( 'border-width:2px', $style );
		$this->assertStringContainsString( 'border-style:solid', $style );
		$this->assertStringContainsString( 'border-color:var(--wp--preset--color--accent)', $style );
		$this->assertStringContainsString( 'box-shadow:var(--wp--preset--shadow--natural)', $style );
	}

	/**
	 * Split border radius corners render individually.
	 */
	public function test_image_inline_style_split_radius(): void {
		$style = BlockSupport::image_inline_style(
			[
				'style' => [
					'border' => [
						'radius' => [
							'topLeft'     => '4px',
							'bottomRight' => '12px',
						],
					],
				],
			]
		);

		$this->assertStringContainsString( 'border-top-left-radius:4px', $style );
		$this->assertStringContainsString( 'border-bottom-right-radius:12px', $style );
	}

	/**
	 * An empty image value yields no markup.
	 */
	public function test_image_html_empty(): void {
		$this->assertSame( '', BlockSupport::image_html( '' ) );
	}

	/**
	 * A URL image renders an img tag carrying the extra attributes.
	 */
	public function test_image_html_url_with_attrs(): void {
		$html = BlockSupport::image_html(
			'https://example.com/photo.jpg',
			'A cover photo',
			'large',
			[ 'style' => 'aspect-ratio:16/9' ]
		);

		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'src="https://example.com/photo.jpg"', $html );
		$this->assertStringContainsString( 'alt="A cover photo"', $html );
		$this->assertStringContainsString( 'style="aspect-ratio:16/9"', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
	}

	/**
	 * Empty extra attribute values are skipped.
	 */
	public function test_image_html_url_skips_empty_attrs(): void {
		$html = BlockSupport::image_html(
			'https://example.com/photo.jpg',
			'',
			'large',
			[ 'style' => '' ]
		);

		$this->assertStringNotContainsString( 'style=', $html );
	}
}
