<?php
/**
 * Outgoing webhook event registry.
 *
 * @package MissionDP
 */

namespace MissionDP\OutgoingWebhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Static registry of available outgoing webhook events.
 */
class WebhookEvents {

	/**
	 * Get all available events.
	 *
	 * @return array<string, array{label: string, description: string, category: string}>
	 */
	public static function all(): array {
		$events = [
			'donation.completed'     => [
				'label'       => __( 'Donation completed', 'mission-donation-platform' ),
				'description' => __( 'Fires when a donation payment is completed.', 'mission-donation-platform' ),
				'category'    => 'donation',
			],
			'donation.refunded'      => [
				'label'       => __( 'Donation refunded', 'mission-donation-platform' ),
				'description' => __( 'Fires when a donation is refunded.', 'mission-donation-platform' ),
				'category'    => 'donation',
			],
			'subscription.created'   => [
				'label'       => __( 'Subscription created', 'mission-donation-platform' ),
				'description' => __( 'Fires when a new recurring donation is created.', 'mission-donation-platform' ),
				'category'    => 'subscription',
			],
			'subscription.renewed'   => [
				'label'       => __( 'Subscription renewed', 'mission-donation-platform' ),
				'description' => __( 'Fires when a recurring donation renews.', 'mission-donation-platform' ),
				'category'    => 'subscription',
			],
			'subscription.cancelled' => [
				'label'       => __( 'Subscription cancelled', 'mission-donation-platform' ),
				'description' => __( 'Fires when a recurring donation is cancelled.', 'mission-donation-platform' ),
				'category'    => 'subscription',
			],
			'subscription.failed'    => [
				'label'       => __( 'Subscription payment failed', 'mission-donation-platform' ),
				'description' => __( 'Fires when a recurring donation payment fails.', 'mission-donation-platform' ),
				'category'    => 'subscription',
			],
			'donor.created'          => [
				'label'       => __( 'Donor created', 'mission-donation-platform' ),
				'description' => __( 'Fires when a new donor record is created.', 'mission-donation-platform' ),
				'category'    => 'donor',
			],
			'donor.updated'          => [
				'label'       => __( 'Donor updated', 'mission-donation-platform' ),
				'description' => __( 'Fires when a donor record is updated.', 'mission-donation-platform' ),
				'category'    => 'donor',
			],
			'campaign.created'       => [
				'label'       => __( 'Campaign created', 'mission-donation-platform' ),
				'description' => __( 'Fires when a new campaign is created.', 'mission-donation-platform' ),
				'category'    => 'campaign',
			],
			'campaign.goal_reached'  => [
				'label'       => __( 'Campaign goal reached', 'mission-donation-platform' ),
				'description' => __( 'Fires when a campaign reaches its fundraising goal.', 'mission-donation-platform' ),
				'category'    => 'campaign',
			],
		];

		/**
		 * Filter the available outgoing webhook events.
		 *
		 * @param array<string, array{label: string, description: string, category: string}> $events Available events.
		 */
		return apply_filters( 'mission_outgoing_webhook_events', $events );
	}

	/**
	 * Get event categories with labels.
	 *
	 * @return array<string, string>
	 */
	public static function categories(): array {
		return [
			'donation'     => __( 'Donation Events', 'mission-donation-platform' ),
			'subscription' => __( 'Subscription Events', 'mission-donation-platform' ),
			'donor'        => __( 'Donor Events', 'mission-donation-platform' ),
			'campaign'     => __( 'Campaign Events', 'mission-donation-platform' ),
		];
	}

	/**
	 * Check whether an event ID is valid.
	 *
	 * @param string $event Event ID.
	 *
	 * @return bool
	 */
	public static function is_valid( string $event ): bool {
		return '*' === $event || array_key_exists( $event, static::all() );
	}

	/**
	 * Get events grouped by category.
	 *
	 * @return array<string, array{label: string, events: array<string, array{label: string, description: string}>}>
	 */
	public static function grouped(): array {
		$all        = static::all();
		$categories = static::categories();
		$grouped    = [];

		foreach ( $categories as $key => $label ) {
			$grouped[ $key ] = [
				'label'  => $label,
				'events' => [],
			];
		}

		foreach ( $all as $event_id => $event ) {
			$category = $event['category'];
			if ( isset( $grouped[ $category ] ) ) {
				$grouped[ $category ]['events'][ $event_id ] = [
					'label'       => $event['label'],
					'description' => $event['description'],
				];
			}
		}

		return $grouped;
	}
}
