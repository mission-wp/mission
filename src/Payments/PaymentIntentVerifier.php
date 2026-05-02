<?php
/**
 * Synchronous PaymentIntent status verifier.
 *
 * Queries api.missionwp.com for the real-time status of a Stripe PaymentIntent
 * so the donation-form confirm endpoint can transition transactions based on
 * an authoritative answer rather than trusting the client. The Stripe webhook
 * remains the ultimate source of truth for asynchronous events (refunds,
 * subscription lifecycle, etc.) but synchronous verification here avoids the
 * stuck-pending problem and lets the donor see an immediate success screen.
 *
 * @package MissionDP
 */

namespace MissionDP\Payments;

use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * PaymentIntent verifier service.
 */
class PaymentIntentVerifier {

	/**
	 * Mission API base URL.
	 */
	private const API_BASE = 'https://api.missionwp.com';

	/**
	 * HTTP timeout for the verify call, in seconds.
	 */
	private const TIMEOUT = 15;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service.
	 */
	public function __construct(
		private readonly SettingsService $settings,
	) {}

	/**
	 * Verify a PaymentIntent's status with the Mission API.
	 *
	 * Return shape:
	 *
	 *   On success:
	 *     [
	 *       'verified'        => true,
	 *       'stripe_status'   => 'succeeded' | 'canceled' | 'requires_payment_method' | 'processing' | 'requires_action' | ...,
	 *       'amount_received' => int,
	 *       'currency'        => string,
	 *       'payment_method'  => [ 'brand' => string, 'last4' => string ],
	 *     ]
	 *
	 *   On unavailability (endpoint not deployed, network error, auth failure, etc.):
	 *     [
	 *       'verified' => false,
	 *       'reason'   => 'stripe_not_connected' | 'api_unreachable' | 'api_not_deployed' | 'api_error',
	 *     ]
	 *
	 * Callers should treat `verified => false` as "fall back to webhook authority" — do not transition
	 * the transaction, return a processing response, and let the webhook complete things async.
	 *
	 * @param string $payment_intent_id Stripe PaymentIntent ID (pi_xxx).
	 * @param bool   $test_mode         Whether the transaction is in test mode.
	 * @return array<string, mixed>
	 */
	public function verify( string $payment_intent_id, bool $test_mode ): array {
		$site_token = $this->settings->get( 'stripe_site_token' );

		if ( empty( $site_token ) ) {
			return [
				'verified' => false,
				'reason'   => 'stripe_not_connected',
			];
		}

		$response = wp_remote_post(
			self::API_BASE . '/confirm-payment-intent',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $site_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'payment_intent_id' => $payment_intent_id,
						'test_mode'         => $test_mode,
					]
				),
				'timeout' => self::TIMEOUT,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'verified' => false,
				'reason'   => 'api_unreachable',
			];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 404 === $code ) {
			return [
				'verified' => false,
				'reason'   => 'api_not_deployed',
			];
		}

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['status'] ) ) {
			return [
				'verified' => false,
				'reason'   => 'api_error',
			];
		}

		return [
			'verified'        => true,
			'stripe_status'   => (string) $body['status'],
			'amount_received' => isset( $body['amount_received'] ) ? (int) $body['amount_received'] : 0,
			'currency'        => isset( $body['currency'] ) ? strtolower( (string) $body['currency'] ) : '',
			'payment_method'  => is_array( $body['payment_method'] ?? null ) ? $body['payment_method'] : [],
		];
	}
}
