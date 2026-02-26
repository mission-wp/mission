<?php
/**
 * Tests for the FormPostType class.
 *
 * @package Mission
 */

namespace Mission\Tests\Forms;

use Mission\Forms\FormPostType;
use WP_UnitTestCase;

/**
 * FormPostType test class.
 */
class FormPostTypeTest extends WP_UnitTestCase {

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$form_post_type = new FormPostType();
		$form_post_type->register();
	}

	/**
	 * Test that the post type is registered.
	 */
	public function test_post_type_is_registered(): void {
		$this->assertTrue( post_type_exists( FormPostType::POST_TYPE ) );
	}

	/**
	 * Test that the post type supports expected features.
	 */
	public function test_post_type_supports_expected_features(): void {
		$this->assertTrue( post_type_supports( FormPostType::POST_TYPE, 'title' ) );
		$this->assertTrue( post_type_supports( FormPostType::POST_TYPE, 'editor' ) );
		$this->assertTrue( post_type_supports( FormPostType::POST_TYPE, 'revisions' ) );
		$this->assertFalse( post_type_supports( FormPostType::POST_TYPE, 'thumbnail' ) );
	}

	/**
	 * Test that the post type is not public.
	 */
	public function test_post_type_not_public(): void {
		$post_type = get_post_type_object( FormPostType::POST_TYPE );

		$this->assertFalse( $post_type->public );
		$this->assertTrue( $post_type->show_ui );
	}

	/**
	 * Test that the post type shows in REST.
	 */
	public function test_post_type_shows_in_rest(): void {
		$post_type = get_post_type_object( FormPostType::POST_TYPE );

		$this->assertTrue( $post_type->show_in_rest );
	}

	/**
	 * Test that expected post meta keys are registered.
	 */
	public function test_post_meta_keys_registered(): void {
		$registered = get_registered_meta_keys( 'post', FormPostType::POST_TYPE );

		$spot_check = array(
			'_mission_form_campaign_id',
			'_mission_form_amounts',
			'_mission_form_custom_amount',
			'_mission_form_recurring_enabled',
			'_mission_form_tip_enabled',
		);

		foreach ( $spot_check as $key ) {
			$this->assertArrayHasKey( $key, $registered, "Meta key {$key} not registered." );
		}
	}
}
