<?php
/**
 * Subscription model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use DateTime;
use DateTimeZone;
use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\SubscriptionDataStore;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Subscription model with relationship access.
 */
class Subscription extends Model {

	use HasMeta;

	public string $status;
	public int $donor_id;
	public int $source_post_id;
	public ?int $campaign_id;
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
		$this->status                  = $data['status'] ?? 'pending';
		$this->donor_id                = (int) ( $data['donor_id'] ?? 0 );
		$this->source_post_id          = (int) ( $data['source_post_id'] ?? 0 );
		$this->campaign_id             = isset( $data['campaign_id'] ) ? (int) $data['campaign_id'] : null;
		$this->initial_transaction_id  = isset( $data['initial_transaction_id'] ) ? (int) $data['initial_transaction_id'] : null;
		$this->amount                  = (int) ( $data['amount'] ?? 0 );
		$this->fee_amount              = (int) ( $data['fee_amount'] ?? 0 );
		$this->tip_amount              = (int) ( $data['tip_amount'] ?? 0 );
		$this->total_amount            = (int) ( $data['total_amount'] ?? 0 );
		$this->currency                = $data['currency'] ?? 'usd';
		$this->frequency               = $data['frequency'] ?? 'monthly';
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
		$this->status                 = 'active';
		$this->initial_transaction_id = $initial_transaction_id;
		$this->date_next_renewal      = $this->calculate_next_renewal_date( current_time( 'mysql', true ) );
		$this->save();
	}

	/**
	 * Cancel the subscription locally and on Stripe via the Mission API.
	 *
	 * @return bool True if cancellation succeeded.
	 */
	public function cancel(): bool {
		if ( 'cancelled' === $this->status ) {
			return true;
		}

		if ( ! $this->call_mission_api( 'cancel-subscription' ) ) {
			return false;
		}

		$this->status         = 'cancelled';
		$this->date_cancelled = current_time( 'mysql', true );
		$this->save();

		return true;
	}

	/**
	 * Pause the subscription locally and on Stripe via the Mission API.
	 *
	 * Only active subscriptions can be paused.
	 *
	 * @return bool True if pausing succeeded.
	 */
	public function pause(): bool {
		if ( 'paused' === $this->status ) {
			return true;
		}

		if ( 'active' !== $this->status ) {
			return false;
		}

		if ( ! $this->call_mission_api( 'pause-subscription' ) ) {
			return false;
		}

		$this->status = 'paused';
		$this->save();

		return true;
	}

	/**
	 * Resume a paused subscription locally and on Stripe via the Mission API.
	 *
	 * Only paused subscriptions can be resumed.
	 *
	 * @return bool True if resuming succeeded.
	 */
	public function resume(): bool {
		if ( 'active' === $this->status ) {
			return true;
		}

		if ( 'paused' !== $this->status ) {
			return false;
		}

		if ( ! $this->call_mission_api( 'resume-subscription' ) ) {
			return false;
		}

		$this->status            = 'active';
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
	 * @return bool True if the update succeeded.
	 */
	public function update_amount( int $donation_amount, int $tip_amount, int $fee_amount = 0 ): bool {
		if ( ! in_array( $this->status, [ 'active', 'paused' ], true ) ) {
			return false;
		}

		$old_amount = $this->amount;

		$success = $this->call_mission_api(
			'update-subscription-amount',
			[
				'donation_amount' => $donation_amount,
				'tip_amount'      => $tip_amount,
			]
		);

		if ( ! $success ) {
			return false;
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
			do_action( 'missiondp_subscription_amount_changed', $this, $old_amount, $donation_amount );
		}

		return true;
	}

	/**
	 * Create a Stripe SetupIntent for updating the payment method.
	 *
	 * Calls the Mission API to create a SetupIntent on the connected account.
	 *
	 * @return array{client_secret: string, connected_account_id: string}|false
	 */
	public function create_setup_intent(): array|false {
		if ( ! $this->gateway_customer_id ) {
			return false;
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
	 * @return array{brand: string, last4: string, exp_month: int, exp_year: int}|false
	 */
	public function update_payment_method( string $payment_method_id ): array|false {
		if ( ! in_array( $this->status, [ 'active', 'paused' ], true ) ) {
			return false;
		}

		if ( ! $this->gateway_subscription_id ) {
			return false;
		}

		$data = $this->call_mission_api_with_response(
			'update-subscription-payment-method',
			[
				'subscription_id'   => $this->gateway_subscription_id,
				'payment_method_id' => $payment_method_id,
				'test_mode'         => $this->is_test,
			]
		);

		if ( ! $data || empty( $data['card'] ) ) {
			return false;
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
	 * @return array<string,mixed>|false Decoded response on success, false on failure.
	 */
	private function call_mission_api_with_response( string $endpoint, array $body ): array|false {
		$settings   = new SettingsService();
		$site_token = $settings->get( 'stripe_site_token' );

		if ( ! $site_token ) {
			error_log( "[Mission] API call to {$endpoint} failed: no site token configured." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
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
			error_log( "[Mission] API call to {$endpoint} failed: " . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			error_log( "[Mission] API call to {$endpoint} returned HTTP {$status_code}: " . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : false;
	}

	/**
	 * Call a Mission API endpoint for this subscription.
	 *
	 * @param string              $endpoint   The API endpoint path (e.g. 'cancel-subscription').
	 * @param array<string,mixed> $extra_data Additional body parameters.
	 * @return bool True if the API call succeeded or was skipped.
	 */
	private function call_mission_api( string $endpoint, array $extra_data = [] ): bool {
		if ( ! $this->gateway_subscription_id ) {
			return true;
		}

		$settings   = new SettingsService();
		$site_token = $settings->get( 'stripe_site_token' );

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
			error_log( "[Mission] API call to {$endpoint} failed: " . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			error_log( "[Mission] API call to {$endpoint} returned HTTP {$status_code}: " . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		return true;
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
					'status'          => 'completed',
					'type'            => $this->frequency,
					'donor_id'        => $this->donor_id,
					'subscription_id' => $this->id,
					'source_post_id'  => $this->source_post_id,
					'campaign_id'     => $this->campaign_id,
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

		// Copy platform fee mode from subscription to renewal transaction.
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
		do_action( 'missiondp_subscription_renewed', $this, $transaction );

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
			'weekly'    => $date->modify( '+1 week' ),
			'monthly'   => $date->modify( '+1 month' ),
			'quarterly' => $date->modify( '+3 months' ),
			'annually'  => $date->modify( '+1 year' ),
			default     => $date->modify( '+1 month' ),
		};

		return $date->format( 'Y-m-d H:i:s' );
	}
}
