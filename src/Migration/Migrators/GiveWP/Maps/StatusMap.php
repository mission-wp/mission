<?php
/**
 * GiveWP status mappings.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Migrators\GiveWP\Maps;

use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;

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
			'publish', 'complete', 'completed', 'give_subscription' => Transaction::STATUS_COMPLETED,
			'pending', 'processing', 'preapproval' => Transaction::STATUS_PENDING,
			'refunded'  => Transaction::STATUS_REFUNDED,
			'cancelled' => Transaction::STATUS_CANCELLED,
			default     => Transaction::STATUS_FAILED, // failed, abandoned, revoked, unknown.
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
			'active'  => Subscription::STATUS_ACTIVE,
			'pending' => Subscription::STATUS_PENDING,
			'failing' => Subscription::STATUS_PAST_DUE,
			'paused', 'suspended' => Subscription::STATUS_PAUSED,
			default   => Subscription::STATUS_CANCELLED, // cancelled, completed, expired, unknown.
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
