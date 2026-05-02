<?php
/**
 * Donor dashboard email change endpoint.
 *
 * Handles requesting, confirming, and cancelling an email address change
 * with token-based verification.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints\DonorDashboard;

use MissionDP\Email\EmailModule;
use MissionDP\Models\Donor;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\RateLimitTrait;
use MissionDP\Rest\Traits\ResolveDonorTrait;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Email change endpoint.
 */
class EmailChangeEndpoint {

	use RateLimitTrait;
	use ResolveDonorTrait;

	/**
	 * Hours before a verification token expires.
	 */
	private const TOKEN_EXPIRY_HOURS = 24;

	/**
	 * Register REST routes.
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/email-change/request',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'request_email_change' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
				'args'                => [
					'new_email' => [
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
			'/donor-dashboard/email-change/confirm',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'confirm_email_change' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => static fn( $val ) => is_email( $val ),
					],
					'token' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/email-change/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel_email_change' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);
	}

	/**
	 * Request an email change by sending a verification email to the new address.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function request_email_change( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'email_change_request', 3, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		$donor = $this->resolve_donor();
		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$new_email = $request->get_param( 'new_email' );

		// Validate the new email is different.
		if ( $new_email === $donor->email ) {
			return new WP_Error(
				'email_unchanged',
				__( 'That is already your email address.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		// Check the new email is not used by another donor.
		$existing_donor = Donor::find_by_email( $new_email );
		if ( $existing_donor && $existing_donor->id !== $donor->id ) {
			return new WP_Error(
				'email_taken',
				__( 'This email is already associated with another account.', 'mission-donation-platform' ),
				[ 'status' => 409 ]
			);
		}

		// Check the new email is not used by another WP user.
		$existing_user_id = email_exists( $new_email );
		if ( $existing_user_id && $existing_user_id !== $donor->user_id ) {
			return new WP_Error(
				'email_taken',
				__( 'This email is already in use.', 'mission-donation-platform' ),
				[ 'status' => 409 ]
			);
		}

		// Generate token and store pending change.
		$token      = wp_generate_password( 32, false );
		$token_hash = wp_hash_password( $token );

		$donor->update_meta( 'pending_email', $new_email );
		$donor->update_meta( 'pending_email_token', $token_hash );
		$donor->update_meta(
			'pending_email_token_expires',
			gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_EXPIRY_HOURS * HOUR_IN_SECONDS )
		);

		// Build verification URL.
		$verification_url = add_query_arg(
			[
				'action' => 'verify-email',
				'token'  => $token,
				'email'  => rawurlencode( $donor->email ),
			],
			$this->get_dashboard_url()
		);

		// Send verification email to the new address.
		/** @var EmailModule $email_module */
		$email_module = \MissionDP\Plugin::instance()->get_email_module();

		if ( ! $email_module->is_email_enabled( 'email_change_verification' ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Email change verification is disabled.', 'mission-donation-platform' ) ], 200 );
		}

		$subject = __( 'Verify your new email address', 'mission-donation-platform' );

		$custom_subject = $email_module->get_custom_subject( 'email_change_verification' );
		if ( $custom_subject ) {
			$subject = $email_module->replace_subject_tags(
				$custom_subject,
				[
					'{donor_name}'   => $donor->first_name ?: __( 'Friend', 'mission-donation-platform' ),
					'{new_email}'    => $new_email,
					'{organization}' => ( new \MissionDP\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) ),
				]
			);
		}

		$data = [
			'donor'            => $donor,
			'new_email'        => $new_email,
			'verification_url' => $verification_url,
			'expiry_hours'     => self::TOKEN_EXPIRY_HOURS,
			'subject'          => $subject,
		];

		$html = $email_module->render_template( 'email-change-verification', $data );
		$email_module->send( $new_email, $subject, $html );

		/**
		 * Fires after a verification email is sent for an email change request.
		 *
		 * @param Donor  $donor     The donor requesting the change.
		 * @param string $new_email The requested new email address.
		 */
		do_action( 'missiondp_donor_email_change_requested', $donor, $new_email );

		return new WP_REST_Response(
			[
				'pending_email' => $new_email,
				'message'       => __( 'Verification email sent. Check your inbox.', 'mission-donation-platform' ),
			]
		);
	}

	/**
	 * Confirm an email change via verification token.
	 *
	 * This endpoint is public — the token serves as authorization.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm_email_change( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit( 'email_change_confirm', 5, 300 );
		if ( $rate_error ) {
			return $rate_error;
		}

		$current_email = $request->get_param( 'email' );
		$token         = $request->get_param( 'token' );

		$donor = Donor::find_by_email( $current_email );
		if ( ! $donor ) {
			return new WP_Error(
				'invalid_request',
				__( 'This verification link is invalid or has expired.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		// Validate token.
		$stored_hash = $donor->get_meta( 'pending_email_token' );
		$expires     = $donor->get_meta( 'pending_email_token_expires' );

		if ( ! $stored_hash || ! $expires ) {
			return new WP_Error(
				'invalid_request',
				__( 'This verification link is invalid or has expired.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		if ( strtotime( $expires ) < time() ) {
			$this->cleanup_pending_meta( $donor );
			return new WP_Error(
				'token_expired',
				__( 'This verification link has expired. Please request a new one.', 'mission-donation-platform' ),
				[ 'status' => 410 ]
			);
		}

		if ( ! wp_check_password( $token, $stored_hash ) ) {
			return new WP_Error(
				'invalid_token',
				__( 'This verification link is invalid or has expired.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$new_email = $donor->get_meta( 'pending_email' );
		if ( ! $new_email ) {
			return new WP_Error(
				'invalid_request',
				__( 'No pending email change found.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		// Re-validate the new email is still available (race condition guard).
		$existing_donor = Donor::find_by_email( $new_email );
		if ( $existing_donor && $existing_donor->id !== $donor->id ) {
			$this->cleanup_pending_meta( $donor );
			return new WP_Error(
				'email_taken',
				__( 'This email is already associated with another account.', 'mission-donation-platform' ),
				[ 'status' => 409 ]
			);
		}

		$existing_user_id = email_exists( $new_email );
		if ( $existing_user_id && $existing_user_id !== $donor->user_id ) {
			$this->cleanup_pending_meta( $donor );
			return new WP_Error(
				'email_taken',
				__( 'This email is already in use.', 'mission-donation-platform' ),
				[ 'status' => 409 ]
			);
		}

		// Perform the email change.
		$old_email = $donor->email;
		$this->apply_email_change( $donor, $new_email );

		// Clean up pending meta.
		$this->cleanup_pending_meta( $donor );

		/**
		 * Fires after a donor's email address has been changed.
		 *
		 * @param Donor  $donor     The donor.
		 * @param string $old_email Previous email address.
		 * @param string $new_email New email address.
		 */
		do_action( 'missiondp_donor_email_changed', $donor, $old_email, $new_email );

		return new WP_REST_Response(
			[
				'email'   => $new_email,
				'message' => __( 'Email address updated successfully.', 'mission-donation-platform' ),
			]
		);
	}

	/**
	 * Cancel a pending email change.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_email_change(): WP_REST_Response|WP_Error {
		$donor = $this->resolve_donor();
		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$this->cleanup_pending_meta( $donor );

		return new WP_REST_Response( [ 'message' => __( 'Email change cancelled.', 'mission-donation-platform' ) ] );
	}

	/**
	 * Apply the email change to all storage locations.
	 *
	 * Updates in the correct order to avoid sync hook conflicts:
	 * 1. Donor table email
	 * 2. WP user email (sync hook sees no diff, early-returns)
	 * 3. WP user_login via direct DB query
	 * 4. Clean cache and re-set auth cookie
	 *
	 * @param Donor  $donor     The donor.
	 * @param string $new_email The new email address.
	 */
	private function apply_email_change( Donor $donor, string $new_email ): void {
		global $wpdb;

		// 1. Update donors table.
		$donor->email = $new_email;
		$donor->save();

		// 2. Update WP user email.
		if ( $donor->user_id ) {
			wp_update_user(
				[
					'ID'         => $donor->user_id,
					'user_email' => $new_email,
				]
			);

			// 3. Update user_login directly (no WP API for this).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->users,
				[ 'user_login' => $new_email ],
				[ 'ID' => $donor->user_id ],
				[ '%s' ],
				[ '%d' ]
			);

			// 4. Clear cached user data and re-set auth cookie.
			clean_user_cache( $donor->user_id );

			if ( get_current_user_id() === $donor->user_id ) {
				wp_set_current_user( $donor->user_id );
				wp_set_auth_cookie( $donor->user_id, true );
			}
		}
	}

	/**
	 * Remove all pending email change meta from a donor.
	 *
	 * @param Donor $donor The donor.
	 */
	private function cleanup_pending_meta( Donor $donor ): void {
		$donor->delete_meta( 'pending_email' );
		$donor->delete_meta( 'pending_email_token' );
		$donor->delete_meta( 'pending_email_token_expires' );
	}

	/**
	 * Get the donor dashboard page URL.
	 *
	 * @return string
	 */
	private function get_dashboard_url(): string {
		$page_id = (int) get_option( 'missiondp_dashboard_page_id', 0 );

		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}

		return home_url( '/' );
	}
}
