<?php
/**
 * GiveWP status mappings.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Migrators\GiveWP\Maps;

defined( 'ABSPATH' ) || exit;

/**
 * Maps GiveWP donation, subscription, and campaign statuses to Mission's.
 */
class StatusMap {

	/**
	 * GiveWP donation post statuses that are never migrated.
	 *
	 * @var string[]
	 */
	public const SKIPPED_PAYMENT_STATUSES = [ 'trash' ];

	/**
	 * GiveWP subscription statuses that are never migrated.
	 *
	 * @var string[]
	 */
	public const SKIPPED_SUBSCRIPTION_STATUSES = [ 'trashed' ];

	/**
	 * Map a GiveWP donation post_status to a Mission transaction status.
	 *
	 * In GiveWP, 'publish' means a completed donation, not a published post.
	 *
	 * @param string $status GiveWP post_status.
	 */
	public static function payment_status( string $status ): string {
		return match ( strtolower( $status ) ) {
			'publish', 'complete', 'completed', 'give_subscription' => 'completed',
			'pending', 'processing', 'preapproval' => 'pending',
			'refunded'  => 'refunded',
			'cancelled' => 'cancelled',
			default     => 'failed', // failed, abandoned, revoked, unknown.
		};
	}

	/**
	 * Map a GiveWP subscription status to a Mission subscription status.
	 *
	 * GiveWP's terminal 'completed'/'expired' (billing schedule finished) have
	 * no Mission equivalent and map to 'cancelled'; the writer keeps the
	 * original status in subscription meta.
	 *
	 * @param string $status GiveWP subscription status.
	 */
	public static function subscription_status( string $status ): string {
		return match ( strtolower( $status ) ) {
			'active'  => 'active',
			'pending' => 'pending',
			'failing' => 'past_due',
			'paused', 'suspended' => 'paused',
			default   => 'cancelled', // cancelled, completed, expired, unknown.
		};
	}

	/**
	 * Map a GiveWP campaign goal type to a Mission goal type.
	 *
	 * @param string $goal_type GiveWP goal type.
	 */
	public static function campaign_goal_type( string $goal_type ): string {
		return match ( $goal_type ) {
			'donations', 'subscriptions', 'donationsFromSubscriptions' => 'donations',
			'donors', 'donorsFromSubscriptions' => 'donors',
			default => 'amount', // amount, amountFromSubscriptions, unknown.
		};
	}
}
