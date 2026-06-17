<?php
/**
 * REST endpoint for the peer-to-peer fundraiser signup flow.
 *
 * One class, several related routes (mirrors DonorAuthEndpoint): branch
 * resolution, sending/verifying one-time codes, the inline password reset, and
 * the final fundraiser/team creation. All public + rate-limited; handlers are
 * thin and delegate to FundraiserRegistrationService.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\DonorDashboard\DonorAuthService;
use MissionDP\DonorDashboard\OtpException;
use MissionDP\Models\Campaign;
use MissionDP\P2P\FundraiserRegistrationService;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\RateLimitTrait;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Fundraiser signup endpoint class.
 */
class RegisterFundraiserEndpoint {

	use RateLimitTrait;

	/**
	 * Constructor.
	 *
	 * @param DonorAuthService              $auth         Auth/session service (current-donor lookup).
	 * @param FundraiserRegistrationService $registration Signup orchestration.
	 */
	public function __construct(
		private DonorAuthService $auth,
		private FundraiserRegistrationService $registration,
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		$public = '__return_true';

		$email_arg = [
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_email',
			'validate_callback' => static fn( $val ) => is_email( $val ),
		];

		register_rest_route(
			RestModule::NAMESPACE,
			'/p2p/account-lookup',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'account_lookup' ],
				'permission_callback' => $public,
				'args'                => [
					'campaign_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'email'       => $email_arg,
					'password'    => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/p2p/send-code',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send_code' ],
				'permission_callback' => $public,
				'args'                => [
					'email'   => $email_arg,
					'purpose' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $val ) => in_array( $val, [ 'signup', 'reset' ], true ),
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/p2p/verify-code',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'verify_code' ],
				'permission_callback' => $public,
				'args'                => [
					'email'      => $email_arg,
					'purpose'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $val ) => in_array( $val, [ 'signup', 'reset' ], true ),
					],
					'code'       => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'first_name' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'last_name'  => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'phone'      => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'password'   => [ 'type' => 'string' ],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/p2p/set-password',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'set_password' ],
				'permission_callback' => $public,
				'args'                => [
					'email'    => $email_arg,
					'grant'    => [
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
			'/p2p/register',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'register_fundraiser' ],
				'permission_callback' => $public,
				'args'                => [
					'campaign_id'  => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'team_mode'    => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'team_id'      => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'team_name'    => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'goal'         => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'story'        => [
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					],
					'dedicate'     => [
						'type'    => 'boolean',
						'default' => false,
					],
					'tribute_type' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'honoree_name' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Decide the account branch for an email + password.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function account_lookup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'p2p_lookup', 10, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		$campaign = $this->resolve_campaign( $request );
		if ( $campaign instanceof WP_Error ) {
			return $campaign;
		}

		try {
			$branch = $this->registration->resolve_account( $request->get_param( 'email' ), $request->get_param( 'password' ) );
		} catch ( OtpException $e ) {
			return $this->otp_error( $e );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'weak_password', $e->getMessage(), [ 'status' => 400 ] );
		}

		$response = [ 'branch' => $branch ];
		if ( FundraiserRegistrationService::BRANCH_VERIFY_REQUIRED === $branch ) {
			$response['cooldown'] = $this->registration->resend_cooldown();
		}

		return new WP_REST_Response( $response );
	}

	/**
	 * Send or resend a verification code.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_code( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'p2p_send_code', 5, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		try {
			$this->registration->send_code( $request->get_param( 'email' ), $request->get_param( 'purpose' ) );
		} catch ( OtpException $e ) {
			return $this->otp_error( $e );
		}

		return new WP_REST_Response(
			[
				'sent'     => true,
				'cooldown' => $this->registration->resend_cooldown(),
			]
		);
	}

	/**
	 * Verify a code: create-and-login for signup, mint a reset grant for reset.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_code( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'p2p_verify_code', 10, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		$email = $request->get_param( 'email' );
		$code  = $request->get_param( 'code' );

		try {
			if ( 'reset' === $request->get_param( 'purpose' ) ) {
				$grant = $this->registration->verify_reset_code( $email, $code );
				return new WP_REST_Response( [ 'grant' => $grant ] );
			}

			$this->registration->complete_signup(
				$email,
				$code,
				(string) $request->get_param( 'first_name' ),
				(string) $request->get_param( 'last_name' ),
				(string) $request->get_param( 'phone' ),
				(string) $request->get_param( 'password' ),
			);

			return new WP_REST_Response( [ 'authenticated' => true ] );
		} catch ( OtpException $e ) {
			return $this->otp_error( $e );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'signup_failed', $e->getMessage(), [ 'status' => 400 ] );
		}
	}

	/**
	 * Set a new password using a verified reset grant.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_password( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'p2p_set_password', 5, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		try {
			$this->registration->set_password(
				$request->get_param( 'email' ),
				$request->get_param( 'grant' ),
				$request->get_param( 'password' ),
			);

			return new WP_REST_Response( [ 'authenticated' => true ] );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'reset_failed', $e->getMessage(), [ 'status' => 400 ] );
		}
	}

	/**
	 * Create the fundraiser (and optionally team) for the logged-in donor.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_fundraiser( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'p2p_register', 10, 600 );
		if ( $rate_error ) {
			return $rate_error;
		}

		$donor = $this->auth->get_current_donor();
		if ( ! $donor ) {
			return new WP_Error( 'not_authenticated', __( 'Please verify your email to continue.', 'mission-donation-platform' ), [ 'status' => 401 ] );
		}

		$campaign = $this->resolve_campaign( $request );
		if ( $campaign instanceof WP_Error ) {
			return $campaign;
		}

		$result = $this->registration->register_fundraiser(
			$donor,
			$campaign,
			[
				'team_mode'    => $request->get_param( 'team_mode' ),
				'team_id'      => (int) $request->get_param( 'team_id' ),
				'team_name'    => (string) $request->get_param( 'team_name' ),
				// The form collects dollars; the model stores minor units.
				'goal'         => (int) $request->get_param( 'goal' ) * 100,
				'story'        => (string) $request->get_param( 'story' ),
				'dedicate'     => (bool) $request->get_param( 'dedicate' ),
				'tribute_type' => $request->get_param( 'tribute_type' ),
				'honoree_name' => (string) $request->get_param( 'honoree_name' ),
			]
		);

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Resolve and validate the campaign for a request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return Campaign|WP_Error The open P2P campaign, or an error.
	 */
	private function resolve_campaign( WP_REST_Request $request ): Campaign|WP_Error {
		$campaign = Campaign::find( (int) $request->get_param( 'campaign_id' ) );

		if ( ! $campaign || ! $campaign->is_p2p() ) {
			return new WP_Error( 'invalid_campaign', __( 'This campaign is not accepting fundraisers.', 'mission-donation-platform' ), [ 'status' => 404 ] );
		}

		$settings = $campaign->p2p_settings();
		if ( empty( $settings['registration_open'] ) ) {
			return new WP_Error( 'registration_closed', __( 'Registration for this campaign is closed.', 'mission-donation-platform' ), [ 'status' => 403 ] );
		}

		return $campaign;
	}

	/**
	 * Map an OtpException to a REST error response.
	 *
	 * Cooldown/throttle become 429 with a retry hint; the rest collapse to a
	 * single generic message so a wrong code can't be distinguished from an
	 * expired or exhausted one.
	 *
	 * @param OtpException $e The exception.
	 * @return WP_Error
	 */
	private function otp_error( OtpException $e ): WP_Error {
		if ( in_array( $e->reason, [ OtpException::COOLDOWN, OtpException::THROTTLED ], true ) ) {
			return new WP_Error(
				'otp_cooldown',
				$e->getMessage(),
				[
					'status'      => 429,
					'retry_after' => $e->retry_after,
				]
			);
		}

		return new WP_Error( 'otp_invalid', $e->getMessage(), [ 'status' => 400 ] );
	}
}
