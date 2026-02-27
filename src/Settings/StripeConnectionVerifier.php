<?php
/**
 * Verifies Stripe API key connectivity.
 *
 * @package Mission
 */

namespace Mission\Settings;

use Stripe\Exception\AuthenticationException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe connection verifier class.
 */
class StripeConnectionVerifier {

	/**
	 * Verify a Stripe secret key by retrieving the connected account.
	 *
	 * @param string $secret_key Stripe secret key to verify.
	 * @return array{connected: bool, account_id?: string, name?: string, error?: string}
	 */
	public function verify( string $secret_key ): array {
		if ( empty( $secret_key ) ) {
			return array(
				'connected' => false,
				'error'     => __( 'Secret key is required.', 'mission' ),
			);
		}

		try {
			$client  = new StripeClient( $secret_key );
			$account = $client->accounts->retrieve();

			return array(
				'connected'  => true,
				'account_id' => $account->id,
				'name'       => $account->settings?->dashboard?->display_name ?? $account->business_profile?->name ?? '',
			);
		} catch ( AuthenticationException $e ) {
			return array(
				'connected' => false,
				'error'     => __( 'Invalid API key.', 'mission' ),
			);
		} catch ( ApiErrorException $e ) {
			return array(
				'connected' => false,
				'error'     => $e->getMessage(),
			);
		}
	}
}
