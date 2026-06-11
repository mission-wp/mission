<?php
/**
 * GiveWP payment gateway slug mapping.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Migrators\GiveWP\Maps;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes GiveWP gateway slugs to Mission's.
 *
 * Migrated transactions never go through gateway dispatch, so unrecognized
 * slugs are kept as-is for the audit trail rather than coerced.
 */
class GatewayMap {

	/**
	 * Map a GiveWP gateway slug to a Mission gateway slug.
	 *
	 * @param string $gateway GiveWP gateway slug.
	 */
	public static function map( string $gateway ): string {
		$gateway = strtolower( trim( $gateway ) );

		if ( '' === $gateway ) {
			return 'manual';
		}

		if ( str_starts_with( $gateway, 'stripe' ) || 'give-stripe' === $gateway ) {
			return 'stripe';
		}

		if ( str_starts_with( $gateway, 'paypal' ) ) {
			return 'paypal';
		}

		if ( in_array( $gateway, [ 'manual', 'offline', 'test-gateway', 'test-gateway-offsite' ], true ) ) {
			return 'manual';
		}

		return $gateway;
	}
}
