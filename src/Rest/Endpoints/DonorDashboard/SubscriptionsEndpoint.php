<?php
/**
 * Donor dashboard subscriptions endpoint.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints\DonorDashboard;

use MissionDP\Currency\Currency;
use MissionDP\Models\Donor;
use MissionDP\Models\Subscription;
use MissionDP\Rest\Args;
use MissionDP\Rest\RestErrors;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\DonorDashboardPrepareTrait;
use MissionDP\Rest\Traits\ResolveDonorTrait;
use MissionDP\Settings\SettingsService;
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
		private SettingsService $settings,
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
			'id' => Args::id(),
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
					'id'              => Args::id(),
					'donation_amount' => Args::integer(
						[
							'required' => true,
							'minimum'  => 100,
						]
					),
					'tip_amount'      => Args::integer(
						[
							'required' => true,
							'minimum'  => 0,
						]
					),
					'fee_amount'      => Args::integer(
						[
							'required' => false,
							'default'  => 0,
							'minimum'  => 0,
						]
					),
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
					'id'                => Args::id(),
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

		$result = $subscription->cancel();

		if ( is_wp_error( $result ) ) {
			return $result;
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

		$result = $subscription->pause();

		if ( is_wp_error( $result ) ) {
			return $result;
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

		$result = $subscription->resume();

		if ( is_wp_error( $result ) ) {
			return $result;
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

		$donation_amount = (int) $request->get_param( 'donation_amount' );
		$tip_amount      = (int) $request->get_param( 'tip_amount' );
		$fee_amount      = (int) $request->get_param( 'fee_amount' );

		// One major unit, raised to Stripe's published minimum where higher.
		$minimum = Currency::minimum_charge( $subscription->currency );

		if ( $donation_amount < $minimum ) {
			return new WP_Error(
				'amount_too_low',
				sprintf(
					/* translators: %s: formatted minimum amount (e.g. "$1.00") */
					__( 'Donation amount must be at least %s.', 'mission-donation-platform' ),
					Currency::format_amount( $minimum, $subscription->currency )
				),
				[ 'status' => 400 ]
			);
		}

		if ( 0 !== $donation_amount % Currency::rounding_unit( $subscription->currency ) ) {
			return new WP_Error(
				'invalid_amount',
				sprintf(
					/* translators: %s: ISO currency code (e.g. "ISK") */
					__( 'Donations in %s must be a whole number.', 'mission-donation-platform' ),
					strtoupper( $subscription->currency )
				),
				[ 'status' => 400 ]
			);
		}

		if ( $tip_amount < 0 ) {
			return new WP_Error(
				'invalid_tip',
				__( 'Tip amount cannot be negative.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$result = $subscription->update_amount( $donation_amount, $tip_amount, $fee_amount );

		if ( is_wp_error( $result ) ) {
			return $result;
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

		$result = $subscription->create_setup_intent();

		if ( is_wp_error( $result ) ) {
			return $result;
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

		$card = $subscription->update_payment_method( $request->get_param( 'payment_method_id' ) );

		if ( is_wp_error( $card ) ) {
			return $card;
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
			return RestErrors::subscription_not_found();
		}

		if ( $subscription->donor_id !== $donor->id ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage this subscription.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return $subscription;
	}
}
