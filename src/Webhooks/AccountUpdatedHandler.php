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
	 * @param array<string, mixed> $data       Event data from the Mission API.
	 * @param string               $account_id Stripe account ID the event applies to.
	 * @return void
	 */
	public function handle( array $data, string $account_id = '' ): void {
		if ( ! isset( $data['charges_enabled'] ) ) {
			return;
		}

		$settings        = new SettingsService();
		$charges_enabled = (bool) $data['charges_enabled'];

		// Older API forwards may omit account_id; fall back to the only connected account.
		$target = '' !== $account_id ? $settings->get_stripe_account_by_id( $account_id ) : null;

		if ( ! $target ) {
			$accounts = $settings->get_stripe_accounts();
			if ( 1 === count( $accounts ) ) {
				$target = $accounts[0];
			}
		}

		if ( ! $target ) {
			return;
		}

		$was_enabled = ! empty( $target['charges_enabled'] );

		if ( $charges_enabled === $was_enabled ) {
			return;
		}

		$settings->update_stripe_account(
			(string) $target['account_id'],
			[ 'charges_enabled' => $charges_enabled ]
		);

		if ( $charges_enabled ) {
			/**
			 * Fires when a connected Stripe account becomes able to process charges.
			 *
			 * @param string $account_id The Stripe account ID that changed.
			 */
			do_action( 'mission_stripe_charges_enabled', (string) $target['account_id'] );
		} else {
			/**
			 * Fires when a connected Stripe account loses the ability to process charges.
			 *
			 * @param string $account_id The Stripe account ID that changed.
			 */
			do_action( 'mission_stripe_charges_disabled', (string) $target['account_id'] );
		}
	}
}
