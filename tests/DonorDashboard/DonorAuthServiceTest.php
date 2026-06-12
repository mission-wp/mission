<?php
/**
 * Tests for the DonorAuthService email flows.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\DonorDashboard;

use MissionDP\Database\DatabaseModule;
use MissionDP\DonorDashboard\DonorAuthService;
use MissionDP\Email\EmailModule;
use MissionDP\Models\Donor;
use WP_UnitTestCase;

/**
 * DonorAuthService test class.
 *
 * The service receives its EmailModule via the constructor, so these tests
 * run it against a stub without touching the booted plugin's modules.
 */
class DonorAuthServiceTest extends WP_UnitTestCase {

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Clean up plugin tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Build an EmailModule stub that records send() calls instead of mailing.
	 *
	 * @param bool $enabled Value is_email_enabled() should report.
	 * @return EmailModule
	 */
	private function stub_email_module( bool $enabled = true ): EmailModule {
		return new class( $enabled ) extends EmailModule {
			/**
			 * Captured send() calls.
			 *
			 * @var array<array{to: string, subject: string, message: string}>
			 */
			public array $sent = [];

			/**
			 * Whether emails report as enabled.
			 *
			 * @var bool
			 */
			private bool $enabled;

			/**
			 * Constructor.
			 *
			 * @param bool $enabled Whether emails report as enabled.
			 */
			public function __construct( bool $enabled ) {
				$this->enabled = $enabled;
				$this->init();
			}

			/**
			 * Report the configured enabled state.
			 *
			 * @param string $email_type Email type key.
			 * @return bool
			 */
			public function is_email_enabled( string $email_type ): bool {
				return $this->enabled;
			}

			/**
			 * Skip template rendering.
			 *
			 * @param string $template Template name.
			 * @param array  $data     Template data.
			 * @return string
			 */
			public function render_template( string $template, array $data = [] ): string {
				return '<html>' . $template . '</html>';
			}

			/**
			 * Record the send instead of mailing.
			 *
			 * @param string $to      Recipient.
			 * @param string $subject Subject.
			 * @param string $message Body.
			 * @param array  $headers Headers.
			 * @return bool
			 */
			public function send( string $to, string $subject, string $message, array $headers = [] ): bool {
				$this->sent[] = [
					'to'      => $to,
					'subject' => $subject,
					'message' => $message,
				];
				return true;
			}
		};
	}

	/**
	 * Test that send_activation_email stores a token and sends through the
	 * injected module.
	 */
	public function test_send_activation_email_uses_injected_module(): void {
		$donor = new Donor( [
			'email'      => 'activate@example.com',
			'first_name' => 'Acti',
			'last_name'  => 'Vation',
		] );
		$donor->save();

		$email   = $this->stub_email_module();
		$service = new DonorAuthService( $email );

		$service->send_activation_email( 'activate@example.com' );

		$this->assertCount( 1, $email->sent );
		$this->assertSame( 'activate@example.com', $email->sent[0]['to'] );
		$this->assertNotEmpty( $email->sent[0]['subject'] );

		$donor = Donor::find( $donor->id );
		$this->assertNotEmpty( $donor->get_meta( 'activation_token' ) );
		$this->assertNotEmpty( $donor->get_meta( 'activation_token_expires' ) );
	}

	/**
	 * Test that no email is sent when the activation email type is disabled.
	 */
	public function test_send_activation_email_respects_disabled_setting(): void {
		$donor = new Donor( [
			'email'      => 'disabled@example.com',
			'first_name' => 'Dis',
			'last_name'  => 'Abled',
		] );
		$donor->save();

		$email   = $this->stub_email_module( false );
		$service = new DonorAuthService( $email );

		$service->send_activation_email( 'disabled@example.com' );

		$this->assertCount( 0, $email->sent );
	}

	/**
	 * Test that an unknown email throws without sending anything.
	 */
	public function test_send_activation_email_throws_for_unknown_email(): void {
		$email   = $this->stub_email_module();
		$service = new DonorAuthService( $email );

		$this->expectException( \RuntimeException::class );

		try {
			$service->send_activation_email( 'nobody@example.com' );
		} finally {
			$this->assertCount( 0, $email->sent );
		}
	}
}
