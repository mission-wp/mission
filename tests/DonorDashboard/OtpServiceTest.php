<?php
/**
 * Tests for the OtpService.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\DonorDashboard;

use MissionDP\DonorDashboard\OtpException;
use MissionDP\DonorDashboard\OtpService;
use MissionDP\Email\EmailModule;
use WP_UnitTestCase;

/**
 * OtpService test class.
 *
 * Codes live in transients; the service receives a stub EmailModule so sends
 * are captured rather than mailed. Tests read the captured code to verify.
 */
class OtpServiceTest extends WP_UnitTestCase {

	/**
	 * The most recent code captured from a "sent" email.
	 *
	 * @var string
	 */
	private string $last_code = '';

	/**
	 * Build the service with a stub email module that captures the code.
	 *
	 * @return OtpService
	 */
	private function service(): OtpService {
		$test = $this;

		$email = new class( $test ) extends EmailModule {
			/**
			 * Parent test case.
			 *
			 * @var OtpServiceTest
			 */
			private OtpServiceTest $test;

			/**
			 * Constructor.
			 *
			 * @param OtpServiceTest $test Parent test case.
			 */
			public function __construct( OtpServiceTest $test ) {
				$this->test = $test;
				$this->init();
			}

			/**
			 * Capture the rendered code instead of mailing.
			 *
			 * @param string $template Template name.
			 * @param array  $data     Template data.
			 * @return string
			 */
			public function render_template( string $template, array $data = [] ): string {
				$this->test->capture_code( (string) ( $data['code'] ?? '' ) );
				return '<html></html>';
			}

			/**
			 * Swallow the send.
			 *
			 * @param string $to      Recipient.
			 * @param string $subject Subject.
			 * @param string $message Body.
			 * @param array  $headers Headers.
			 * @return bool
			 */
			public function send( string $to, string $subject, string $message, array $headers = [] ): bool {
				return true;
			}
		};

		return new OtpService( $email );
	}

	/**
	 * Record the code captured during send (called by the stub module).
	 *
	 * @param string $code The 6-digit code.
	 * @return void
	 */
	public function capture_code( string $code ): void {
		$this->last_code = $code;
	}

	/**
	 * Test a sent code is 6 digits, emailed, and starts a cooldown.
	 */
	public function test_send_emails_code_and_starts_cooldown(): void {
		$service = $this->service();
		$service->send( 'signup@example.com', 'signup' );

		$this->assertMatchesRegularExpression( '/^\d{6}$/', $this->last_code );
		$this->assertGreaterThan( 0, $service->cooldown_remaining( 'signup@example.com', 'signup' ) );
	}

	/**
	 * Test requesting another code during the cooldown is rejected.
	 */
	public function test_send_within_cooldown_throws(): void {
		$service = $this->service();
		$service->send( 'cool@example.com', 'signup' );

		try {
			$service->send( 'cool@example.com', 'signup' );
			$this->fail( 'Expected an OtpException for the cooldown.' );
		} catch ( OtpException $e ) {
			$this->assertSame( OtpException::COOLDOWN, $e->reason );
			$this->assertGreaterThan( 0, $e->retry_after );
		}
	}

	/**
	 * Test a correct code verifies once, then is burned.
	 */
	public function test_verify_success_burns_code(): void {
		$service = $this->service();
		$service->send( 'ok@example.com', 'signup' );

		$this->assertTrue( $service->verify( 'ok@example.com', 'signup', $this->last_code ) );

		// Re-using the same code fails because it was burned.
		try {
			$service->verify( 'ok@example.com', 'signup', $this->last_code );
			$this->fail( 'Expected an OtpException after the code was burned.' );
		} catch ( OtpException $e ) {
			$this->assertSame( OtpException::EXPIRED, $e->reason );
		}
	}

	/**
	 * Test a wrong code throws INVALID.
	 */
	public function test_verify_wrong_code_throws_invalid(): void {
		$service = $this->service();
		$service->send( 'wrong@example.com', 'signup' );

		$bad = '000000' === $this->last_code ? '111111' : '000000';

		try {
			$service->verify( 'wrong@example.com', 'signup', $bad );
			$this->fail( 'Expected an OtpException for a wrong code.' );
		} catch ( OtpException $e ) {
			$this->assertSame( OtpException::INVALID, $e->reason );
		}
	}

	/**
	 * Test the code is burned after too many wrong attempts.
	 */
	public function test_verify_exhausts_after_max_attempts(): void {
		$service = $this->service();
		$service->send( 'max@example.com', 'signup' );

		$bad = '000000' === $this->last_code ? '111111' : '000000';

		// Five wrong attempts are INVALID; the sixth exhausts the code.
		for ( $i = 0; $i < 5; $i++ ) {
			try {
				$service->verify( 'max@example.com', 'signup', $bad );
			} catch ( OtpException $e ) {
				$this->assertSame( OtpException::INVALID, $e->reason );
			}
		}

		try {
			$service->verify( 'max@example.com', 'signup', $bad );
			$this->fail( 'Expected an OtpException once attempts are exhausted.' );
		} catch ( OtpException $e ) {
			$this->assertSame( OtpException::EXHAUSTED, $e->reason );
		}
	}

	/**
	 * Test the sixth code request within the hour is throttled.
	 */
	public function test_send_throttled_after_hourly_cap(): void {
		$service = $this->service();
		$email   = 'cap@example.com';

		// Five sends are allowed; clear the resend cooldown between each so
		// only the hourly counter is in play.
		for ( $i = 0; $i < 5; $i++ ) {
			$service->send( $email, 'signup' );
			delete_transient( 'missiondp_otp_cooldown_' . md5( $email . '|signup' ) );
		}

		try {
			$service->send( $email, 'signup' );
			$this->fail( 'Expected an OtpException once the hourly cap is hit.' );
		} catch ( OtpException $e ) {
			$this->assertSame( OtpException::THROTTLED, $e->reason );
			$this->assertGreaterThan( 0, $e->retry_after );
		}
	}

	/**
	 * Test a stored code past its expiry verifies as expired and is burned.
	 */
	public function test_verify_stale_code_reports_expired(): void {
		$service = $this->service();
		$email   = 'stale@example.com';
		$service->send( $email, 'signup' );

		// Age the stored code past its expiry while the transient still exists.
		$key             = 'missiondp_otp_code_' . md5( $email . '|signup' );
		$data            = get_transient( $key );
		$data['expires'] = time() - 1;
		set_transient( $key, $data, 60 );

		try {
			$service->verify( $email, 'signup', $this->last_code );
			$this->fail( 'Expected an OtpException for a stale code.' );
		} catch ( OtpException $e ) {
			$this->assertSame( OtpException::EXPIRED, $e->reason );
		}

		// The stale code is deleted, so it reads the same as never existing.
		$this->assertFalse( get_transient( $key ) );
	}

	/**
	 * Test verifying with no outstanding code reports as expired.
	 */
	public function test_verify_without_code_throws_expired(): void {
		$service = $this->service();

		try {
			$service->verify( 'none@example.com', 'signup', '123456' );
			$this->fail( 'Expected an OtpException when no code exists.' );
		} catch ( OtpException $e ) {
			$this->assertSame( OtpException::EXPIRED, $e->reason );
		}
	}

	/**
	 * Test a reset grant is single-use.
	 */
	public function test_reset_grant_is_single_use(): void {
		$service = $this->service();
		$token   = $service->grant_reset( 'grant@example.com' );

		$this->assertTrue( $service->consume_reset_grant( 'grant@example.com', $token ) );
		$this->assertFalse( $service->consume_reset_grant( 'grant@example.com', $token ) );
	}

	/**
	 * Test a wrong grant token is rejected.
	 */
	public function test_reset_grant_rejects_wrong_token(): void {
		$service = $this->service();
		$service->grant_reset( 'grant2@example.com' );

		$this->assertFalse( $service->consume_reset_grant( 'grant2@example.com', 'not-the-token' ) );
	}
}
