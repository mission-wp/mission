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

		wp_set_current_user( 0 );

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

	// -------------------------------------------------------------------------
	// create_account() / login_user() / set_password() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test create_account() makes a donor-role WP user without logging in.
	 */
	public function test_create_account_creates_donor_user(): void {
		$donor = new Donor( [ 'email' => 'new@example.com', 'first_name' => 'New', 'last_name' => 'User' ] );
		$donor->save();

		$service = new DonorAuthService( $this->stub_email_module() );
		$result  = $service->create_account( $donor, 'longenough1' );

		$this->assertNotEmpty( $result->user_id );
		$user = get_userdata( $result->user_id );
		$this->assertContains( 'missiondp_donor', $user->roles );
		$this->assertSame( 'new@example.com', $user->user_email );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Test create_account() rejects a short password and creates no user.
	 */
	public function test_create_account_rejects_short_password(): void {
		$donor = new Donor( [ 'email' => 'short@example.com', 'first_name' => 'S', 'last_name' => 'P' ] );
		$donor->save();

		$service = new DonorAuthService( $this->stub_email_module() );

		try {
			$service->create_account( $donor, 'short' );
			$this->fail( 'Expected a RuntimeException for a short password.' );
		} catch ( \RuntimeException $e ) {
			$this->assertNull( Donor::find( $donor->id )->user_id );
		}
	}

	/**
	 * Test login() sets the current user, so a nonce minted right after is valid.
	 *
	 * Regression guard: the signup modal logs in mid-flow and immediately mints a
	 * REST nonce for the register call; if login() leaves the current user as 0,
	 * that nonce is rejected as an invalid cookie nonce.
	 */
	public function test_login_sets_current_user(): void {
		$donor = new Donor( [ 'email' => 'loginnow@example.com', 'first_name' => 'L', 'last_name' => 'N' ] );
		$donor->save();

		$service = new DonorAuthService( $this->stub_email_module() );
		$service->create_account( $donor, 'longenough1' );
		wp_set_current_user( 0 );

		$service->login( 'loginnow@example.com', 'longenough1' );

		$this->assertSame( $donor->user_id, get_current_user_id() );
	}

	/**
	 * Test login_user() establishes a session for a donor.
	 */
	public function test_login_user_establishes_session(): void {
		$donor = new Donor( [ 'email' => 'login@example.com', 'first_name' => 'Log', 'last_name' => 'In' ] );
		$donor->save();

		$service = new DonorAuthService( $this->stub_email_module() );
		$service->create_account( $donor, 'longenough1' );

		$logged = $service->login_user( $donor->user_id );

		$this->assertSame( $donor->id, $logged->id );
		$this->assertSame( $donor->user_id, get_current_user_id() );
	}

	/**
	 * Test login_user() rejects a non-donor user.
	 */
	public function test_login_user_rejects_non_donor(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$service  = new DonorAuthService( $this->stub_email_module() );

		$this->expectException( \RuntimeException::class );
		$service->login_user( $admin_id );
	}

	/**
	 * Test set_password() changes the user's password.
	 */
	public function test_set_password_updates_credentials(): void {
		$donor = new Donor( [ 'email' => 'pw@example.com', 'first_name' => 'P', 'last_name' => 'W' ] );
		$donor->save();

		$service = new DonorAuthService( $this->stub_email_module() );
		$service->create_account( $donor, 'originalpw1' );

		$service->set_password( $donor->user_id, 'brandnewpw2' );

		$this->assertTrue( wp_check_password( 'brandnewpw2', get_userdata( $donor->user_id )->user_pass, $donor->user_id ) );
	}

	/**
	 * Test set_password() refuses a privileged (non-donor) account and leaves
	 * its password unchanged.
	 */
	public function test_set_password_rejects_non_donor(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator', 'user_pass' => 'adminpass99' ] );
		$service  = new DonorAuthService( $this->stub_email_module() );

		try {
			$service->set_password( $admin_id, 'attackerpw1' );
			$this->fail( 'Expected RuntimeException for a non-donor user.' );
		} catch ( \RuntimeException $e ) {
			$this->assertTrue( wp_check_password( 'adminpass99', get_userdata( $admin_id )->user_pass, $admin_id ) );
		}
	}
}
