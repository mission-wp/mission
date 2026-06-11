<?php
/**
 * Tests for the CampaignSlug class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Campaigns;

use MissionDP\Campaigns\CampaignPostType;
use MissionDP\Campaigns\CampaignSlug;
use WP_UnitTestCase;

/**
 * CampaignSlug test class.
 */
class CampaignSlugTest extends WP_UnitTestCase {

	/**
	 * Test that sanitize produces a single lowercase path segment.
	 */
	public function test_sanitize_normalizes_input(): void {
		$this->assertSame( 'giving-page', CampaignSlug::sanitize( 'Giving Page!' ) );
		$this->assertSame( 'fundraisers', CampaignSlug::sanitize( 'FUNDRAISERS' ) );
		$this->assertSame( 'a-b', CampaignSlug::sanitize( 'a/b' ) );
	}

	/**
	 * Test that sanitize returns an empty string when nothing usable remains.
	 */
	public function test_sanitize_returns_empty_for_garbage(): void {
		$this->assertSame( '', CampaignSlug::sanitize( '!!!' ) );
		$this->assertSame( '', CampaignSlug::sanitize( '' ) );
	}

	/**
	 * Test reserved slug detection.
	 */
	public function test_is_reserved(): void {
		$this->assertTrue( CampaignSlug::is_reserved( 'category' ) );
		$this->assertTrue( CampaignSlug::is_reserved( 'wp-json' ) );
		$this->assertFalse( CampaignSlug::is_reserved( 'giving' ) );
	}

	/**
	 * Test that a published top-level page conflicts.
	 */
	public function test_find_conflict_matches_published_page(): void {
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'campaigns',
				'post_title'  => 'Campaigns',
				'post_status' => 'publish',
			]
		);

		$conflict = CampaignSlug::find_conflict( 'campaigns' );

		$this->assertNotNull( $conflict );
		$this->assertSame( 'Campaigns', $conflict->post_title );
	}

	/**
	 * Test that a draft page does not conflict.
	 */
	public function test_find_conflict_ignores_draft_page(): void {
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'campaigns',
				'post_status' => 'draft',
			]
		);

		$this->assertNull( CampaignSlug::find_conflict( 'campaigns' ) );
	}

	/**
	 * Test that a child page does not conflict with a top-level path.
	 */
	public function test_find_conflict_ignores_child_page(): void {
		$parent_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_status' => 'publish',
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'campaigns',
				'post_status' => 'publish',
				'post_parent' => $parent_id,
			]
		);

		$this->assertNull( CampaignSlug::find_conflict( 'campaigns' ) );
	}

	/**
	 * Test that blog posts only conflict under a postname-first permalink structure.
	 */
	public function test_find_conflict_matches_posts_only_under_postname_permalinks(): void {
		self::factory()->post->create(
			[
				'post_name'   => 'campaigns',
				'post_status' => 'publish',
			]
		);

		$this->set_permalink_structure( '/%year%/%monthnum%/%postname%/' );
		$this->assertNull( CampaignSlug::find_conflict( 'campaigns' ) );

		$this->set_permalink_structure( '/%postname%/' );
		$this->assertNotNull( CampaignSlug::find_conflict( 'campaigns' ) );
	}

	/**
	 * Test that campaign posts never conflict with the slug.
	 */
	public function test_find_conflict_ignores_campaign_posts(): void {
		( new CampaignPostType() )->register();

		self::factory()->post->create(
			[
				'post_type'   => CampaignPostType::POST_TYPE,
				'post_name'   => 'campaigns',
				'post_status' => 'publish',
			]
		);

		$this->set_permalink_structure( '/%postname%/' );
		$this->assertNull( CampaignSlug::find_conflict( 'campaigns' ) );
	}

	/**
	 * Test that first_available returns the default when nothing conflicts.
	 */
	public function test_first_available_prefers_default(): void {
		$this->assertSame( 'campaigns', CampaignSlug::first_available() );
	}

	/**
	 * Test that first_available falls through the candidate list.
	 */
	public function test_first_available_skips_taken_candidates(): void {
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'campaigns',
				'post_status' => 'publish',
			]
		);

		$this->assertSame( 'giving', CampaignSlug::first_available() );
	}

	/**
	 * Test that first_available falls back to numbered variants when all candidates are taken.
	 */
	public function test_first_available_falls_back_to_numbered_variants(): void {
		foreach ( [ 'campaigns', 'giving', 'fundraisers', 'mission-campaigns' ] as $slug ) {
			self::factory()->post->create(
				[
					'post_type'   => 'page',
					'post_name'   => $slug,
					'post_status' => 'publish',
				]
			);
		}

		$this->assertSame( 'campaigns-2', CampaignSlug::first_available() );

		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'campaigns-2',
				'post_status' => 'publish',
			]
		);

		$this->assertSame( 'campaigns-3', CampaignSlug::first_available() );
	}
}
