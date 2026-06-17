<?php
/**
 * One-time-code (OTP) exception.
 *
 * @package MissionDP
 */

namespace MissionDP\DonorDashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Raised by OtpService when a code can't be sent or verified.
 *
 * Carries a machine-readable reason so the REST layer can map failures to the
 * right status without leaking which specific check failed (invalid/expired/
 * exhausted are reported to the user as one generic "wrong code" message).
 */
class OtpException extends \RuntimeException {

	public const INVALID   = 'invalid';
	public const EXPIRED   = 'expired';
	public const EXHAUSTED = 'exhausted';
	public const COOLDOWN  = 'cooldown';
	public const THROTTLED = 'throttled';

	/**
	 * Constructor.
	 *
	 * Prefer the named factories below; they keep the throw site free of the
	 * extra (non-output) arguments the escape-output sniff would flag.
	 *
	 * @param string $message     Human-readable message (already escaped).
	 * @param string $reason      One of the reason constants.
	 * @param int    $retry_after Seconds to wait before retrying (cooldown/throttled).
	 */
	public function __construct(
		string $message,
		public string $reason,
		public int $retry_after = 0,
	) {
		parent::__construct( $message );
	}

	/**
	 * Sender is within the resend cooldown.
	 *
	 * @param int $retry_after Seconds until another code may be requested.
	 * @return self
	 */
	public static function cooldown( int $retry_after ): self {
		return new self( esc_html__( 'Please wait before requesting another code.', 'mission-donation-platform' ), self::COOLDOWN, $retry_after );
	}

	/**
	 * Sender is over the hourly code cap.
	 *
	 * @param int $retry_after Seconds to suggest waiting.
	 * @return self
	 */
	public static function throttled( int $retry_after ): self {
		return new self( esc_html__( 'Too many codes requested. Please try again later.', 'mission-donation-platform' ), self::THROTTLED, $retry_after );
	}

	/**
	 * No outstanding code, or it has expired.
	 *
	 * @return self
	 */
	public static function expired(): self {
		return new self( esc_html__( 'That code has expired. Request a new one.', 'mission-donation-platform' ), self::EXPIRED );
	}

	/**
	 * Too many wrong attempts; the code is burned.
	 *
	 * @return self
	 */
	public static function exhausted(): self {
		return new self( esc_html__( 'Too many attempts. Request a new code.', 'mission-donation-platform' ), self::EXHAUSTED );
	}

	/**
	 * The submitted code did not match.
	 *
	 * @return self
	 */
	public static function invalid(): self {
		return new self( esc_html__( 'That code didn\'t match. Please try again.', 'mission-donation-platform' ), self::INVALID );
	}
}
