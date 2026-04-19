<?php
/**
 * Donor dashboard subscriptions endpoint.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints\DonorDashboard;

use Mission\Currency\Currency;
use Mission\Models\Donor;
use Mission\Models\Subscription;
use Mission\Rest\RestModule;
use Mission\Rest\Traits\DonorDashboardPrepareTrait;
use Mission\Rest\Traits\ResolveDonorTrait;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Handles donor dashboard subscription management routes.
 */
class SubscriptionsEndpoint {

	use ResolveDonorTrait;
	use DonorDashboardPrepareTrait;

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
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/subscriptions',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_subscriptions' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);

		$action_args = [
			'id' => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
		];

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/subscriptions/(?P<id>\d+)/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel_subscription' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
				'args'                => $action_args,
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/subscriptions/(?P<id>\d+)/pause',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'pause_subscription' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
				'args'                => $action_args,
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/subscriptions/(?P<id>\d+)/resume',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'resume_subscription' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
				'args'                => $action_args,
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/subscriptions/(?P<id>\d+)/amount',
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_subscription_amount' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
				'args'                => [
					'id'              => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'donation_amount' => [
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 100,
						'sanitize_callback' => 'absint',
					],
					'tip_amount'      => [
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					],
					'fee_amount'      => [
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/subscriptions/(?P<id>\d+)/setup-intent',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_setup_intent' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
				'args'                => $action_args,
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/subscriptions/(?P<id>\d+)/payment-method',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_subscription_payment_method' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
				'args'                => [
					'id'                => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'payment_method_id' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * GET /donor-dashboard/subscriptions
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_subscriptions(): WP_REST_Response|WP_Error {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$is_test       = $this->settings->get( 'test_mode', false );
		$subscriptions = $donor->subscriptions( [ 'is_test' => $is_test ] );

		$this->preload_campaigns( array_map( static fn( $s ) => $s->campaign_id, $subscriptions ) );

		return new WP_REST_Response(
			array_map( [ $this, 'prepare_subscription' ], $subscriptions )
		);
	}

	/**
	 * POST /donor-dashboard/subscriptions/{id}/cancel
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_subscription( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subscription = $this->resolve_donor_subscription( $request->get_param( 'id' ) );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		if ( ! in_array( $subscription->status, [ 'active', 'paused', 'past_due' ], true ) ) {
			return new WP_Error(
				'subscription_not_cancellable',
				__( 'This subscription cannot be cancelled.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $subscription->cancel() ) {
			return new WP_Error(
				'subscription_cancel_failed',
				__( 'Failed to cancel subscription. Please try again.', 'missionwp-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'id'      => $subscription->id,
				'status'  => $subscription->status,
			]
		);
	}

	/**
	 * POST /donor-dashboard/subscriptions/{id}/pause
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pause_subscription( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subscription = $this->resolve_donor_subscription( $request->get_param( 'id' ) );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		if ( 'active' !== $subscription->status ) {
			return new WP_Error(
				'subscription_not_pausable',
				__( 'Only active subscriptions can be paused.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $subscription->pause() ) {
			return new WP_Error(
				'subscription_pause_failed',
				__( 'Failed to pause subscription. Please try again.', 'missionwp-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'id'      => $subscription->id,
				'status'  => $subscription->status,
			]
		);
	}

	/**
	 * POST /donor-dashboard/subscriptions/{id}/resume
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resume_subscription( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subscription = $this->resolve_donor_subscription( $request->get_param( 'id' ) );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		if ( 'paused' !== $subscription->status ) {
			return new WP_Error(
				'subscription_not_resumable',
				__( 'Only paused subscriptions can be resumed.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $subscription->resume() ) {
			return new WP_Error(
				'subscription_resume_failed',
				__( 'Failed to resume subscription. Please try again.', 'missionwp-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'id'      => $subscription->id,
				'status'  => $subscription->status,
			]
		);
	}

	/**
	 * PUT /donor-dashboard/subscriptions/{id}/amount
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_subscription_amount( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subscription = $this->resolve_donor_subscription( $request->get_param( 'id' ) );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		if ( ! in_array( $subscription->status, [ 'active', 'paused' ], true ) ) {
			return new WP_Error(
				'subscription_not_updatable',
				__( 'Only active or paused subscriptions can be updated.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$donation_amount = (int) $request->get_param( 'donation_amount' );
		$tip_amount      = (int) $request->get_param( 'tip_amount' );
		$fee_amount      = (int) $request->get_param( 'fee_amount' );

		if ( $donation_amount < 100 ) {
			return new WP_Error(
				'amount_too_low',
				sprintf(
					/* translators: %s: formatted minimum amount (e.g. "$1.00") */
					__( 'Donation amount must be at least %s.', 'missionwp-donation-platform' ),
					Currency::format_amount( 100, $subscription->currency )
				),
				[ 'status' => 400 ]
			);
		}

		if ( $tip_amount < 0 ) {
			return new WP_Error(
				'invalid_tip',
				__( 'Tip amount cannot be negative.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $subscription->update_amount( $donation_amount, $tip_amount, $fee_amount ) ) {
			return new WP_Error(
				'subscription_update_failed',
				__( 'Failed to update subscription amount. Please try again.', 'missionwp-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( $this->prepare_subscription( $subscription ) );
	}

	/**
	 * POST /donor-dashboard/subscriptions/{id}/setup-intent
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_setup_intent( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subscription = $this->resolve_donor_subscription( $request->get_param( 'id' ) );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		if ( ! in_array( $subscription->status, [ 'active', 'paused' ], true ) ) {
			return new WP_Error(
				'subscription_not_updatable',
				__( 'Only active or paused subscriptions can be updated.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$result = $subscription->create_setup_intent();

		if ( ! $result ) {
			return new WP_Error(
				'setup_intent_failed',
				__( 'Failed to initialize payment update. Please try again.', 'missionwp-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'client_secret'        => $result['client_secret'],
				'connected_account_id' => $result['connected_account_id'],
			]
		);
	}

	/**
	 * POST /donor-dashboard/subscriptions/{id}/payment-method
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_subscription_payment_method( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subscription = $this->resolve_donor_subscription( $request->get_param( 'id' ) );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		if ( ! in_array( $subscription->status, [ 'active', 'paused' ], true ) ) {
			return new WP_Error(
				'subscription_not_updatable',
				__( 'Only active or paused subscriptions can be updated.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$card = $subscription->update_payment_method( $request->get_param( 'payment_method_id' ) );

		if ( ! $card ) {
			return new WP_Error(
				'payment_method_update_failed',
				__( 'Failed to update payment method. Please try again.', 'missionwp-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		/**
		 * Fires after a subscription's payment method is updated.
		 *
		 * @param Subscription $subscription The subscription.
		 */
		do_action( 'mission_subscription_payment_method_updated', $subscription );

		return new WP_REST_Response( $card );
	}

	/**
	 * Resolve a subscription owned by the current donor.
	 *
	 * @param int $id Subscription ID.
	 * @return Subscription|WP_Error
	 */
	private function resolve_donor_subscription( int $id ): Subscription|WP_Error {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$subscription = Subscription::find( $id );

		if ( ! $subscription ) {
			return new WP_Error(
				'subscription_not_found',
				__( 'Subscription not found.', 'missionwp-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		if ( $subscription->donor_id !== $donor->id ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage this subscription.', 'missionwp-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return $subscription;
	}
}
