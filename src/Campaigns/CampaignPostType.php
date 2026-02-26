<?php
/**
 * Campaign custom post type registration.
 *
 * @package Mission
 */

namespace Mission\Campaigns;

use Mission\Database\DataStore\CampaignDataStore;
use Mission\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the mission_campaign post type and its meta fields.
 */
class CampaignPostType {

	public const POST_TYPE = 'mission_campaign';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'save_post_mission_campaign', array( $this, 'sync_goal_amount' ), 10, 2 );
	}

	/**
	 * Register the post type and its meta fields.
	 */
	public function register(): void {
		$this->register_post_type();
		$this->register_post_meta();
	}

	/**
	 * Register the mission_campaign post type.
	 */
	private function register_post_type(): void {
		$labels = array(
			'name'               => __( 'Campaigns', 'mission' ),
			'singular_name'      => __( 'Campaign', 'mission' ),
			'add_new'            => __( 'Add New', 'mission' ),
			'add_new_item'       => __( 'Add New Campaign', 'mission' ),
			'edit_item'          => __( 'Edit Campaign', 'mission' ),
			'new_item'           => __( 'New Campaign', 'mission' ),
			'view_item'          => __( 'View Campaign', 'mission' ),
			'search_items'       => __( 'Search Campaigns', 'mission' ),
			'not_found'          => __( 'No campaigns found.', 'mission' ),
			'not_found_in_trash' => __( 'No campaigns found in Trash.', 'mission' ),
			'all_items'          => __( 'Campaigns', 'mission' ),
		);

		$args = array(
			'labels'       => $labels,
			'public'       => true,
			'has_archive'  => true,
			'show_in_rest' => true,
			'show_in_menu' => false,
			'rewrite'      => array( 'slug' => 'campaigns' ),
			'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register all campaign post meta fields.
	 */
	private function register_post_meta(): void {
		$meta_fields = array(
			'_mission_campaign_amounts'               => array(
				'type'    => 'array',
				'default' => array( 1000, 2500, 5000, 10000 ),
				'items'   => array( 'type' => 'integer' ),
			),
			'_mission_campaign_custom_amount'         => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'_mission_campaign_minimum_amount'        => array(
				'type'    => 'integer',
				'default' => 500,
			),
			'_mission_campaign_recurring_enabled'     => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'_mission_campaign_recurring_frequencies' => array(
				'type'    => 'array',
				'default' => array( 'monthly', 'quarterly', 'annually' ),
				'items'   => array( 'type' => 'string' ),
			),
			'_mission_campaign_recurring_default'     => array(
				'type'    => 'string',
				'default' => 'one_time',
			),
			'_mission_campaign_fee_recovery'          => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'_mission_campaign_tip_enabled'           => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'_mission_campaign_tip_percentages'       => array(
				'type'    => 'array',
				'default' => array( 5, 10, 15 ),
				'items'   => array( 'type' => 'integer' ),
			),
			'_mission_campaign_anonymous_enabled'     => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'_mission_campaign_tribute_enabled'       => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'_mission_campaign_confirmation_message'  => array(
				'type'    => 'string',
				'default' => '',
			),
			'_mission_campaign_goal_amount'           => array(
				'type'    => 'integer',
				'default' => 0,
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

	/**
	 * Sync goal_amount from post meta to the campaigns custom table on save.
	 *
	 * Also creates the campaign table row if it doesn't exist yet.
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by save_post hook signature.
	public function sync_goal_amount( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$store    = new CampaignDataStore();
		$campaign = $store->find_by_post_id( $post_id );

		$goal_amount = (int) get_post_meta( $post_id, '_mission_campaign_goal_amount', true );

		if ( $campaign ) {
			$campaign->goal_amount = $goal_amount;
			$store->update( $campaign );
		} else {
			$campaign = new Campaign(
				array(
					'post_id'     => $post_id,
					'goal_amount' => $goal_amount,
				)
			);
			$store->create( $campaign );
		}
	}
}
