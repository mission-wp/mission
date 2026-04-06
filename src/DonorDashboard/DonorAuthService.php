<?php
/**
 * Donor authentication service.
 *
 * Handles account activation (with email verification), login, logout,
 * and current donor resolution.
 *
 * @package Mission
 */

namespace Mission\DonorDashboard;

use Mission\Email\EmailModule;
use Mission\Models\Donor;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless service for donor authentication.
 */
class DonorAuthService {

	/**
	 * Hours before an activation token expires.
	 */
	private const TOKEN_EXPIRY_HOURS = 24;

	/**
	 * Default minimum password length for donor accounts.
	 */
	private const MIN_PASSWORD_LENGTH = 8;

	/**
	 * Send an activation email with a verification link.
	 *
	 * Generates a secure token, stores its hash in donor meta, and
	 * emails the plain-text token as a link the donor must click
	 * before they can set a password.
	 *
	 * @param string $email Donor email address.
	 * @return void
	 *
	 * @throws \RuntimeException If the donor is not found or already has an account.
	 */
	public function send_activation_email( string $email ): void {
		$donor = Donor::find_by_email( $email );

		if ( ! $donor ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'We couldn\'t find any donations with this email address.', 'mission' ) );
		}

		if ( $donor->user_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'An account already exists for this email. Please log in instead.', 'mission' ) );
		}

		// Generate a secure random token and store its hash.
		$token      = wp_generate_password( 32, false );
		$token_hash = wp_hash_password( $token );

		$donor->update_meta( 'activation_token', $token_hash );
		$donor->update_meta(
			'activation_token_expires',
			gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_EXPIRY_HOURS * HOUR_IN_SECONDS )
		);

		// Build the verification URL.
		$dashboard_url    = $this->get_dashboard_url();
		$verification_url = add_query_arg(
			[
				'activation_token' => $token,
				'email'            => rawurlencode( $email ),
			],
			$dashboard_url
		);

		/** @var EmailModule $email_module */
		$email_module = \Mission\Plugin::instance()->get_email_module();

		if ( ! $email_module->is_email_enabled( 'account_activation' ) ) {
			return;
		}

		$data = [
			'donor'            => $donor,
			'verification_url' => $verification_url,
			'expiry_hours'     => self::TOKEN_EXPIRY_HOURS,
		];

		$subject = __( 'Verify your email to activate your donor account', 'mission' );

		$custom_subject = $email_module->get_custom_subject( 'account_activation' );
		if ( $custom_subject ) {
			$subject = $email_module->replace_subject_tags(
				$custom_subject,
				[
					'{donor_name}'   => $donor->first_name ?: __( 'Friend', 'mission' ),
					'{organization}' => ( new \Mission\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) ),
				]
			);
		}

		$html = $email_module->render_template( 'account-activation', array_merge( $data, [ 'subject' => $subject ] ) );
		$email_module->send( $donor->email, $subject, $html );

		/**
		 * Fires after an activation email is sent to a donor.
		 *
		 * @param Donor $donor The donor model.
		 */
		do_action( 'mission_donor_activation_email_sent', $donor );
	}

	/**
	 * Validate an activation token without consuming it.
	 *
	 * Used during page render to decide whether to show the
	 * "set your password" form.
	 *
	 * @param string $email Donor email address.
	 * @param string $token Plain-text activation token.
	 * @return Donor|null The donor if valid, null otherwise.
	 */
	public function validate_activation_token( string $email, string $token ): ?Donor {
		$donor = Donor::find_by_email( $email );

		if ( ! $donor || $donor->user_id ) {
			return null;
		}

		$stored_hash = $donor->get_meta( 'activation_token' );
		$expires     = $donor->get_meta( 'activation_token_expires' );

		if ( ! $stored_hash || ! $expires ) {
			return null;
		}

		// Check expiration.
		if ( strtotime( $expires ) < time() ) {
			return null;
		}

		// Verify the token hash.
		if ( ! wp_check_password( $token, $stored_hash ) ) {
			return null;
		}

		return $donor;
	}

	/**
	 * Activate a donor account using a verified token.
	 *
	 * Validates the token, creates a WordPress user with the
	 * `mission_donor` role, links it to the donor record, cleans up
	 * the token meta, and logs them in.
	 *
	 * @param string $email    Donor email address.
	 * @param string $password Plain-text password.
	 * @param string $token    Plain-text activation token from the email link.
	 * @return Donor The activated donor.
	 *
	 * @throws \RuntimeException If the token is invalid, expired, or the donor already has an account.
	 */
	public function activate( string $email, string $password, string $token ): Donor {
		$this->validate_password_length( $password );

		$donor = $this->validate_activation_token( $email, $token );

		if ( ! $donor ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'This activation link is invalid or has expired. Please request a new one.', 'mission' ) );
		}

		$user_id = $donor->create_user_account( $password );

		// Clean up token meta.
		$donor->delete_meta( 'activation_token' );
		$donor->delete_meta( 'activation_token_expires' );

		// Log the donor in immediately.
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		/**
		 * Fires after a donor activates their account.
		 *
		 * @param Donor    $donor The donor model.
		 * @param \WP_User $user  The newly created WordPress user.
		 */
		do_action( 'mission_donor_account_activated', $donor, get_userdata( $user_id ) );

		return $donor;
	}

	/**
	 * Log a donor in.
	 *
	 * @param string $email    Email address.
	 * @param string $password Plain-text password.
	 * @param bool   $remember Whether to persist the session.
	 * @return Donor The authenticated donor.
	 *
	 * @throws \RuntimeException If credentials are invalid or the user is not a donor.
	 */
	public function login( string $email, string $password, bool $remember = false ): Donor {
		$user = wp_signon(
			[
				'user_login'    => $email,
				'user_password' => $password,
				'remember'      => $remember,
			],
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'Invalid email or password.', 'mission' ) );
		}

		if ( ! in_array( 'mission_donor', $user->roles, true ) ) {
			wp_logout();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'Invalid email or password.', 'mission' ) );
		}

		$donor = Donor::find_by_user_id( $user->ID );

		if ( ! $donor ) {
			wp_logout();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'Invalid email or password.', 'mission' ) );
		}

		return $donor;
	}

	/**
	 * Log the current donor out.
	 *
	 * @return void
	 */
	public function logout(): void {
		wp_logout();
	}

	/**
	 * Resolve the currently authenticated donor.
	 *
	 * @return Donor|null The donor, or null if not authenticated.
	 */
	public function get_current_donor(): ?Donor {
		$user = wp_get_current_user();

		if ( ! $user->ID || ! in_array( 'mission_donor', $user->roles, true ) ) {
			return null;
		}

		return Donor::find_by_user_id( $user->ID );
	}

	/**
	 * Send a password reset email to a donor.
	 *
	 * Looks up the donor and their linked WordPress user, generates a
	 * WP-native password reset key, and sends a branded email with a
	 * link to reset their password on the donor dashboard.
	 *
	 * @param string $email Donor email address.
	 * @return void
	 *
	 * @throws \RuntimeException If the donor is not found or has no account.
	 */
	public function forgot_password( string $email ): void {
		$donor = Donor::find_by_email( $email );

		if ( ! $donor ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'We couldn\'t find a donor account with this email address.', 'mission' ) );
		}

		if ( ! $donor->user_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'No account has been activated for this email. Please activate your account first.', 'mission' ) );
		}

		$user = get_userdata( $donor->user_id );

		if ( ! $user ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'Unable to process this request.', 'mission' ) );
		}

		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'Unable to generate a password reset link. Please try again later.', 'mission' ) );
		}

		$reset_url = add_query_arg(
			[
				'action' => 'reset-password',
				'key'    => $key,
				'login'  => rawurlencode( $user->user_login ),
			],
			$this->get_dashboard_url()
		);

		/** @var EmailModule $email_module */
		$email_module = \Mission\Plugin::instance()->get_email_module();

		if ( ! $email_module->is_email_enabled( 'password_reset' ) ) {
			return;
		}

		$subject = __( 'Reset your password', 'mission' );

		$custom_subject = $email_module->get_custom_subject( 'password_reset' );
		if ( $custom_subject ) {
			$subject = $email_module->replace_subject_tags(
				$custom_subject,
				[
					'{donor_name}'   => $donor->first_name ?: __( 'Friend', 'mission' ),
					'{organization}' => ( new \Mission\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) ),
				]
			);
		}

		$data = [
			'donor'        => $donor,
			'reset_url'    => $reset_url,
			'expiry_hours' => 24,
			'subject'      => $subject,
		];

		$html = $email_module->render_template( 'password-reset', $data );
		$email_module->send( $donor->email, $subject, $html );

		/**
		 * Fires after a password reset email is sent to a donor.
		 *
		 * @param Donor $donor The donor model.
		 */
		do_action( 'mission_donor_password_reset_email_sent', $donor );
	}

	/**
	 * Reset a donor's password using a WP-native reset key.
	 *
	 * Validates the key, updates the password, and logs the donor in.
	 *
	 * @param string $login        WordPress user login (the donor's email).
	 * @param string $key          Password reset key from the email link.
	 * @param string $new_password The new password.
	 * @return Donor The donor, now logged in.
	 *
	 * @throws \RuntimeException If the key is invalid/expired or the user is not a donor.
	 */
	public function reset_password( string $login, string $key, string $new_password ): Donor {
		$this->validate_password_length( $new_password );

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'This password reset link is invalid or has expired. Please request a new one.', 'mission' ) );
		}

		if ( ! in_array( 'mission_donor', $user->roles, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'This password reset link is invalid or has expired. Please request a new one.', 'mission' ) );
		}

		$donor = Donor::find_by_user_id( $user->ID );

		if ( ! $donor ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'This password reset link is invalid or has expired. Please request a new one.', 'mission' ) );
		}

		// Set the new password (destroys all existing sessions).
		wp_set_password( $new_password, $user->ID );

		// Invalidate the reset key to prevent reuse.
		wp_update_user(
			[
				'ID'                  => $user->ID,
				'user_activation_key' => '',
			]
		);

		// Log the donor in immediately.
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		/**
		 * Fires after a donor resets their password.
		 *
		 * @param Donor    $donor The donor model.
		 * @param \WP_User $user  The WordPress user.
		 */
		do_action( 'mission_donor_password_reset', $donor, $user );

		return $donor;
	}

	/**
	 * Validate a password reset key without consuming it.
	 *
	 * Used during page render to decide whether to show the
	 * "choose a new password" form. Does not invalidate the key.
	 *
	 * @param string $login WordPress user login.
	 * @param string $key   Password reset key.
	 * @return \WP_User|null The user if valid, null otherwise.
	 */
	public function validate_reset_key( string $login, string $key ): ?\WP_User {
		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			return null;
		}

		if ( ! in_array( 'mission_donor', $user->roles, true ) ) {
			return null;
		}

		return $user;
	}

	/**
	 * Format a donor for API responses.
	 *
	 * @param Donor $donor The donor model.
	 * @return array<string, mixed>
	 */
	public function format_donor_response( Donor $donor ): array {
		return [
			'id'                => $donor->id,
			'email'             => $donor->email,
			'first_name'        => $donor->first_name,
			'last_name'         => $donor->last_name,
			'total_donated'     => $donor->total_donated,
			'transaction_count' => $donor->transaction_count,
		];
	}

	/**
	 * Validate a password meets the minimum length.
	 *
	 * @param string $password Password to validate.
	 * @throws \RuntimeException If the password is too short.
	 */
	private function validate_password_length( string $password ): void {
		/**
		 * Filters the minimum password length for donor accounts.
		 *
		 * @param int $min_length Default minimum length.
		 */
		$min_length = (int) apply_filters( 'mission_donor_min_password_length', self::MIN_PASSWORD_LENGTH );

		if ( strlen( $password ) < $min_length ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: minimum password length */
					__( 'Password must be at least %d characters.', 'mission' ),
					$min_length
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Get the donor dashboard page URL.
	 *
	 * @return string
	 */
	private function get_dashboard_url(): string {
		$page_id = (int) get_option( 'mission_dashboard_page_id', 0 );

		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}

		return home_url( '/' );
	}
}
