<?php
/**
 * Donation form settings resolver.
 *
 * @package Mission
 */

namespace Mission\Blocks;

use Mission\Campaigns\CampaignPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves effective donation form settings using the resolution chain:
 * 1. Block attributes (if explicitly set)
 * 2. Campaign post meta (if block has a campaignId or is on a campaign post)
 * 3. Plugin defaults
 */
class DonationFormSettings {

	/**
	 * Default settings used as the final fallback.
	 */
	private const DEFAULTS = array(
		'amounts'              => array( 1000, 2500, 5000, 10000 ),
		'customAmount'         => true,
		'minimumAmount'        => 500,
		'recurringEnabled'     => true,
		'recurringFrequencies' => array( 'monthly', 'quarterly', 'annually' ),
		'recurringDefault'     => 'one_time',
		'feeRecovery'          => true,
		'tipEnabled'           => true,
		'tipPercentages'       => array( 5, 10, 15 ),
		'anonymousEnabled'     => false,
		'tributeEnabled'       => false,
		'confirmationMessage'  => '',
	);

	/**
	 * Map from block attribute names to campaign post meta keys.
	 */
	private const META_MAP = array(
		'amounts'              => '_mission_campaign_amounts',
		'customAmount'         => '_mission_campaign_custom_amount',
		'minimumAmount'        => '_mission_campaign_minimum_amount',
		'recurringEnabled'     => '_mission_campaign_recurring_enabled',
		'recurringFrequencies' => '_mission_campaign_recurring_frequencies',
		'recurringDefault'     => '_mission_campaign_recurring_default',
		'feeRecovery'          => '_mission_campaign_fee_recovery',
		'tipEnabled'           => '_mission_campaign_tip_enabled',
		'tipPercentages'       => '_mission_campaign_tip_percentages',
		'anonymousEnabled'     => '_mission_campaign_anonymous_enabled',
		'tributeEnabled'       => '_mission_campaign_tribute_enabled',
		'confirmationMessage'  => '_mission_campaign_confirmation_message',
	);

	/**
	 * Resolve effective settings for a donation form block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 *
	 * @return array<string, mixed> Resolved settings.
	 */
	public static function resolve( array $attributes ): array {
		$campaign_id = self::resolve_campaign_id( $attributes );
		$settings    = array();

		foreach ( self::DEFAULTS as $key => $default ) {
			// 1. Block attribute wins if explicitly set.
			if ( isset( $attributes[ $key ] ) ) {
				$settings[ $key ] = $attributes[ $key ];
				continue;
			}

			// 2. Campaign post meta.
			if ( $campaign_id && isset( self::META_MAP[ $key ] ) ) {
				$meta_value = get_post_meta( $campaign_id, self::META_MAP[ $key ], true );
				if ( '' !== $meta_value ) {
					$settings[ $key ] = $meta_value;
					continue;
				}
			}

			// 3. Plugin defaults.
			$settings[ $key ] = $default;
		}

		/**
		 * Filters the resolved donation form settings.
		 *
		 * @param array<string, mixed> $settings    Resolved settings.
		 * @param array<string, mixed> $attributes  Original block attributes.
		 * @param int                  $campaign_id The campaign post ID (0 if none).
		 */
		return apply_filters( 'mission_donation_form_settings', $settings, $attributes, $campaign_id );
	}

	/**
	 * Determine the campaign post ID for this block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 *
	 * @return int Campaign post ID, or 0 if none.
	 */
	private static function resolve_campaign_id( array $attributes ): int {
		// Explicit campaignId attribute.
		if ( ! empty( $attributes['campaignId'] ) ) {
			return (int) $attributes['campaignId'];
		}

		// Auto-detect: if this block is rendered on a campaign post.
		$post = get_post();
		if ( $post && CampaignPostType::POST_TYPE === $post->post_type ) {
			return $post->ID;
		}

		return 0;
	}
}
