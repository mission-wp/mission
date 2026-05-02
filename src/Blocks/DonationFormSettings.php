<?php
/**
 * Donation form settings resolver.
 *
 * @package MissionDP
 */

namespace MissionDP\Blocks;

use MissionDP\Campaigns\CampaignPostType;
use MissionDP\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves effective donation form settings using a 2-tier resolution chain:
 * 1. Block attributes (if explicitly set)
 * 2. Plugin defaults
 */
class DonationFormSettings {

	/**
	 * Default settings used as the final fallback.
	 */
	private const DEFAULTS = [
		'amountsByFrequency'      => [
			'one_time' => [ 1000, 2500, 5000, 10000 ],
			'monthly'  => [ 1000, 2500, 5000, 10000 ],
		],
		'defaultAmounts'          => [],
		'customAmount'            => true,
		'minimumAmount'           => 500,
		'recurringEnabled'        => true,
		'recurringFrequencies'    => [ 'monthly', 'quarterly', 'annually' ],
		'recurringDefault'        => 'one_time',
		'feeRecovery'             => true,
		'feeMode'                 => 'optional',
		'tipEnabled'              => true,
		'tipPercentages'          => [ 5, 10, 15, 20 ],
		'collectAddress'          => true,
		'anonymousEnabled'        => false,
		'tributeEnabled'          => false,
		'commentsEnabled'         => false,
		'phoneRequired'           => false,
		'confirmationType'        => 'message',
		'confirmationRedirectUrl' => '',
		'amountDescriptions'      => [],
		'primaryColor'            => '',
		'continueButtonText'      => '',
		'donateButtonText'        => '',
		'chooseGiftHeading'       => '',
		'summaryHeading'          => '',
		'additionalInfoHeading'   => '',
		'customFields'            => [],
	];

	/**
	 * Resolve effective settings for a donation form block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 *
	 * @return array<string, mixed> Resolved settings.
	 */
	public static function resolve( array $attributes ): array {
		$campaign_id = self::resolve_campaign_id( $attributes );
		$settings    = [];

		foreach ( self::DEFAULTS as $key => $default ) {
			// Block attribute wins if explicitly set, otherwise use default.
			if ( isset( $attributes[ $key ] ) ) {
				$settings[ $key ] = $attributes[ $key ];
			} else {
				$settings[ $key ] = $default;
			}
		}

		$settings['campaignId'] = $campaign_id;
		$settings['currency']   = get_option( 'missiondp_settings', [] )['currency'] ?? 'USD';
		$settings['siteName']   = ( new \MissionDP\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) );

		$settings['globalPrimaryColor'] = get_option( 'missiondp_settings', [] )['primary_color'] ?? '#2fa36b';

		if ( ! empty( $settings['primaryColor'] ) ) {
			$settings['primaryColor'] = sanitize_hex_color( $settings['primaryColor'] ) ?: '';
		}

		/**
		 * Filters the resolved donation form settings.
		 *
		 * @param array<string, mixed> $settings    Resolved settings.
		 * @param array<string, mixed> $attributes  Original block attributes.
		 * @param int                  $campaign_id The campaign table ID (0 if none).
		 */
		return apply_filters( 'missiondp_donation_form_settings', $settings, $attributes, $campaign_id );
	}

	/**
	 * Determine the campaign table ID for this block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 *
	 * @return int Campaign table ID, or 0 if none.
	 */
	private static function resolve_campaign_id( array $attributes ): int {
		// Explicit campaignId attribute (campaign table ID).
		if ( ! empty( $attributes['campaignId'] ) ) {
			return (int) $attributes['campaignId'];
		}

		// Auto-detect: if this block is rendered on a campaign post, look up the table ID.
		$post = get_post();
		if ( $post && CampaignPostType::POST_TYPE === $post->post_type ) {
			$campaign = Campaign::find_by_post_id( $post->ID );
			return $campaign?->id ?? 0;
		}

		return 0;
	}
}
