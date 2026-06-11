<?php
/**
 * Webhook delivery handler that executes HTTP delivery with signing and retries.
 *
 * @package MissionDP
 */

namespace MissionDP\OutgoingWebhooks;

use MissionDP\Models\OutgoingWebhook;
use MissionDP\Models\WebhookDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the actual HTTP delivery of outgoing webhooks.
 */
class DeliveryHandler {

	/**
	 * Maximum response body size to store in the delivery log (10 KB).
	 *
	 * @var int
	 */
	private const MAX_RESPONSE_BODY_SIZE = 10240;

	/**
	 * Maximum number of delivery attempts.
	 *
	 * @var int
	 */
	private const MAX_ATTEMPTS = 5;

	/**
	 * Consecutive failure count threshold for marking a webhook as failing.
	 *
	 * @var int
	 */
	private const FAILING_THRESHOLD = 15;

	/**
	 * Deliver a webhook by delivery ID.
	 *
	 * Called via Action Scheduler on the 'missiondp_deliver_webhook' hook.
	 *
	 * @param int $delivery_id Delivery record ID.
	 *
	 * @return void
	 */
	public function deliver( int $delivery_id ): void {
		$delivery = WebhookDelivery::find( $delivery_id );

		if ( ! $delivery || 'pending' !== $delivery->status ) {
			return;
		}

		$webhook = OutgoingWebhook::find( $delivery->webhook_id );

		if ( ! $webhook || 'active' !== $webhook->status ) {
			$delivery->status        = 'failed';
			$delivery->error_message = 'Webhook is paused or deleted.';
			$delivery->save();

			return;
		}

		$body = $delivery->request_body;

		// Build headers.
		$signature = hash_hmac( 'sha256', $body, $webhook->secret );

		$headers = [
			'Content-Type'        => 'application/json',
			'X-Mission-Event'     => $delivery->event,
			'X-Mission-Delivery'  => $delivery->event_id,
			'X-Mission-Signature' => 'sha256=' . $signature,
			'User-Agent'          => $this->get_user_agent(),
		];

		$delivery->request_headers = wp_json_encode( $headers );

		/** @var int $timeout HTTP request timeout in seconds. */
		$timeout = (int) apply_filters( 'mission_outgoing_webhook_timeout', 15 );

		$start = microtime( true );

		$response = wp_remote_post(
			$delivery->url,
			[
				'body'        => $body,
				'headers'     => $headers,
				'timeout'     => $timeout,
				'sslverify'   => true,
				'redirection' => 0,
			]
		);

		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		$delivery->duration_ms = $duration;

		if ( is_wp_error( $response ) ) {
			$this->handle_failure( $delivery, $webhook, $response->get_error_message() );

			return;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		// Filtered responses (pre_http_request) return headers as a plain array.
		$response_headers = wp_remote_retrieve_headers( $response );
		$response_headers = is_object( $response_headers ) ? $response_headers->getAll() : (array) $response_headers;

		$delivery->response_code    = $response_code;
		$delivery->response_headers = wp_json_encode( $response_headers );
		$delivery->response_body    = mb_substr( $response_body, 0, self::MAX_RESPONSE_BODY_SIZE );

		if ( $response_code >= 200 && $response_code < 300 ) {
			$this->handle_success( $delivery, $webhook );
		} else {
			$this->handle_failure(
				$delivery,
				$webhook,
				sprintf( 'HTTP %d: %s', $response_code, wp_remote_retrieve_response_message( $response ) )
			);
		}
	}

	/**
	 * Handle a successful delivery.
	 *
	 * @param WebhookDelivery $delivery The delivery.
	 * @param OutgoingWebhook $webhook  The webhook.
	 *
	 * @return void
	 */
	private function handle_success( WebhookDelivery $delivery, OutgoingWebhook $webhook ): void {
		$delivery->status = 'success';
		$delivery->save();

		$webhook->failure_count      = 0;
		$webhook->failing_since      = null;
		$webhook->health             = 'healthy';
		$webhook->last_delivery_at   = current_time( 'mysql', true );
		$webhook->last_response_code = $delivery->response_code;
		$webhook->save();

		/**
		 * Fires after a webhook delivery attempt.
		 *
		 * @param WebhookDelivery $delivery The delivery record.
		 * @param OutgoingWebhook $webhook  The webhook.
		 * @param bool            $success  Whether delivery succeeded.
		 */
		do_action( 'mission_outgoing_webhook_delivered', $delivery, $webhook, true );
	}

	/**
	 * Handle a failed delivery.
	 *
	 * @param WebhookDelivery $delivery      The delivery.
	 * @param OutgoingWebhook $webhook       The webhook.
	 * @param string          $error_message Error description.
	 *
	 * @return void
	 */
	private function handle_failure( WebhookDelivery $delivery, OutgoingWebhook $webhook, string $error_message ): void {
		$delivery->status        = 'failed';
		$delivery->error_message = mb_substr( $error_message, 0, 500 );
		$delivery->save();

		$previous_health = $webhook->health;

		++$webhook->failure_count;
		$webhook->last_delivery_at   = current_time( 'mysql', true );
		$webhook->last_response_code = $delivery->response_code;

		if ( $webhook->failure_count >= self::FAILING_THRESHOLD ) {
			$webhook->health = 'failing';
		}

		// Track when failures started.
		if ( 'healthy' === $previous_health && $webhook->failure_count >= self::FAILING_THRESHOLD ) {
			$webhook->failing_since = current_time( 'mysql', true );
		}

		// Auto-pause after prolonged continuous failure.
		if ( $webhook->failing_since ) {
			$failing_duration = time() - strtotime( $webhook->failing_since . ' UTC' );

			/** @var int $auto_pause_seconds Seconds of continuous failure before auto-pausing. */
			$auto_pause_seconds = (int) apply_filters( 'mission_outgoing_webhook_auto_pause_seconds', 7 * DAY_IN_SECONDS );

			if ( $failing_duration >= $auto_pause_seconds ) {
				$webhook->status = 'paused';

				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					as_unschedule_all_actions( 'missiondp_deliver_webhook', [], 'mission-webhook-' . $webhook->id );
				}

				/**
				 * Fires when a webhook is automatically paused due to prolonged failures.
				 *
				 * @param OutgoingWebhook $webhook The webhook that was paused.
				 */
				do_action( 'mission_outgoing_webhook_auto_paused', $webhook );
			}
		}

		$webhook->save();

		/** @see handle_success() for hook documentation. */
		do_action( 'mission_outgoing_webhook_delivered', $delivery, $webhook, false );

		$this->maybe_retry( $delivery, $webhook );
	}

	/**
	 * Schedule a retry if attempts remain.
	 *
	 * @param WebhookDelivery $delivery The failed delivery.
	 * @param OutgoingWebhook $webhook  The webhook.
	 *
	 * @return void
	 */
	private function maybe_retry( WebhookDelivery $delivery, OutgoingWebhook $webhook ): void {
		if ( $delivery->attempt >= self::MAX_ATTEMPTS ) {
			return;
		}

		// Without Action Scheduler there is no way to schedule a delayed retry.
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		/**
		 * Retry delay schedule in seconds (indexed 0 = first retry delay, etc.).
		 *
		 * @param int[] $delays Delay in seconds for each retry.
		 */
		$delays = (array) apply_filters( 'mission_outgoing_webhook_retry_delays', [ 60, 300, 1800, 7200 ] );

		$delay_index = $delivery->attempt - 1;
		$delay       = $delays[ $delay_index ] ?? end( $delays );

		$retry = new WebhookDelivery(
			[
				'webhook_id'   => $webhook->id,
				'event'        => $delivery->event,
				'event_id'     => $delivery->event_id,
				'url'          => $delivery->url,
				'request_body' => $delivery->request_body,
				'attempt'      => $delivery->attempt + 1,
				'status'       => 'pending',
			]
		);

		$retry_id = $retry->save();

		as_schedule_single_action(
			time() + $delay,
			'missiondp_deliver_webhook',
			[ $retry_id ],
			'mission-webhook-' . $webhook->id
		);
	}

	/**
	 * Get the User-Agent string for outgoing requests.
	 *
	 * @return string
	 */
	private function get_user_agent(): string {
		/** @var string $user_agent */
		$user_agent = apply_filters( 'mission_outgoing_webhook_user_agent', 'Mission-Webhooks/1.0' );

		return $user_agent;
	}
}
