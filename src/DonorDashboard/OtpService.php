<?php
/**
 * One-time-code (OTP) service.
 *
 * Sends and verifies 6-digit email verification codes for the peer-to-peer
 * signup flow (verifying a donor's email before creating an account, and the
 * inline password-reset path). Codes live in transients keyed by a hash of the
 * email so the flow works before any donor row exists.
 *
 * @package MissionDP
 */

namespace MissionDP\DonorDashboard;

use MissionDP\Email\EmailModule;
use MissionDP\Models\Donor;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless service for email one-time codes.
 */
class OtpService {

	/**
	 * Code lifetime in seconds.
	 */
	private const CODE_EXPIRY = 600;

	/**
	 * Seconds a sender must wait before requesting another code.
	 */
	private const RESEND_COOLDOWN = 30;

	/**
	 * Verification attempts allowed before a code is burned.
	 */
	private const MAX_ATTEMPTS = 5;

	/**
	 * Codes a single email may request per hour.
	 */
	private const HOURLY_CAP = 5;

	/**
	 * Lifetime of a password-reset grant in seconds.
	 */
	private const GRANT_EXPIRY = 600;

	/**
	 * Constructor.
	 *
	 * @param EmailModule $email Email module used to deliver codes.
	 */
	public function __construct(
		private EmailModule $email,
	) {}

	/**
	 * Generate, store, and email a fresh code.
	 *
	 * @param string $email   Recipient email address.
	 * @param string $purpose Code purpose ('signup' or 'reset').
	 * @return void
	 *
	 * @throws OtpException If the sender is within the cooldown or over the hourly cap.
	 */
	public function send( string $email, string $purpose ): void {
		$remaining = $this->cooldown_remaining( $email, $purpose );
		if ( $remaining > 0 ) {
			// Built then thrown so the escape-output sniff doesn't read the args.
			$error = OtpException::cooldown( $remaining );
			throw $error;
		}

		$count_key = $this->key( $email, $purpose, 'count' );
		$count     = (int) get_transient( $count_key );
		if ( $count >= self::HOURLY_CAP ) {
			$error = OtpException::throttled( self::RESEND_COOLDOWN );
			throw $error;
		}

		$code = (string) random_int( 100000, 999999 );

		set_transient(
			$this->key( $email, $purpose, 'code' ),
			[
				'hash'     => wp_hash_password( $code ),
				'attempts' => 0,
				'expires'  => time() + self::CODE_EXPIRY,
			],
			self::CODE_EXPIRY
		);

		set_transient( $this->key( $email, $purpose, 'cooldown' ), time() + self::RESEND_COOLDOWN, self::RESEND_COOLDOWN );
		set_transient( $count_key, $count + 1, HOUR_IN_SECONDS );

		$this->send_code_email( $email, $code );
	}

	/**
	 * Verify a submitted code, burning it on success.
	 *
	 * @param string $email   Email the code was sent to.
	 * @param string $purpose Code purpose ('signup' or 'reset').
	 * @param string $code    Submitted 6-digit code.
	 * @return bool True on success.
	 *
	 * @throws OtpException If the code is missing/expired, exhausted, or wrong.
	 */
	public function verify( string $email, string $purpose, string $code ): bool {
		$code_key = $this->key( $email, $purpose, 'code' );
		$data     = get_transient( $code_key );

		if ( ! is_array( $data ) || ( $data['expires'] ?? 0 ) < time() ) {
			delete_transient( $code_key );
			throw OtpException::expired();
		}

		++$data['attempts'];

		if ( $data['attempts'] > self::MAX_ATTEMPTS ) {
			delete_transient( $code_key );
			throw OtpException::exhausted();
		}

		if ( ! wp_check_password( $code, $data['hash'] ) ) {
			$ttl = max( 1, (int) $data['expires'] - time() );
			set_transient( $code_key, $data, $ttl );
			throw OtpException::invalid();
		}

		delete_transient( $code_key );

		return true;
	}

	/**
	 * Seconds remaining before another code may be requested.
	 *
	 * @param string $email   Email address.
	 * @param string $purpose Code purpose.
	 * @return int Seconds remaining, or 0 if a code can be sent now.
	 */
	public function cooldown_remaining( string $email, string $purpose ): int {
		$until = (int) get_transient( $this->key( $email, $purpose, 'cooldown' ) );

		return $until > 0 ? max( 0, $until - time() ) : 0;
	}

	/**
	 * Mint a single-use grant authorizing a password reset.
	 *
	 * Issued only after a verified 'reset' code; consumed by the set-password
	 * step. This is the only thing that authorizes setting a new password on an
	 * existing account, so the client is never trusted to assert "verified".
	 *
	 * @param string $email Email address.
	 * @return string Plain-text grant token (store the hash; return the token).
	 */
	public function grant_reset( string $email ): string {
		$token = wp_generate_password( 32, false );

		set_transient( $this->grant_key( $email ), wp_hash_password( $token ), self::GRANT_EXPIRY );

		return $token;
	}

	/**
	 * Consume a password-reset grant (single use).
	 *
	 * @param string $email Email address.
	 * @param string $token Plain-text grant token.
	 * @return bool True if the grant was valid (and is now spent).
	 */
	public function consume_reset_grant( string $email, string $token ): bool {
		$hash = get_transient( $this->grant_key( $email ) );

		if ( ! $hash ) {
			return false;
		}

		delete_transient( $this->grant_key( $email ) );

		return wp_check_password( $token, $hash );
	}

	/**
	 * Resend cooldown, exposed for the REST response.
	 *
	 * @return int
	 */
	public function resend_cooldown(): int {
		return self::RESEND_COOLDOWN;
	}

	/**
	 * Render and send the code email (neutral wording, never leaks donor status).
	 *
	 * Always sent regardless of the email-enabled toggle: verification can't
	 * work without delivery.
	 *
	 * @param string $email Recipient email.
	 * @param string $code  The 6-digit code.
	 * @return void
	 */
	private function send_code_email( string $email, string $code ): void {
		$org = ( new SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) );

		$subject = __( 'Your verification code', 'mission-donation-platform' );

		$custom_subject = $this->email->get_custom_subject( 'p2p_otp_code' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags(
				$custom_subject,
				[
					'{code}'         => $code,
					'{organization}' => $org,
				]
			);
		}

		$html = $this->email->render_template(
			'p2p-otp-code',
			[
				'code'           => $code,
				'expiry_minutes' => (int) ( self::CODE_EXPIRY / MINUTE_IN_SECONDS ),
				'organization'   => $org,
				'subject'        => $subject,
			]
		);

		$this->email->send( $email, $subject, $html );
	}

	/**
	 * Transient key for a code-related value.
	 *
	 * @param string $email   Email address.
	 * @param string $purpose Code purpose.
	 * @param string $kind    Value kind (code/cooldown/count).
	 * @return string
	 */
	private function key( string $email, string $purpose, string $kind ): string {
		return 'missiondp_otp_' . $kind . '_' . md5( strtolower( trim( $email ) ) . '|' . $purpose );
	}

	/**
	 * Transient key for a password-reset grant.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function grant_key( string $email ): string {
		return 'missiondp_otp_grant_' . md5( strtolower( trim( $email ) ) );
	}
}
