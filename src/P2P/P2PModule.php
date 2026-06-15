<?php
/**
 * Peer-to-peer frontend module.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the peer-to-peer public surface: the fundraiser/team shell post types
 * (and, in later steps, their nested URLs, page rendering, and sign-up modal).
 */
class P2PModule {

	/**
	 * Fundraiser post type instance.
	 *
	 * @var FundraiserPostType
	 */
	private FundraiserPostType $fundraiser_post_type;

	/**
	 * Team post type instance.
	 *
	 * @var TeamPostType
	 */
	private TeamPostType $team_post_type;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->fundraiser_post_type = new FundraiserPostType();
		$this->team_post_type       = new TeamPostType();
	}

	/**
	 * Register hooks for all P2P frontend components.
	 */
	public function init(): void {
		$this->fundraiser_post_type->init();
		$this->team_post_type->init();
	}
}
