<?php
/**
 * Subscription model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use DateTime;
use DateTimeZone;
use MissionDP\Constants\Frequency;
use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\SubscriptionDataStore;
use MissionDP\Settings\SettingsService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Subscription model with relationship access.
 */
class Subscription extends Model {

	use HasMeta;

	public const STATUS_PENDING   = 'pending';
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_PAST_DUE  = 'past_due';
	public const STATUS_PAUSED    = 'paused';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Every subscription status.
	 *
	 * @var string[]
	 */
	public const STATUSES = [
		self::STATUS_PENDING,
		self::STATUS_ACTIVE,
		self::STATUS_PAST_DUE,
		self::STATUS_PAUSED,
		self::STATUS_CANCELLED,
	];

	public string $status;
	public int $donor_id;
	public int $source_post_id;
	public ?int $campaign_id;
	public ?int $fundraiser_id;
	public ?int $team_id;
	public ?int $initial_transaction_id;
	public int $amount;
	public int $fee_amount;
	public int $tip_amount;
	public int $total_amount;
	public string $currency;
	public string $frequency;
	public string $payment_gateway;
	public ?string $gateway_subscription_id;
	public ?string $gateway_customer_id;
	public int $renewal_count;
	public int $total_renewed;
	public bool $is_test;
	public string $date_created;
	public ?string $date_next_renewal;
	public ?string $date_cancelled;
	public string $date_modified;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id                      = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->status                  = $data['status'] ?? self::STATUS_PENDING;
		$this->donor_id                = (int) ( $data['donor_id'] ?? 0 );
		$this->source_post_id          = (int) ( $data['source_post_id'] ?? 0 );
		$this->campaign_id             = isset( $data['campaign_id'] ) ? (int) $data['campaign_id'] : null;
		$this->fundraiser_id           = isset( $data['fundraiser_id'] ) ? (int) $data['fundraiser_id'] : null;
		$this->team_id                 = isset( $data['team_id'] ) ? (int) $data['team_id'] : null;
		$this->initial_transaction_id  = isset( $data['initial_transaction_id'] ) ? (int) $data['initial_transaction_id'] : null;
		$this->amount                  = (int) ( $data['amount'] ?? 0 );
		$this->fee_amount              = (int) ( $data['fee_amount'] ?? 0 );
		$this->tip_amount              = (int) ( $data['tip_amount'] ?? 0 );
		$this->total_amount            = (int) ( $data['total_amount'] ?? 0 );
		$this->currency                = $data['currency'] ?? 'usd';
		$this->frequency               = $data['frequency'] ?? Frequency::MONTHLY;
		$this->payment_gateway         = $data['payment_gateway'] ?? '';
		$this->gateway_subscription_id = $data['gateway_subscription_id'] ?? null;
		$this->gateway_customer_id     = $data['gateway_customer_id'] ?? null;
		$this->renewal_count           = (int) ( $data['renewal_count'] ?? 0 );
		$this->total_renewed           = (int) ( $data['total_renewed'] ?? 0 );
		$this->is_test                 = (bool) ( $data['is_test'] ?? false );
		$this->date_created            = $data['date_created'] ?? current_time( 'mysql', true );
		$this->date_next_renewal       = $data['date_next_renewal'] ?? null;
		$this->date_cancelled          = $data['date_cancelled'] ?? null;
		$this->date_modified           = $data['date_modified'] ?? current_time( 'mysql', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new SubscriptionDataStore();
	}

	/**
	 * Save without firing side-effect hooks (activity feed, status emails).
	 *
	 * For bulk backfill paths (import, migration) that must not trigger
	 * listeners for historical rows.
	 *
	 * @return int|bool New ID on insert, true on update, false on failure.
	 */
	public function save_silent(): int|bool {
		/** @var SubscriptionDataStore $store */
		$store = static::store();

		if ( $this->id ) {
			return $store->update_silent( $this );
		}

		$this->id = $store->create_silent( $this );

		return $this->id;
	}

	/**
	 * Find a subscription by its gateway subscription ID.
	 *
	 * @param string $gateway_subscription_id Gateway subscription identifier.
	 *
	 * @return Subscription|null
	 */
	public static function find_by_gateway_subscription_id( string $gateway_subscription_id ): ?self {
		if ( '' === trim( $gateway_subscription_id ) ) {
			return null;
		}

		/** @var SubscriptionDataStore $store */
		$store = static::store();
		return $store->read_by_gateway_subscription_id( $gateway_subscription_id );
	}

	/**
	 * Map gateway subscription IDs to subscription IDs in one query.
	 *
	 * Empty values never match.
	 *
	 * @param string[] $gateway_ids Gateway subscription identifiers.
	 * @return array<string, int> Map of gateway_subscription_id => subscription ID.
	 */
	public static function find_ids_by_gateway_subscription_ids( array $gateway_ids ): array {
		/** @var SubscriptionDataStore $store */
		$store = static::store();
		return $store->read_ids_by_gateway_subscription_ids( $gateway_ids );
	}

	/**
	 * Get the donor for this subscription.
	 *
	 * @return Donor|null
	 */
	public function donor(): ?Donor {
		return $this->donor_id ? Donor::find( $this->donor_id ) : null;
	}

	/**
	 * Get the campaign for this subscription.
	 *
	 * @return Campaign|null
	 */
	public function campaign(): ?Campaign {
		return $this->campaign_id ? Campaign::find( $this->campaign_id ) : null;
	}

	/**
	 * Get the fundraiser this subscription is attributed to.
	 *
	 * @return Fundraiser|null
	 */
	public function fundraiser(): ?Fundraiser {
		return $this->fundraiser_id ? Fundraiser::find( $this->fundraiser_id ) : null;
	}

	/**
	 * Get the team this subscription is attributed to.
	 *
	 * @return Team|null
	 */
	public function team(): ?Team {
		return $this->team_id ? Team::find( $this->team_id ) : null;
	}

	/**
	 * Get the notes for this subscription.
	 *
	 * @param array<string, mixed> $args Additional query args.
	 * @return Note[]
	 */
	public function notes( array $args = [] ): array {
		return Note::query(
			array_merge(
				$args,
				[
					'object_type' => 'subscription',
					'object_id'   => $this->id,
				]
			)
		);
	}

	/**
	 * Get the transactions for this subscription.
	 *
	 * @param array<string, mixed> $args Additional query args.
	 * @return Transaction[]
	 */
	public function transactions( array $args = [] ): array {
		return Transaction::query( array_merge( $args, [ 'subscription_id' => $this->id ] ) );
	}

	/**
	 * Activate the subscription after the initial payment succeeds.
	 *
	 * Sets status to active, links the initial transaction, calculates the
	 * next renewal date, and persists.
	 *
	 * @param int $initial_transaction_id The ID of the first completed transaction.
	 * @return void
	 */
	public function activate( int $initial_transaction_id ): void {
		$this->status                 = self::STATUS_ACTIVE;
		$this->initial_transaction_id = $initial_transaction_id;
		$this->date_next_renewal      = $this->calculate_next_renewal_date( current_time( 'mysql', true ) );
		$this->save();
	}

	/**
	 * Cancel the subscription locally and on Stripe via the Mission API.
	 *
	 * Idempotent: cancelling an already-cancelled subscription succeeds.
	 *
	 * @return true|WP_Error True on success, WP_Error if the Mission API call failed.
	 */
	public function cancel(): bool|WP_Error {
		if ( self::STATUS_CANCELLED === $this->status ) {
			return true;
		}

		$result = $this->call_mission_api( 'cancel-subscription' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->status         = self::STATUS_CANCELLED;
		$this->date_cancelled = current_time( 'mysql', true );
		$this->save();

		return true;
	}

	/**
	 * Pause the subscription locally and on Stripe via the Mission API.
	 *
	 * Only active subscriptions can be paused. Idempotent if already paused.
	 *
	 * @return true|WP_Error True on success, WP_Error on invalid state or API failure.
	 */
	public function pause(): bool|WP_Error {
		if ( self::STATUS_PAUSED === $this->status ) {
			return true;
		}

		if ( self::STATUS_ACTIVE !== $this->status ) {
			return $this->state_error( 'subscription_not_pausable' );
		}

		$result = $this->call_mission_api( 'pause-subscription' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->status = self::STATUS_PAUSED;
		$this->save();

		return true;
	}

	/**
	 * Resume a paused subscription locally and on Stripe via the Mission API.
	 *
	 * Only paused subscriptions can be resumed. Idempotent if already active.
	 *
	 * @return true|WP_Error True on success, WP_Error on invalid state or API failure.
	 */
	public function resume(): bool|WP_Error {
		if ( self::STATUS_ACTIVE === $this->status ) {
			return true;
		}

		if ( self::STATUS_PAUSED !== $this->status ) {
			return $this->state_error( 'subscription_not_resumable' );
		}

		$result = $this->call_mission_api( 'resume-subscription' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->status            = self::STATUS_ACTIVE;
		$this->date_next_renewal = $this->calculate_next_renewal_date( current_time( 'mysql', true ) );
		$this->save();

		return true;
	}

	/**
	 * Update the subscription amount locally and on Stripe via the Mission API.
	 *
	 * Only active or paused subscriptions can change amount.
	 *
	 * @param int $donation_amount New donation amount in minor units.
	 * @param int $tip_amount      New tip amount in minor units.
	 * @return true|WP_Error True on success, WP_Error on invalid state or API failure.
	 */
	public function update_amount( int $donation_amount, int $tip_amount, int $fee_amount = 0 ): bool|WP_Error {
		if ( ! in_array( $this->status, [ self::STATUS_ACTIVE, self::STATUS_PAUSED ], true ) ) {
			return $this->state_error( 'subscription_not_updatable' );
		}

		$old_amount = $this->amount;

		$result = $this->call_mission_api(
			'update-subscription-amount',
			[
				'donation_amount' => $donation_amount,
				'tip_amount'      => $tip_amount,
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->amount       = $donation_amount;
		$this->tip_amount   = $tip_amount;
		$this->fee_amount   = $fee_amount;
		$this->total_amount = $donation_amount + $tip_amount;
		$this->save();

		if ( $old_amount !== $donation_amount ) {
			/**
			 * Fires when a subscription's donation amount changes.
			 *
			 * @param Subscription $subscription   The subscription.
			 * @param int          $old_amount     Previous donation amount in minor units.
			 * @param int          $new_amount     New donation amount in minor units.
			 */
			do_action( 'mission_subscription_amount_changed', $this, $old_amount, $donation_amount );
		}

		return true;
	}

	/**
	 * Create a Stripe SetupIntent for updating the payment method.
	 *
	 * Calls the Mission API to create a SetupIntent on the connected account.
	 *
	 * @return array{client_secret: string, connected_account_id: string}|WP_Error
	 */
	public function create_setup_intent(): array|WP_Error {
		if ( ! in_array( $this->status, [ self::STATUS_ACTIVE, self::STATUS_PAUSED ], true ) ) {
			return $this->state_error( 'subscription_not_updatable' );
		}

		if ( ! $this->gateway_customer_id ) {
			return $this->state_error( 'subscription_missing_gateway_data' );
		}

		return $this->call_mission_api_with_response(
			'create-setup-intent',
			[
				'customer_id' => $this->gateway_customer_id,
				'test_mode'   => $this->is_test,
			]
		);
	}

	/**
	 * Update the subscription's payment method on Stripe and save card details locally.
	 *
	 * @param string $payment_method_id Stripe PaymentMethod ID from confirmSetup.
	 * @return array{brand: string, last4: string, exp_month: int, exp_year: int}|WP_Error
	 */
	public function update_payment_method( string $payment_method_id ): array|WP_Error {
		if ( ! in_array( $this->status, [ self::STATUS_ACTIVE, self::STATUS_PAUSED ], true ) ) {
			return $this->state_error( 'subscription_not_updatable' );
		}

		if ( ! $this->gateway_subscription_id ) {
			return $this->state_error( 'subscription_missing_gateway_data' );
		}

		$data = $this->call_mission_api_with_response(
			'update-subscription-payment-method',
			[
				'subscription_id'   => $this->gateway_subscription_id,
				'payment_method_id' => $payment_method_id,
				'test_mode'         => $this->is_test,
			]
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['card'] ) ) {
			return $this->api_error( 'mission_api_invalid_response', 'update-subscription-payment-method' );
		}

		$card = $data['card'];

		if ( ! empty( $card['brand'] ) ) {
			$this->update_meta( 'payment_method_brand', $card['brand'] );
		}
		if ( ! empty( $card['last4'] ) ) {
			$this->update_meta( 'payment_method_last4', $card['last4'] );
		}
		if ( isset( $card['exp_month'] ) ) {
			$this->update_meta( 'payment_method_exp_month', $card['exp_month'] );
		}
		if ( isset( $card['exp_year'] ) ) {
			$this->update_meta( 'payment_method_exp_year', $card['exp_year'] );
		}

		return $card;
	}

	/**
	 * Call a Mission API endpoint and return the decoded response body.
	 *
	 * Unlike call_mission_api() which returns bool, this returns the parsed
	 * response for endpoints where the caller needs the response data.
	 *
	 * @param string              $endpoint The API endpoint path.
	 * @param array<string,mixed> $body     Request body parameters.
	 * @return array<string,mixed>|WP_Error Decoded response on success, WP_Error on failure.
	 */
	private function call_mission_api_with_response( string $endpoint, array $body ): array|WP_Error {
		$settings   = new SettingsService();
		$site_token = $settings->resolve_site_token( (string) $this->get_meta( 'stripe_account_id' ) );

		if ( ! $site_token ) {
			$this->log_api_failure( $endpoint, 'no_site_token' );
			return $this->api_error( 'mission_api_unreachable', $endpoint );
		}

		$response = wp_remote_post(
			'https://api.missionwp.com/' . $endpoint,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $site_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->log_api_failure( $endpoint, 'wp_error', [ 'error' => $response->get_error_message() ] );
			return $this->api_error( 'mission_api_unreachable', $endpoint );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			$this->log_api_failure(
				$endpoint,
				'http_error',
				[
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				]
			);
			return $this->api_error( 'mission_api_error', $endpoint, [ 'upstream_status' => $status_code ] );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return $this->api_error( 'mission_api_invalid_response', $endpoint );
		}

		return $data;
	}

	/**
	 * Call a Mission API endpoint for this subscription.
	 *
	 * @param string              $endpoint   The API endpoint path (e.g. 'cancel-subscription').
	 * @param array<string,mixed> $extra_data Additional body parameters.
	 * @return true|WP_Error True if the API call succeeded or was skipped, WP_Error on failure.
	 */
	private function call_mission_api( string $endpoint, array $extra_data = [] ): bool|WP_Error {
		if ( ! $this->gateway_subscription_id ) {
			return true;
		}

		$settings   = new SettingsService();
		$site_token = $settings->resolve_site_token( (string) $this->get_meta( 'stripe_account_id' ) );

		if ( ! $site_token ) {
			return true;
		}

		$body = array_merge(
			[
				'subscription_id' => $this->gateway_subscription_id,
				'test_mode'       => $this->is_test,
			],
			$extra_data
		);

		$response = wp_remote_post(
			'https://api.missionwp.com/' . $endpoint,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $site_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->log_api_failure( $endpoint, 'wp_error', [ 'error' => $response->get_error_message() ] );
			return $this->api_error( 'mission_api_unreachable', $endpoint );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			$this->log_api_failure(
				$endpoint,
				'http_error',
				[
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				]
			);
			return $this->api_error( 'mission_api_error', $endpoint, [ 'upstream_status' => $status_code ] );
		}

		return true;
	}

	/**
	 * Build the WP_Error for a lifecycle action attempted from the wrong state.
	 *
	 * State errors map to a 400: the caller asked for a transition this
	 * subscription cannot make.
	 *
	 * @param string $code One of subscription_not_pausable, subscription_not_resumable, subscription_not_updatable, subscription_missing_gateway_data.
	 * @return WP_Error
	 */
	private function state_error( string $code ): WP_Error {
		$messages = [
			'subscription_not_pausable'         => __( 'Only active subscriptions can be paused.', 'mission-donation-platform' ),
			'subscription_not_resumable'        => __( 'Only paused subscriptions can be resumed.', 'mission-donation-platform' ),
			'subscription_not_updatable'        => __( 'Only active or paused subscriptions can be updated.', 'mission-donation-platform' ),
			'subscription_missing_gateway_data' => __( 'This subscription has no saved payment method to update.', 'mission-donation-platform' ),
		];

		return new WP_Error( $code, $messages[ $code ], [ 'status' => 400 ] );
	}

	/**
	 * Build the WP_Error for a failed Mission API call.
	 *
	 * All Mission API failures map to a 502: the upstream service, not the
	 * caller's request, is at fault.
	 *
	 * @param string              $code     One of mission_api_unreachable, mission_api_error, mission_api_invalid_response.
	 * @param string              $endpoint The API endpoint that failed.
	 * @param array<string,mixed> $extra    Additional error data (e.g. upstream_status).
	 * @return WP_Error
	 */
	private function api_error( string $code, string $endpoint, array $extra = [] ): WP_Error {
		$messages = [
			'mission_api_unreachable'      => __( 'Could not reach the payment service. Please try again.', 'mission-donation-platform' ),
			'mission_api_error'            => __( 'The payment service returned an error. Please try again.', 'mission-donation-platform' ),
			'mission_api_invalid_response' => __( 'The payment service returned an unexpected response.', 'mission-donation-platform' ),
		];

		return new WP_Error(
			$code,
			$messages[ $code ],
			array_merge(
				[
					'status'   => 502,
					'endpoint' => $endpoint,
				],
				$extra
			)
		);
	}

	/**
	 * Announce a Mission API call failure so listeners (activity feed) can record it.
	 *
	 * @param string              $endpoint The API endpoint that failed.
	 * @param string              $reason   Short reason code (no_site_token, wp_error, http_error).
	 * @param array<string,mixed> $extra    Additional context (error message, status, body).
	 */
	private function log_api_failure( string $endpoint, string $reason, array $extra = [] ): void {
		/**
		 * Fires when a Mission API call made for a subscription fails.
		 *
		 * @param Subscription         $subscription The subscription.
		 * @param string               $endpoint     The API endpoint that failed.
		 * @param string               $reason       Short reason code (no_site_token, wp_error, http_error).
		 * @param array<string,mixed>  $extra        Additional context (error message, status, body).
		 */
		do_action( 'mission_subscription_api_call_failed', $this, $endpoint, $reason, $extra );
	}

	/**
	 * Record a renewal payment from a webhook event.
	 *
	 * Creates a new completed Transaction, increments renewal_count and
	 * total_renewed, and advances date_next_renewal.
	 *
	 * @param array<string, mixed> $transaction_data Additional transaction data
	 *                                               (gateway_transaction_id, etc.).
	 * @return Transaction The newly created transaction.
	 */
	public function record_renewal( array $transaction_data = [] ): Transaction {
		$transaction = new Transaction(
			array_merge(
				[
					'status'          => Transaction::STATUS_COMPLETED,
					'type'            => $this->frequency,
					'donor_id'        => $this->donor_id,
					'subscription_id' => $this->id,
					'source_post_id'  => $this->source_post_id,
					'campaign_id'     => $this->campaign_id,
					'fundraiser_id'   => $this->fundraiser_id,
					'team_id'         => $this->team_id,
					'amount'          => $this->amount,
					'fee_amount'      => $this->fee_amount,
					'tip_amount'      => $this->tip_amount,
					'total_amount'    => $this->total_amount,
					'currency'        => $this->currency,
					'payment_gateway' => $this->payment_gateway,
					'is_test'         => $this->is_test,
					'date_completed'  => current_time( 'mysql', true ),
				],
				$transaction_data,
			)
		);

		$transaction->save();

		$fee_mode = $this->get_meta( 'fee_mode' );
		if ( $fee_mode ) {
			$transaction->add_meta( 'fee_mode', $fee_mode );
		}

		$this->renewal_count    += 1;
		$this->total_renewed    += $this->total_amount;
		$this->date_next_renewal = $this->calculate_next_renewal_date();
		$this->save();

		/**
		 * Fires after a subscription renewal is recorded.
		 *
		 * @param Subscription $subscription The subscription.
		 * @param Transaction  $transaction  The renewal transaction.
		 */
		do_action( 'mission_subscription_renewed', $this, $transaction );

		return $transaction;
	}

	/**
	 * Calculate the next renewal date based on frequency.
	 *
	 * @param string|null $from MySQL datetime to calculate from. Defaults to
	 *                         the current date_next_renewal or now.
	 * @return string MySQL datetime string (UTC).
	 */
	public function calculate_next_renewal_date( ?string $from = null ): string {
		$base = $from ?? $this->date_next_renewal ?? current_time( 'mysql', true );
		$date = new DateTime( $base, new DateTimeZone( 'UTC' ) );

		match ( $this->frequency ) {
			Frequency::WEEKLY    => $date->modify( '+1 week' ),
			Frequency::MONTHLY   => $date->modify( '+1 month' ),
			Frequency::QUARTERLY => $date->modify( '+3 months' ),
			Frequency::ANNUALLY  => $date->modify( '+1 year' ),
			default              => $date->modify( '+1 month' ),
		};

		return $date->format( 'Y-m-d H:i:s' );
	}
}
