<?php
/**
 * Outgoing webhook module that wires event listeners and dispatches deliveries.
 *
 * @package MissionDP
 */

namespace MissionDP\OutgoingWebhooks;

use MissionDP\Models\OutgoingWebhook;
use MissionDP\Models\WebhookDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * Outgoing webhook module class.
 */
class OutgoingWebhookModule {

	/**
	 * Delivery handler instance.
	 *
	 * @var DeliveryHandler
	 */
	private DeliveryHandler $delivery_handler;

	/**
	 * Initialize the module.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->delivery_handler = new DeliveryHandler();

		$this->register_event_listeners();
		$this->register_cron_handler();
		$this->register_pruning();
	}

	/**
	 * Register WordPress action listeners for each webhook event.
	 *
	 * @return void
	 */
	private function register_event_listeners(): void {
		add_action( 'mission_transaction_status_pending_to_completed', [ $this, 'on_donation_completed' ] );
		add_action( 'mission_transaction_created', [ $this, 'on_transaction_created' ] );

		add_action( 'mission_transaction_refund_applied', [ $this, 'on_donation_refunded' ] );

		add_action( 'mission_subscription_created', [ $this, 'on_subscription_created' ] );

		add_action( 'mission_subscription_renewed', [ $this, 'on_subscription_renewed' ], 10, 2 );

		add_action( 'mission_subscription_status_active_to_cancelled', [ $this, 'on_subscription_cancelled' ] );
		add_action( 'mission_subscription_status_pending_to_cancelled', [ $this, 'on_subscription_cancelled' ] );
		add_action( 'mission_subscription_status_paused_to_cancelled', [ $this, 'on_subscription_cancelled' ] );
		add_action( 'mission_subscription_status_past_due_to_cancelled', [ $this, 'on_subscription_cancelled' ] );

		add_action( 'mission_subscription_status_active_to_failed', [ $this, 'on_subscription_failed' ] );
		add_action( 'mission_subscription_status_pending_to_failed', [ $this, 'on_subscription_failed' ] );

		add_action( 'mission_donor_created', [ $this, 'on_donor_created' ] );
		add_action( 'mission_donor_updated', [ $this, 'on_donor_updated' ] );

		add_action( 'mission_campaign_created', [ $this, 'on_campaign_created' ] );

		add_action( 'mission_campaign_milestone_reached', [ $this, 'on_campaign_milestone_reached' ], 10, 3 );
	}

	/**
	 * Register the cron handler for async delivery.
	 *
	 * @return void
	 */
	private function register_cron_handler(): void {
		add_action( 'missiondp_deliver_webhook', [ $this->delivery_handler, 'deliver' ] );
	}

	/**
	 * Register delivery log pruning on the existing daily cleanup cron.
	 *
	 * @return void
	 */
	private function register_pruning(): void {
		add_action( 'missiondp_daily_cleanup', [ $this, 'prune_deliveries' ] );
	}

	/**
	 * Prune old delivery log entries.
	 *
	 * @return void
	 */
	public function prune_deliveries(): void {
		/** @var int $days Number of days to retain delivery records. */
		$days = (int) apply_filters( 'mission_outgoing_webhook_delivery_retention_days', 30 );

		/** @var \MissionDP\Database\DataStore\WebhookDeliveryDataStore $store */
		$store = WebhookDelivery::store();
		$store->prune( $days );
	}

	/**
	 * Dispatch an event to all matching active webhooks.
	 *
	 * @param string               $event        Event name (e.g. 'donation.completed').
	 * @param array<string, mixed> $payload_data The 'data' portion of the payload.
	 * @param bool                 $is_test      Whether the event originated in test mode.
	 *
	 * @return void
	 */
	public function dispatch( string $event, array $payload_data, bool $is_test = false ): void {
		$webhooks = OutgoingWebhook::query( [ 'status' => 'active' ] );

		if ( empty( $webhooks ) ) {
			return;
		}

		$event_id = 'evt_' . bin2hex( random_bytes( 12 ) );

		$payload = [
			'id'         => $event_id,
			'event'      => $event,
			'created_at' => gmdate( 'c' ),
			'is_test'    => $is_test,
			'data'       => $payload_data,
		];

		/**
		 * Filter the outgoing webhook payload before delivery.
		 *
		 * @param array<string, mixed> $payload The full payload.
		 * @param string               $event   Event name.
		 */
		$payload = apply_filters( 'mission_outgoing_webhook_payload', $payload, $event );

		/**
		 * Filter whether the event should be dispatched at all (e.g. to suppress test-mode events).
		 *
		 * @param bool                 $should_dispatch Whether to dispatch. Default true.
		 * @param string               $event           Event name.
		 * @param array<string, mixed> $payload         The full payload.
		 */
		if ( ! apply_filters( 'mission_outgoing_webhook_should_dispatch', true, $event, $payload ) ) {
			return;
		}

		foreach ( $webhooks as $webhook ) {
			if ( ! $webhook->subscribes_to( $event ) ) {
				continue;
			}

			$delivery = new WebhookDelivery(
				[
					'webhook_id'   => $webhook->id,
					'event'        => $event,
					'event_id'     => $event_id,
					'url'          => $webhook->url,
					'request_body' => wp_json_encode( $payload ),
					'status'       => 'pending',
				]
			);

			$delivery_id = $delivery->save();

			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					'missiondp_deliver_webhook',
					[ $delivery_id ],
					'mission-webhook-' . $webhook->id
				);
			} else {
				// Fallback for environments without Action Scheduler (e.g. tests).
				do_action( 'missiondp_deliver_webhook', $delivery_id );
			}
		}
	}

	/**
	 * Handle transaction created: dispatch if already completed.
	 *
	 * @param object $transaction Transaction model.
	 *
	 * @return void
	 */
	public function on_transaction_created( object $transaction ): void {
		if ( 'completed' === $transaction->status ) {
			$this->on_donation_completed( $transaction );
		}
	}

	/**
	 * Handle donation completed.
	 *
	 * @param object $transaction Transaction model.
	 *
	 * @return void
	 */
	public function on_donation_completed( object $transaction ): void {
		$this->dispatch( 'donation.completed', $this->build_transaction_data( $transaction ), (bool) $transaction->is_test );
	}

	/**
	 * Handle donation refunded.
	 *
	 * @param object $transaction Transaction model.
	 *
	 * @return void
	 */
	public function on_donation_refunded( object $transaction ): void {
		$this->dispatch( 'donation.refunded', $this->build_transaction_data( $transaction ), (bool) $transaction->is_test );
	}

	/**
	 * Handle subscription created.
	 *
	 * @param object $subscription Subscription model.
	 *
	 * @return void
	 */
	public function on_subscription_created( object $subscription ): void {
		$this->dispatch( 'subscription.created', $this->build_subscription_data( $subscription ), (bool) $subscription->is_test );
	}

	/**
	 * Handle subscription renewed.
	 *
	 * @param object $subscription Subscription model.
	 * @param object $transaction  The renewal transaction.
	 *
	 * @return void
	 */
	public function on_subscription_renewed( object $subscription, object $transaction ): void {
		$data                        = $this->build_subscription_data( $subscription );
		$data['renewal_transaction'] = $this->build_transaction_data( $transaction );

		$this->dispatch( 'subscription.renewed', $data, (bool) $subscription->is_test );
	}

	/**
	 * Handle subscription cancelled.
	 *
	 * @param object $subscription Subscription model.
	 *
	 * @return void
	 */
	public function on_subscription_cancelled( object $subscription ): void {
		$this->dispatch( 'subscription.cancelled', $this->build_subscription_data( $subscription ), (bool) $subscription->is_test );
	}

	/**
	 * Handle subscription failed.
	 *
	 * @param object $subscription Subscription model.
	 *
	 * @return void
	 */
	public function on_subscription_failed( object $subscription ): void {
		$this->dispatch( 'subscription.failed', $this->build_subscription_data( $subscription ), (bool) $subscription->is_test );
	}

	/**
	 * Handle donor created.
	 *
	 * @param object $donor Donor model.
	 *
	 * @return void
	 */
	public function on_donor_created( object $donor ): void {
		$this->dispatch( 'donor.created', $this->build_donor_data( $donor ) );
	}

	/**
	 * Handle donor updated.
	 *
	 * @param object $donor Donor model.
	 *
	 * @return void
	 */
	public function on_donor_updated( object $donor ): void {
		$this->dispatch( 'donor.updated', $this->build_donor_data( $donor ) );
	}

	/**
	 * Handle campaign created.
	 *
	 * @param object $campaign Campaign model.
	 *
	 * @return void
	 */
	public function on_campaign_created( object $campaign ): void {
		$this->dispatch( 'campaign.created', $this->build_campaign_data( $campaign ) );
	}

	/**
	 * Handle campaign milestone reached: only dispatch for 100% goal.
	 *
	 * @param object $campaign     Campaign model.
	 * @param string $milestone_id Milestone ID (e.g. '100-pct').
	 * @param bool   $is_test      Whether the milestone was reached in test mode.
	 *
	 * @return void
	 */
	public function on_campaign_milestone_reached( object $campaign, string $milestone_id, bool $is_test = false ): void {
		if ( '100-pct' !== $milestone_id ) {
			return;
		}

		$this->dispatch( 'campaign.goal_reached', $this->build_campaign_data( $campaign ), $is_test );
	}

	/**
	 * Build transaction payload data.
	 *
	 * @param object $transaction Transaction model.
	 *
	 * @return array<string, mixed>
	 */
	private function build_transaction_data( object $transaction ): array {
		$donor    = $transaction->donor();
		$campaign = $transaction->campaign();

		return [
			'id'              => $transaction->id,
			'status'          => $transaction->status,
			'type'            => $transaction->type,
			'amount'          => $transaction->amount,
			'total_amount'    => $transaction->total_amount,
			'amount_refunded' => $transaction->amount_refunded,
			'currency'        => $transaction->currency,
			'is_test'         => $transaction->is_test,
			'date_created'    => $transaction->date_created,
			'date_completed'  => $transaction->date_completed,
			'donor'           => $donor ? [
				'id'         => $donor->id,
				'email'      => $donor->email,
				'first_name' => $donor->first_name,
				'last_name'  => $donor->last_name,
			] : null,
			'campaign'        => $campaign ? [
				'id'    => $campaign->id,
				'title' => $campaign->title,
			] : null,
		];
	}

	/**
	 * Build subscription payload data.
	 *
	 * @param object $subscription Subscription model.
	 *
	 * @return array<string, mixed>
	 */
	private function build_subscription_data( object $subscription ): array {
		$donor    = $subscription->donor();
		$campaign = $subscription->campaign();

		return [
			'id'                => $subscription->id,
			'status'            => $subscription->status,
			'amount'            => $subscription->amount,
			'total_amount'      => $subscription->total_amount,
			'currency'          => $subscription->currency,
			'frequency'         => $subscription->frequency,
			'renewal_count'     => $subscription->renewal_count,
			'is_test'           => $subscription->is_test,
			'date_created'      => $subscription->date_created,
			'date_next_renewal' => $subscription->date_next_renewal,
			'donor'             => $donor ? [
				'id'         => $donor->id,
				'email'      => $donor->email,
				'first_name' => $donor->first_name,
				'last_name'  => $donor->last_name,
			] : null,
			'campaign'          => $campaign ? [
				'id'    => $campaign->id,
				'title' => $campaign->title,
			] : null,
		];
	}

	/**
	 * Build donor payload data.
	 *
	 * @param object $donor Donor model.
	 *
	 * @return array<string, mixed>
	 */
	private function build_donor_data( object $donor ): array {
		return [
			'id'           => $donor->id,
			'email'        => $donor->email,
			'first_name'   => $donor->first_name,
			'last_name'    => $donor->last_name,
			'date_created' => $donor->date_created,
		];
	}

	/**
	 * Build campaign payload data.
	 *
	 * @param object $campaign Campaign model.
	 *
	 * @return array<string, mixed>
	 */
	private function build_campaign_data( object $campaign ): array {
		return [
			'id'           => $campaign->id,
			'title'        => $campaign->title,
			'goal_amount'  => $campaign->goal_amount,
			'total_raised' => $campaign->total_raised,
			'status'       => $campaign->status,
			'date_created' => $campaign->date_created,
		];
	}
}
