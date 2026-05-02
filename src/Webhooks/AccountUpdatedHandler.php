<?php
/**
 * Handler for account.updated webhook events.
 *
 * Fired when a connected Stripe account's status changes — most commonly
 * when a nonprofit completes onboarding and charges_enabled flips to true.
 *
 * @package MissionDP
 */

namespace MissionDP\Webhooks;

use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs the connected Stripe account's charges_enabled status.
 */
class AccountUpdatedHandler {

	/**
	 * Handle the event.
	 *
	 * @param array<string, mixed> $data Event data from the Mission API.
	 * @return void
	 */
	public function handle( array $data ): void {
		if ( ! isset( $data['charges_enabled'] ) ) {
			return;
		}

		$settings        = new SettingsService();
		$charges_enabled = (bool) $data['charges_enabled'];
		$was_enabled     = (bool) $settings->get( 'stripe_charges_enabled' );

		if ( $charges_enabled === $was_enabled ) {
			return;
		}

		$settings->update( [ 'stripe_charges_enabled' => $charges_enabled ] );

		if ( $charges_enabled ) {
			/**
			 * Fires when a connected Stripe account becomes able to process charges.
			 */
			do_action( 'missiondp_stripe_charges_enabled' );
		} else {
			/**
			 * Fires when a connected Stripe account loses the ability to process charges.
			 */
			do_action( 'missiondp_stripe_charges_disabled' );
		}
	}
}
