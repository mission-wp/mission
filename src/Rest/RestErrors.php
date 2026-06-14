<?php
/**
 * Factories for common REST error responses.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Single home for the entity-not-found errors repeated across endpoints.
 */
class RestErrors {

	/**
	 * Generic 404 error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Translated message.
	 * @return WP_Error
	 */
	public static function not_found( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => 404 ] );
	}

	/**
	 * Donor lookup failed.
	 *
	 * @return WP_Error
	 */
	public static function donor_not_found(): WP_Error {
		return self::not_found( 'donor_not_found', __( 'Donor not found.', 'mission-donation-platform' ) );
	}

	/**
	 * Transaction lookup failed.
	 *
	 * @return WP_Error
	 */
	public static function transaction_not_found(): WP_Error {
		return self::not_found( 'transaction_not_found', __( 'Transaction not found.', 'mission-donation-platform' ) );
	}

	/**
	 * Subscription lookup failed.
	 *
	 * @return WP_Error
	 */
	public static function subscription_not_found(): WP_Error {
		return self::not_found( 'subscription_not_found', __( 'Subscription not found.', 'mission-donation-platform' ) );
	}

	/**
	 * Campaign lookup failed.
	 *
	 * @return WP_Error
	 */
	public static function campaign_not_found(): WP_Error {
		return self::not_found( 'campaign_not_found', __( 'Campaign not found.', 'mission-donation-platform' ) );
	}

	/**
	 * Fundraiser lookup failed.
	 *
	 * @return WP_Error
	 */
	public static function fundraiser_not_found(): WP_Error {
		return self::not_found( 'fundraiser_not_found', __( 'Fundraiser not found.', 'mission-donation-platform' ) );
	}

	/**
	 * Team lookup failed.
	 *
	 * @return WP_Error
	 */
	public static function team_not_found(): WP_Error {
		return self::not_found( 'team_not_found', __( 'Team not found.', 'mission-donation-platform' ) );
	}

	/**
	 * Outgoing webhook lookup failed.
	 *
	 * @return WP_Error
	 */
	public static function webhook_not_found(): WP_Error {
		return self::not_found( 'webhook_not_found', __( 'Webhook not found.', 'mission-donation-platform' ) );
	}

	/**
	 * Note lookup failed.
	 *
	 * @return WP_Error
	 */
	public static function note_not_found(): WP_Error {
		return self::not_found( 'note_not_found', __( 'Note not found.', 'mission-donation-platform' ) );
	}

	/**
	 * Stripe account lookup failed.
	 *
	 * @return WP_Error
	 */
	public static function account_not_found(): WP_Error {
		return self::not_found( 'account_not_found', __( 'Stripe account not found.', 'mission-donation-platform' ) );
	}
}
