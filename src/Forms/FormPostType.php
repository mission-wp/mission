<?php
/**
 * Form custom post type registration.
 *
 * @package Mission
 */

namespace Mission\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the mission_form post type and its meta fields.
 */
class FormPostType {

	public const POST_TYPE = 'mission_form';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the post type and its meta fields.
	 */
	public function register(): void {
		$this->register_post_type();
		$this->register_post_meta();
	}

	/**
	 * Register the mission_form post type.
	 */
	private function register_post_type(): void {
		$labels = array(
			'name'               => __( 'Donation Forms', 'mission' ),
			'singular_name'      => __( 'Donation Form', 'mission' ),
			'add_new'            => __( 'Add New', 'mission' ),
			'add_new_item'       => __( 'Add New Donation Form', 'mission' ),
			'edit_item'          => __( 'Edit Donation Form', 'mission' ),
			'new_item'           => __( 'New Donation Form', 'mission' ),
			'view_item'          => __( 'View Donation Form', 'mission' ),
			'search_items'       => __( 'Search Donation Forms', 'mission' ),
			'not_found'          => __( 'No donation forms found.', 'mission' ),
			'not_found_in_trash' => __( 'No donation forms found in Trash.', 'mission' ),
			'all_items'          => __( 'Donation Forms', 'mission' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'show_in_rest'    => true,
			'capability_type' => 'post',
			'supports'        => array( 'title', 'editor', 'revisions' ),
			'has_archive'     => false,
			'rewrite'         => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register all form post meta fields.
	 */
	private function register_post_meta(): void {
		$meta_fields = array(
			'_mission_form_campaign_id'           => array(
				'type'    => 'integer',
				'default' => 0,
			),
			'_mission_form_amounts'               => array(
				'type'    => 'array',
				'default' => array( 1000, 2500, 5000, 10000 ),
				'items'   => array( 'type' => 'integer' ),
			),
			'_mission_form_custom_amount'         => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'_mission_form_minimum_amount'        => array(
				'type'    => 'integer',
				'default' => 500,
			),
			'_mission_form_recurring_enabled'     => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'_mission_form_recurring_frequencies' => array(
				'type'    => 'array',
				'default' => array( 'monthly', 'quarterly', 'annually' ),
				'items'   => array( 'type' => 'string' ),
			),
			'_mission_form_recurring_default'     => array(
				'type'    => 'string',
				'default' => 'one_time',
			),
			'_mission_form_fee_recovery'          => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'_mission_form_tip_enabled'           => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'_mission_form_tip_percentages'       => array(
				'type'    => 'array',
				'default' => array( 5, 10, 15 ),
				'items'   => array( 'type' => 'integer' ),
			),
			'_mission_form_anonymous_enabled'     => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'_mission_form_tribute_enabled'       => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'_mission_form_confirmation_message'  => array(
				'type'    => 'string',
				'default' => '',
			),
		);

		foreach ( $meta_fields as $meta_key => $config ) {
			$args = array(
				'type'          => $config['type'],
				'single'        => true,
				'show_in_rest'  => true,
				'default'       => $config['default'],
				'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
			);

			// Array types need a schema for REST.
			if ( 'array' === $config['type'] && isset( $config['items'] ) ) {
				$args['show_in_rest'] = array(
					'schema' => array(
						'type'  => 'array',
						'items' => $config['items'],
					),
				);
			}

			register_post_meta( self::POST_TYPE, $meta_key, $args );
		}
	}
}
