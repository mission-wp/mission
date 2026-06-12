<?php
/**
 * Tests for the AdminNotifier.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Email;

use MissionDP\Email\AdminNotifier;
use MissionDP\Email\EmailModule;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * Admin notifier test class.
 *
 * Tests use a custom body stored in settings so notify() renders without
 * needing the full template data the real listeners provide.
 */
class AdminNotifierTest extends WP_UnitTestCase {

	/**
	 * Notifier under test.
	 *
	 * @var AdminNotifier
	 */
	private AdminNotifier $notifier;

	/**
	 * Filters added during the test, removed in tear_down.
	 *
	 * @var array<int, array{string, callable}>
	 */
	private array $filters_to_remove = [];

	/**
	 * Set up a fresh notifier and mailer before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		reset_phpmailer_instance();

		$email = new EmailModule();
		$email->init();

		$this->notifier = new AdminNotifier( $email, new SettingsService() );

		// A stored custom body lets notify() render any type without full
		// template data.
		update_option(
			SettingsService::OPTION_NAME,
			[ 'emails' => [ 'admin_new_donation' => [ 'body' => '<p>Notification body</p>' ] ] ]
		);
	}

	/**
	 * Clean up settings and filters after each test.
	 */
	public function tear_down(): void {
		delete_option( SettingsService::OPTION_NAME );

		foreach ( $this->filters_to_remove as [ $hook, $callback ] ) {
			remove_filter( $hook, $callback );
		}
		$this->filters_to_remove = [];

		reset_phpmailer_instance();

		parent::tear_down();
	}

	/**
	 * Add a filter that is automatically removed in tear_down.
	 *
	 * @param string   $hook     Filter name.
	 * @param callable $callback Filter callback.
	 * @param int      $args     Number of accepted arguments.
	 * @return void
	 */
	private function add_test_filter( string $hook, callable $callback, int $args = 1 ): void {
		add_filter( $hook, $callback, 10, $args );
		$this->filters_to_remove[] = [ $hook, $callback ];
	}

	/**
	 * Get all captured emails.
	 *
	 * @return array<int, object>
	 */
	private function sent_emails(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	// -------------------------------------------------------------------------
	// notify() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test notify falls back to the WordPress admin email when unconfigured.
	 */
	public function test_notify_sends_to_default_admin_email(): void {
		$result = $this->notifier->notify( 'admin_new_donation', 'Test notification' );

		$this->assertTrue( $result );

		$sent = $this->sent_emails();
		$this->assertCount( 1, $sent );
		$this->assertSame( get_option( 'admin_email' ), $sent[0]['to'][0][0] );
		$this->assertSame( 'Test notification', $sent[0]['subject'] );
		$this->assertStringContainsString( 'Notification body', $sent[0]['body'] );
	}

	/**
	 * Test notify is suppressed when the type is disabled in settings.
	 */
	public function test_notify_returns_false_when_disabled(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[ 'emails' => [ 'admin_new_donation' => [ 'enabled' => false ] ] ]
		);

		$result = $this->notifier->notify( 'admin_new_donation', 'Test notification' );

		$this->assertFalse( $result );
		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test the should-send filter can suppress a notification.
	 */
	public function test_should_send_filter_suppresses_notification(): void {
		$this->add_test_filter( 'mission_should_send_admin_notification', '__return_false' );

		$result = $this->notifier->notify( 'admin_new_donation', 'Test notification' );

		$this->assertFalse( $result );
		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test notify bails when no recipients can be resolved.
	 */
	public function test_notify_returns_false_when_no_recipients(): void {
		// update_option() refuses an invalid admin_email, so blank it via the
		// pre-option filter instead.
		$this->add_test_filter( 'pre_option_admin_email', '__return_empty_string' );

		$result = $this->notifier->notify( 'admin_new_donation', 'Test notification' );

		$this->assertFalse( $result );
		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test notify bails when the template renders to nothing.
	 */
	public function test_notify_returns_false_for_unknown_template(): void {
		$result = $this->notifier->notify( 'admin_nonexistent_type', 'Test notification' );

		$this->assertFalse( $result );
		$this->assertCount( 0, $this->sent_emails() );
	}

	/**
	 * Test every configured recipient receives the notification.
	 */
	public function test_configured_recipients_each_receive_email(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[
				'emails' => [
					'admin_new_donation' => [
						'body'       => '<p>Notification body</p>',
						'recipients' => [ 'one@example.org', 'two@example.org' ],
					],
				],
			]
		);

		$result = $this->notifier->notify( 'admin_new_donation', 'Test notification' );

		$this->assertTrue( $result );

		$sent = $this->sent_emails();
		$this->assertCount( 2, $sent );
		$this->assertSame( 'one@example.org', $sent[0]['to'][0][0] );
		$this->assertSame( 'two@example.org', $sent[1]['to'][0][0] );
	}

	// -------------------------------------------------------------------------
	// get_recipients() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test configured recipients win over the admin email fallback.
	 */
	public function test_get_recipients_returns_configured_list(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[ 'emails' => [ 'admin_new_donation' => [ 'recipients' => [ 'configured@example.org' ] ] ] ]
		);

		$this->assertSame(
			[ 'configured@example.org' ],
			$this->notifier->get_recipients( 'admin_new_donation' )
		);
	}

	/**
	 * Test the recipients filter can replace the resolved list.
	 */
	public function test_recipients_filter_overrides_list(): void {
		$this->add_test_filter(
			'mission_admin_notification_recipients',
			static fn() => [ 'filtered@example.org' ]
		);

		$this->assertSame(
			[ 'filtered@example.org' ],
			$this->notifier->get_recipients( 'admin_new_donation' )
		);

		$this->notifier->notify( 'admin_new_donation', 'Test notification' );

		$sent = $this->sent_emails();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'filtered@example.org', $sent[0]['to'][0][0] );
	}

	/**
	 * Test admin email types are enabled by default.
	 */
	public function test_is_enabled_defaults_to_true(): void {
		$this->assertTrue( $this->notifier->is_enabled( 'admin_new_donation' ) );
		$this->assertTrue( $this->notifier->is_enabled( 'admin_refund' ) );
	}
}
