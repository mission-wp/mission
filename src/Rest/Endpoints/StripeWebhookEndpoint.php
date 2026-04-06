<?php
/**
 * REST endpoint for receiving webhook events from the Mission API.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Models\Transaction;
use Mission\Rest\RestModule;
use Mission\Settings\SettingsService;
use Mission\Webhooks\AccountUpdatedHandler;
use Mission\Webhooks\InvoicePaymentFailedHandler;
use Mission\Webhooks\InvoicePaymentSucceededHandler;
use Mission\Webhooks\PaymentIntentSucceededHandler;
use Mission\Webhooks\SubscriptionDeletedHandler;
use Mission\Webhooks\SubscriptionUpdatedHandler;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe webhook endpoint class.
 */
class StripeWebhookEndpoint {

	/**
	 * Maximum age of a webhook signature in seconds (5 minutes).
	 *
	 * @var int
	 */
	private const SIGNATURE_MAX_AGE = 300;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service.
	 */
	public function __construct(
		private readonly SettingsService $settings,
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/webhooks/stripe',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle an incoming webhook event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$signature = $request->get_header( 'X-Mission-Signature' );

		if ( ! $signature ) {
			return new WP_Error(
				'missing_signature',
				'Missing X-Mission-Signature header.',
				[ 'status' => 400 ]
			);
		}

		$body   = $request->get_body();
		$secret = $this->settings->get( 'stripe_webhook_secret' );

		if ( ! $secret ) {
			return new WP_Error(
				'webhook_not_configured',
				'Webhook secret is not configured.',
				[ 'status' => 500 ]
			);
		}

		if ( ! $this->verify_signature( $body, $signature, $secret ) ) {
			return new WP_Error(
				'invalid_signature',
				'Invalid webhook signature.',
				[ 'status' => 400 ]
			);
		}

		$payload = json_decode( $body, true );

		if ( ! $payload || empty( $payload['event_type'] ) ) {
			return new WP_Error(
				'invalid_payload',
				'Invalid or missing event payload.',
				[ 'status' => 400 ]
			);
		}

		$this->process_event( $payload );

		return new WP_REST_Response( [ 'received' => true ], 200 );
	}

	/**
	 * Verify the HMAC-SHA256 signature from the Mission API.
	 *
	 * @param string $body      Raw request body.
	 * @param string $signature Signature header value (t={timestamp},v1={hash}).
	 * @param string $secret    Webhook secret.
	 * @return bool
	 */
	private function verify_signature( string $body, string $signature, string $secret ): bool {
		$timestamp  = '';
		$signatures = [];

		foreach ( explode( ',', $signature ) as $part ) {
			$pair = explode( '=', $part, 2 );
			if ( count( $pair ) !== 2 ) {
				continue;
			}

			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( ! $timestamp || empty( $signatures ) ) {
			return false;
		}

		// Reject timestamps older than 5 minutes (replay protection).
		if ( abs( time() - (int) $timestamp ) > self::SIGNATURE_MAX_AGE ) {
			return false;
		}

		$signed_payload = $timestamp . '.' . $body;
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Process a webhook event by type.
	 *
	 * @param array<string, mixed> $payload Event payload.
	 * @return void
	 */
	private function process_event( array $payload ): void {
		$event_type = $payload['event_type'];
		$data       = $payload['data'] ?? [];

		/**
		 * Fires when a webhook event is received, before type-specific processing.
		 *
		 * @param string               $event_type Event type identifier.
		 * @param array<string, mixed> $data       Event data.
		 * @param array<string, mixed> $payload    Full event payload.
		 */
		do_action( 'mission_webhook_event', $event_type, $data, $payload );

		match ( $event_type ) {
			'account.updated'                => ( new AccountUpdatedHandler() )->handle( $data ),
			'charge.refunded'                => $this->handle_charge_refunded( $data ),
			'payment_intent.succeeded'       => ( new PaymentIntentSucceededHandler() )->handle( $data ),
			'invoice.paid',
			'invoice.payment_succeeded'      => ( new InvoicePaymentSucceededHandler() )->handle( $data ),
			'invoice.payment_failed'         => ( new InvoicePaymentFailedHandler() )->handle( $data ),
			'customer.subscription.deleted'  => ( new SubscriptionDeletedHandler() )->handle( $data ),
			'customer.subscription.paused',
			'customer.subscription.resumed',
			'customer.subscription.updated'  => ( new SubscriptionUpdatedHandler() )->handle( $data ),
			default                          => $this->handle_unknown_event( $event_type, $data ),
		};

		/**
		 * Fires after a webhook event has been processed.
		 *
		 * @param string               $event_type Event type identifier.
		 * @param array<string, mixed> $data       Event data.
		 * @param array<string, mixed> $payload    Full event payload.
		 */
		do_action( 'mission_webhook_event_processed', $event_type, $data, $payload );
	}

	/**
	 * Handle a charge.refunded event.
	 *
	 * @param array<string, mixed> $data Event data.
	 * @return void
	 */
	private function handle_charge_refunded( array $data ): void {
		$payment_intent_id = $data['payment_intent_id'] ?? '';

		if ( ! $payment_intent_id ) {
			return;
		}

		$transactions = Transaction::query(
			[
				'gateway_transaction_id' => $payment_intent_id,
				'per_page'               => 1,
			]
		);

		if ( empty( $transactions ) ) {
			return;
		}

		$transaction = $transactions[0];

		// Idempotency: skip if already fully refunded.
		if ( 'refunded' === $transaction->status ) {
			return;
		}

		$stripe_refunded = (int) ( $data['amount_refunded'] ?? 0 );

		// Cap at total_amount to avoid over-refunding.
		$new_refunded = min( $stripe_refunded, $transaction->total_amount );
		$refund_delta = $new_refunded - $transaction->amount_refunded;

		// Idempotency: skip if no new refund amount.
		if ( $refund_delta <= 0 ) {
			return;
		}

		$transaction->amount_refunded = $new_refunded;

		// Mark as fully refunded when the entire amount has been returned.
		if ( $new_refunded >= $transaction->total_amount ) {
			$transaction->status        = 'refunded';
			$transaction->date_refunded = current_time( 'mysql', true );
		}

		$transaction->save();
	}

	/**
	 * Handle an unrecognized event type.
	 *
	 * @param string               $event_type Event type.
	 * @param array<string, mixed> $data       Event data.
	 * @return void
	 */
	private function handle_unknown_event( string $event_type, array $data ): void {
		/**
		 * Fires for webhook event types not handled by the core plugin.
		 * Allows extensions to handle custom event types.
		 *
		 * @param array<string, mixed> $data Event data.
		 */
		do_action( "mission_webhook_{$event_type}", $data );
	}
}
