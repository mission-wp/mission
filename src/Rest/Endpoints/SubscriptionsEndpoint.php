<?php
/**
 * REST endpoint for subscriptions (admin).
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Models\Subscription;
use Mission\Models\Transaction;
use Mission\Reporting\ReportingService;
use Mission\Rest\RestModule;
use Mission\Settings\SettingsService;
use Mission\Tip\TipCalculator;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Subscriptions endpoint class.
 */
class SubscriptionsEndpoint {

	/**
	 * Constructor.
	 *
	 * @param ReportingService $reporting Reporting service.
	 * @param SettingsService  $settings  Settings service.
	 */
	public function __construct(
		private readonly ReportingService $reporting,
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
			'/subscriptions',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_subscriptions' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->get_collection_params(),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/subscriptions/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_subscription' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/subscriptions/(?P<id>\d+)/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel_subscription' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/subscriptions/(?P<id>\d+)/pause',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'pause_subscription' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/subscriptions/(?P<id>\d+)/resume',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'resume_subscription' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/subscriptions/(?P<id>\d+)',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_subscription' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id'          => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'status'      => [
						'type'              => 'string',
						'enum'              => [ 'active', 'pending', 'past_due', 'paused', 'cancelled' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'campaign_id' => [
						'type' => [ 'integer', 'null' ],
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/subscriptions/summary',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_summary' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
	}

	/**
	 * Permission check — requires manage_options.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage subscriptions.', 'mission' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * GET handler — returns paginated subscriptions list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_subscriptions( WP_REST_Request $request ): WP_REST_Response {
		$per_page = $request->get_param( 'per_page' ) ?? 25;

		$result = $this->reporting->subscriptions_with_donors(
			[
				'per_page'    => $per_page,
				'page'        => $request->get_param( 'page' ) ?? 1,
				'orderby'     => $request->get_param( 'orderby' ) ?? 'date_created',
				'order'       => $request->get_param( 'order' ) ?? 'DESC',
				'status'      => $request->get_param( 'status' ),
				'campaign_id' => $request->get_param( 'campaign_id' ),
				'donor_id'    => $request->get_param( 'donor_id' ),
				'search'      => $request->get_param( 'search' ),
			]
		);

		$total_pages = $per_page > 0 ? (int) ceil( $result['total'] / $per_page ) : 0;

		$response = new WP_REST_Response( $result['items'], 200 );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * GET single subscription with detailed info.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_subscription( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subscription = Subscription::find( $request->get_param( 'id' ) );

		if ( ! $subscription ) {
			return new WP_Error(
				'subscription_not_found',
				__( 'Subscription not found.', 'mission' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $this->prepare_subscription_detail( $subscription ), 200 );
	}

	/**
	 * Cancel a subscription.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_subscription( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subscription = Subscription::find( $request->get_param( 'id' ) );

		if ( ! $subscription ) {
			return new WP_Error(
				'subscription_not_found',
				__( 'Subscription not found.', 'mission' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! in_array( $subscription->status, [ 'active', 'past_due', 'pending' ], true ) ) {
			return new WP_Error(
				'subscription_not_cancellable',
				__( 'This subscription cannot be cancelled.', 'mission' ),
				[ 'status' => 400 ]
			);
		}

		$cancelled = $subscription->cancel();

		if ( ! $cancelled ) {
			return new WP_Error(
				'cancellation_failed',
				__( 'Failed to cancel subscription on Stripe.', 'mission' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'id'      => $subscription->id,
				'status'  => $subscription->status,
			],
			200
		);
	}

	/**
	 * Pause a subscription.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pause_subscription( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subscription = Subscription::find( $request->get_param( 'id' ) );

		if ( ! $subscription ) {
			return new WP_Error(
				'subscription_not_found',
				__( 'Subscription not found.', 'mission' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'active' !== $subscription->status ) {
			return new WP_Error(
				'subscription_not_pausable',
				__( 'Only active subscriptions can be paused.', 'mission' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $subscription->pause() ) {
			return new WP_Error(
				'pause_failed',
				__( 'Failed to pause subscription on Stripe.', 'mission' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'id'      => $subscription->id,
				'status'  => $subscription->status,
			],
			200
		);
	}

	/**
	 * Resume a paused subscription.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resume_subscription( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subscription = Subscription::find( $request->get_param( 'id' ) );

		if ( ! $subscription ) {
			return new WP_Error(
				'subscription_not_found',
				__( 'Subscription not found.', 'mission' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'paused' !== $subscription->status ) {
			return new WP_Error(
				'subscription_not_resumable',
				__( 'Only paused subscriptions can be resumed.', 'mission' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $subscription->resume() ) {
			return new WP_Error(
				'resume_failed',
				__( 'Failed to resume subscription on Stripe.', 'mission' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'id'      => $subscription->id,
				'status'  => $subscription->status,
			],
			200
		);
	}

	/**
	 * PATCH handler — update a subscription.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_subscription( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subscription = Subscription::find( $request->get_param( 'id' ) );

		if ( ! $subscription ) {
			return new WP_Error(
				'subscription_not_found',
				__( 'Subscription not found.', 'mission' ),
				[ 'status' => 404 ]
			);
		}

		if ( $request->has_param( 'status' ) ) {
			$subscription->status = $request->get_param( 'status' );
		}

		if ( $request->has_param( 'campaign_id' ) ) {
			$campaign_id               = $request->get_param( 'campaign_id' );
			$subscription->campaign_id = $campaign_id ? (int) $campaign_id : null;
		}

		$subscription->save();

		return new WP_REST_Response( $this->prepare_subscription_detail( $subscription ), 200 );
	}

	/**
	 * GET subscription summary stats.
	 *
	 * @return WP_REST_Response
	 */
	public function get_summary(): WP_REST_Response {
		return new WP_REST_Response( $this->reporting->subscription_summary(), 200 );
	}

	/**
	 * Prepare a subscription for the detail response.
	 *
	 * @param Subscription $subscription Subscription model.
	 * @return array<string, mixed>
	 */
	private function prepare_subscription_detail( Subscription $subscription ): array {
		$donor    = $subscription->donor();
		$campaign = $subscription->campaign();
		$is_test  = (bool) $this->settings->get( 'test_mode' );

		$transactions = $subscription->transactions(
			[
				'per_page' => 100,
				'orderby'  => 'date_created',
				'order'    => 'DESC',
			]
		);

		$prepared_transactions = array_map(
			fn( $txn ) => [
				'id'             => $txn->id,
				'status'         => $txn->status,
				'amount'         => $txn->amount,
				'fee_amount'     => $txn->fee_amount,
				'tip_amount'     => $txn->tip_amount,
				'total_amount'   => $txn->total_amount,
				'processing_fee' => $this->calculate_processing_fee( $txn ),
				'fee_recovered'  => $txn->fee_amount,
				'adjusted_tip'   => TipCalculator::adjusted_tip( $txn ),
				'currency'       => $txn->currency,
				'date_created'   => $txn->date_created,
				'date_completed' => $txn->date_completed,
			],
			$transactions,
		);

		return [
			'id'                      => $subscription->id,
			'status'                  => $subscription->status,
			'donor_id'                => $subscription->donor_id,
			'source_post_id'          => $subscription->source_post_id,
			'source_title'            => $subscription->source_post_id ? get_the_title( $subscription->source_post_id ) : '',
			'source_url'              => $subscription->source_post_id ? get_permalink( $subscription->source_post_id ) : '',
			'campaign_id'             => $subscription->campaign_id,
			'initial_transaction_id'  => $subscription->initial_transaction_id,
			'amount'                  => $subscription->amount,
			'fee_amount'              => $subscription->fee_amount,
			'tip_amount'              => $subscription->tip_amount,
			'total_amount'            => $subscription->total_amount,
			'currency'                => $subscription->currency,
			'frequency'               => $subscription->frequency,
			'payment_gateway'         => $subscription->payment_gateway,
			'payment_method_brand'    => $subscription->get_meta( 'payment_method_brand' ) ?: '',
			'payment_method_last4'    => $subscription->get_meta( 'payment_method_last4' ) ?: '',
			'gateway_subscription_id' => $subscription->gateway_subscription_id,
			'gateway_customer_id'     => $subscription->gateway_customer_id,
			'renewal_count'           => $subscription->renewal_count,
			'total_renewed'           => $subscription->total_renewed,
			'is_test'                 => $subscription->is_test,
			'date_created'            => $subscription->date_created,
			'date_next_renewal'       => $subscription->date_next_renewal,
			'date_cancelled'          => $subscription->date_cancelled,
			'date_modified'           => $subscription->date_modified,
			'donor'                   => $donor ? [
				'id'                => $donor->id,
				'email'             => $donor->email,
				'first_name'        => $donor->first_name,
				'last_name'         => $donor->last_name,
				'gravatar_hash'     => md5( strtolower( trim( $donor->email ) ) ),
				'total_donated'     => $is_test ? $donor->test_total_donated : $donor->total_donated,
				'transaction_count' => $is_test ? $donor->test_transaction_count : $donor->transaction_count,
				'address_1'         => $donor->address_1,
				'address_2'         => $donor->address_2,
				'city'              => $donor->city,
				'state'             => $donor->state,
				'zip'               => $donor->zip,
				'country'           => $donor->country,
			] : null,
			'campaign'                => $campaign ? [
				'id'    => $campaign->id,
				'title' => $campaign->title,
			] : null,
			'transactions'            => $prepared_transactions,
		];
	}

	/**
	 * Calculate the processing fee for a transaction.
	 *
	 * @param Transaction $txn Transaction model.
	 * @return int Processing fee in minor units.
	 */
	private function calculate_processing_fee( Transaction $txn ): int {
		if ( $txn->fee_amount > 0 ) {
			return $txn->fee_amount;
		}

		[ $fee_rate, $fee_fixed ] = $this->get_fee_params( $txn );

		return (int) round( $txn->amount * $fee_rate + $fee_fixed );
	}

	/**
	 * Get collection query parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_collection_params(): array {
		return [
			'page'        => [
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page'    => [
				'type'              => 'integer',
				'default'           => 25,
				'sanitize_callback' => static fn( $val ) => min( absint( $val ), 100 ),
			],
			'orderby'     => [
				'type'              => 'string',
				'default'           => 'date_created',
				'enum'              => [ 'date_created', 'date_next_renewal', 'amount', 'status' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'order'       => [
				'type'              => 'string',
				'default'           => 'DESC',
				'enum'              => [ 'ASC', 'DESC' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'status'      => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'donor_id'    => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'campaign_id' => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'search'      => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}
}
