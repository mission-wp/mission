<?php
/**
 * REST endpoint for donor authentication.
 *
 * Handles account activation (with email verification), login, logout,
 * and current donor lookup.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\DonorDashboard\DonorAuthService;
use Mission\Rest\RestModule;
use Mission\Rest\Traits\DonorPermissionTrait;
use Mission\Rest\Traits\RateLimitTrait;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Donor auth endpoint class.
 */
class DonorAuthEndpoint {

	use DonorPermissionTrait;
	use RateLimitTrait;

	/**
	 * Constructor.
	 *
	 * @param DonorAuthService $auth Auth service.
	 */
	public function __construct(
		private readonly DonorAuthService $auth,
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-auth/send-activation',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send_activation' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => static fn( $val ) => is_email( $val ),
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-auth/activate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'activate' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => static fn( $val ) => is_email( $val ),
					],
					'password' => [
						'type'     => 'string',
						'required' => true,
					],
					'token'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-auth/login',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'login' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => static fn( $val ) => is_email( $val ),
					],
					'password' => [
						'type'     => 'string',
						'required' => true,
					],
					'remember' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-auth/forgot-password',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'forgot_password' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => static fn( $val ) => is_email( $val ),
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-auth/reset-password',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reset_password' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'login'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'key'      => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'password' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-auth/logout',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'logout' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-auth/me',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'me' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);
	}

	/**
	 * Send an activation verification email.
	 *
	 * Always returns a success message to prevent email enumeration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_activation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'send_activation', 3, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		try {
			$this->auth->send_activation_email( $request->get_param( 'email' ) );
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Silently swallow — never reveal whether the email exists.
		}

		return new WP_REST_Response(
			[
				'message' => __( 'If a donor account exists for that email, a verification link has been sent.', 'missionwp-donation-platform' ),
			]
		);
	}

	/**
	 * Activate a donor account with a verified token.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function activate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'activate', 5, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		try {
			$donor = $this->auth->activate(
				$request->get_param( 'email' ),
				$request->get_param( 'password' ),
				$request->get_param( 'token' ),
			);

			return new WP_REST_Response( $this->auth->format_donor_response( $donor ), 201 );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'activation_failed', $e->getMessage(), [ 'status' => 400 ] );
		}
	}

	/**
	 * Send a password reset email.
	 *
	 * Always returns a success message to prevent email enumeration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function forgot_password( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'forgot_password', 3, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		try {
			$this->auth->forgot_password( $request->get_param( 'email' ) );
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Silently swallow — never reveal whether the email exists.
		}

		return new WP_REST_Response(
			[
				'message' => __( 'If an account exists for that email, a password reset link has been sent.', 'missionwp-donation-platform' ),
			]
		);
	}

	/**
	 * Reset a donor's password using a reset key.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_password( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'reset_password', 5, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		try {
			$donor = $this->auth->reset_password(
				$request->get_param( 'login' ),
				$request->get_param( 'key' ),
				$request->get_param( 'password' ),
			);

			return new WP_REST_Response( $this->auth->format_donor_response( $donor ) );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'reset_failed', $e->getMessage(), [ 'status' => 400 ] );
		}
	}

	/**
	 * Log a donor in.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function login( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'login', 5, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		try {
			$donor = $this->auth->login(
				$request->get_param( 'email' ),
				$request->get_param( 'password' ),
				(bool) $request->get_param( 'remember' ),
			);

			return new WP_REST_Response( $this->auth->format_donor_response( $donor ) );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'login_failed', $e->getMessage(), [ 'status' => 401 ] );
		}
	}

	/**
	 * Log the current donor out.
	 *
	 * @return WP_REST_Response
	 */
	public function logout(): WP_REST_Response {
		$this->auth->logout();

		return new WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * Return the current authenticated donor.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function me(): WP_REST_Response|WP_Error {
		$donor = $this->auth->get_current_donor();

		if ( ! $donor ) {
			return new WP_Error( 'donor_not_found', __( 'Donor record not found.', 'missionwp-donation-platform' ), [ 'status' => 404 ] );
		}

		return new WP_REST_Response( $this->auth->format_donor_response( $donor ) );
	}
}
