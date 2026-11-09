<?php
/**
 * Tests for donors whose email already belongs to a WordPress user.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\DonorDashboard;

use MissionDP\Database\DatabaseModule;
use MissionDP\DonorDashboard\DonorAuthService;
use MissionDP\DonorDashboard\DonorDashboardModule;
use MissionDP\Email\EmailModule;
use MissionDP\Models\Donor;
use MissionDP\Rest\Endpoints\DonorAuthEndpoint;
use WP_UnitTestCase;

/**
 * Linking requires both email proof (token) and the existing account's password.
 */
class ExistingAccountLinkingTest extends WP_UnitTestCase {

	/**
	 * Mission emails captured by the stub module.
	 *
	 * @var array<int, string>
	 */
	private array $mission_sent = [];

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Reset the mailer and captured sends.
	 */
	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		$this->mission_sent = [];
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

		reset_phpmailer_instance();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Activation.
	// -------------------------------------------------------------------------

	/**
	 * The right existing password links the donor and leaves the account untouched.
	 */
	public function test_activate_links_existing_account_with_its_password(): void {
		$admin_id = $this->create_user( 'administrator', 'owner@example.com', 'adminpass99' );
		$donor    = $this->create_donor_with_token( 'owner@example.com', 'tok' );

		$result = $this->service()->activate( 'owner@example.com', 'adminpass99', 'tok' );

		$this->assertSame( $admin_id, $result->user_id );
		$this->assertSame( $admin_id, Donor::find( $donor->id )->user_id );
		$this->assertSame( $admin_id, get_current_user_id() );

		$user = get_userdata( $admin_id );
		$this->assertSame( [ 'administrator' ], array_values( $user->roles ) );
		$this->assertTrue( wp_check_password( 'adminpass99', $user->user_pass, $admin_id ) );
		$this->assertEmpty( Donor::find( $donor->id )->get_meta( 'activation_token' ) );
	}

	/**
	 * Existing passwords are not held to Mission's new-password length rule.
	 */
	public function test_activate_accepts_short_existing_password(): void {
		$user_id = $this->create_user( 'subscriber', 'member@example.com', 'abc12' );
		$this->create_donor_with_token( 'member@example.com', 'tok' );

		$result = $this->service()->activate( 'member@example.com', 'abc12', 'tok' );

		$this->assertSame( $user_id, $result->user_id );
	}

	/**
	 * A valid token with the wrong password links nothing and keeps the token.
	 *
	 * Covers the pre-created account attack: someone registered a WP account
	 * with the victim's email; the victim's token alone must not link to it.
	 */
	public function test_activate_with_wrong_password_does_not_link(): void {
		$this->create_user( 'subscriber', 'victim@example.com', 'attackerpw1' );
		$donor = $this->create_donor_with_token( 'victim@example.com', 'tok' );

		try {
			$this->service()->activate( 'victim@example.com', 'victimguess1', 'tok' );
			$this->fail( 'Expected a RuntimeException for the wrong password.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringNotContainsString( 'already used', $e->getMessage() );
		}

		$this->assertNull( Donor::find( $donor->id )->user_id );
		$this->assertNotEmpty( Donor::find( $donor->id )->get_meta( 'activation_token' ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * An account already linked to another donor record is never re-linked.
	 */
	public function test_activate_refuses_account_linked_to_another_donor(): void {
		$user_id = $this->create_user( 'subscriber', 'shared@example.com', 'sharedpw11' );

		$other = new Donor( [ 'email' => 'other@example.com', 'user_id' => $user_id ] );
		$other->save();

		$donor = $this->create_donor_with_token( 'shared@example.com', 'tok' );

		$this->expectException( \RuntimeException::class );

		try {
			$this->service()->activate( 'shared@example.com', 'sharedpw11', 'tok' );
		} finally {
			$this->assertNull( Donor::find( $donor->id )->user_id );
		}
	}

	/**
	 * Linking fires the domain event the activity feed listens to.
	 */
	public function test_activate_fires_linked_action(): void {
		$user_id = $this->create_user( 'subscriber', 'event@example.com', 'eventpw111' );
		$this->create_donor_with_token( 'event@example.com', 'tok' );

		$linked = [];
		add_action(
			'mission_donor_user_linked',
			static function ( $donor, $id ) use ( &$linked ) {
				$linked[] = $id;
			},
			10,
			2
		);

		$this->service()->activate( 'event@example.com', 'eventpw111', 'tok' );

		$this->assertSame( [ $user_id ], $linked );
	}

	// -------------------------------------------------------------------------
	// Access for linked non-donor accounts.
	// -------------------------------------------------------------------------

	/**
	 * A donor linked to a non-donor user (e.g. by a GiveWP migration) can log in.
	 */
	public function test_login_allows_linked_non_donor_user(): void {
		$user_id = $this->create_user( 'subscriber', 'migrated@example.com', 'migratedpw1' );
		$donor   = new Donor( [ 'email' => 'migrated@example.com', 'user_id' => $user_id ] );
		$donor->save();

		$result = $this->service()->login( 'migrated@example.com', 'migratedpw1' );

		$this->assertSame( $donor->id, $result->id );
		$this->assertSame( $user_id, get_current_user_id() );
	}

	/**
	 * A WordPress user with no donor record still can't log in to the dashboard.
	 */
	public function test_login_rejects_unlinked_user(): void {
		$this->create_user( 'administrator', 'plainadmin@example.com', 'adminpass99' );

		$this->expectException( \RuntimeException::class );
		$this->service()->login( 'plainadmin@example.com', 'adminpass99' );
	}

	/**
	 * Donor REST endpoints accept a linked non-donor user and refuse an unlinked one.
	 */
	public function test_donor_permission_follows_the_link_not_the_role(): void {
		$endpoint = new DonorAuthEndpoint( $this->service() );

		$linked_id = $this->create_user( 'administrator', 'linkedadmin@example.com', 'adminpass99' );
		( new Donor( [ 'email' => 'linkedadmin@example.com', 'user_id' => $linked_id ] ) )->save();
		wp_set_current_user( $linked_id );
		$this->assertTrue( $endpoint->check_donor_permission() );

		$unlinked_id = $this->create_user( 'administrator', 'unlinked@example.com', 'adminpass99' );
		wp_set_current_user( $unlinked_id );
		$this->assertWPError( $endpoint->check_donor_permission() );
	}

	/**
	 * Admins linked to a donor keep their admin bar (role-based behavior stays role-based).
	 */
	public function test_linked_admin_keeps_admin_bar(): void {
		$admin_id = $this->create_user( 'administrator', 'bar@example.com', 'adminpass99' );
		( new Donor( [ 'email' => 'bar@example.com', 'user_id' => $admin_id ] ) )->save();
		wp_set_current_user( $admin_id );

		$this->assertTrue( ( new DonorDashboardModule() )->hide_admin_bar_for_donors( true ) );
	}

	// -------------------------------------------------------------------------
	// Password reset for linked non-donor accounts.
	// -------------------------------------------------------------------------

	/**
	 * Forgot password for a linked admin sends core's reset email, not the dashboard link.
	 */
	public function test_forgot_password_for_linked_admin_uses_core_reset(): void {
		$admin_id = $this->create_user( 'administrator', 'resetadmin@example.com', 'adminpass99' );
		( new Donor( [ 'email' => 'resetadmin@example.com', 'user_id' => $admin_id ] ) )->save();

		// The test site's default sender (wordpress@localhost) is rejected by PHPMailer.
		add_filter( 'wp_mail_from', static fn() => 'wordpress@example.org' );

		$this->service()->forgot_password( 'resetadmin@example.com' );

		$this->assertSame( [], $this->mission_sent );

		$sent = tests_retrieve_phpmailer_instance()->get_sent( 0 );
		$this->assertNotFalse( $sent );
		$this->assertMatchesRegularExpression( '#wp-login\.php\?[^\s]*action=rp#', $sent->body );
		$this->assertStringNotContainsString( 'action=reset-password', $sent->body );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Build the service with an email module that records sends.
	 *
	 * @return DonorAuthService
	 */
	private function service(): DonorAuthService {
		$sent = &$this->mission_sent;

		$email = new class( $sent ) extends EmailModule {
			/**
			 * Captured recipients.
			 *
			 * @var array<int, string>
			 */
			private array $sent;

			/**
			 * Constructor.
			 *
			 * @param array $sent Reference to the test's capture array.
			 */
			public function __construct( array &$sent ) {
				$this->sent = &$sent;
				$this->init();
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
				$this->sent[] = $to;
				return true;
			}
		};

		return new DonorAuthService( $email );
	}

	/**
	 * Create a WordPress user.
	 *
	 * @param string $role     Role.
	 * @param string $email    Email.
	 * @param string $password Password.
	 * @return int User ID.
	 */
	private function create_user( string $role, string $email, string $password ): int {
		return self::factory()->user->create(
			[
				'role'       => $role,
				'user_email' => $email,
				'user_pass'  => $password,
			]
		);
	}

	/**
	 * Store an unlinked donor with an unexpired activation token.
	 *
	 * @param string $email Donor email.
	 * @param string $token Plain-text token.
	 * @return Donor
	 */
	private function create_donor_with_token( string $email, string $token ): Donor {
		$donor = new Donor( [ 'email' => $email ] );
		$donor->save();
		$donor->update_meta( 'activation_token', wp_hash_password( $token ) );
		$donor->update_meta( 'activation_token_expires', gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

		return $donor;
	}
}
