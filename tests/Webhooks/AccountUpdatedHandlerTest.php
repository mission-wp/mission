<?php
/**
 * Tests for AccountUpdatedHandler.
 *
 * Verifies that account.updated webhook events are routed to the correct
 * connected Stripe account when multiple accounts are connected.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Webhooks;

use MissionDP\Settings\SettingsService;
use MissionDP\Webhooks\AccountUpdatedHandler;
use WP_UnitTestCase;

/**
 * AccountUpdatedHandler test class.
 */
class AccountUpdatedHandlerTest extends WP_UnitTestCase {

	/**
	 * Settings service instance.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Hooks added during tests that need cleanup.
	 *
	 * @var array<array{string, callable, int}>
	 */
	private array $hooks_to_remove = array();

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->settings = new SettingsService();
		delete_option( SettingsService::OPTION_NAME );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( SettingsService::OPTION_NAME );

		foreach ( $this->hooks_to_remove as [ $hook, $callback, $priority ] ) {
			remove_action( $hook, $callback, $priority );
		}
		$this->hooks_to_remove = array();

		parent::tear_down();
	}

	/**
	 * Helper to register an action that auto-cleans up after the test.
	 *
	 * @param string   $hook     Action hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Number of accepted args.
	 * @return void
	 */
	private function add_action_with_cleanup( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_action( $hook, $callback, $priority, $args );
		$this->hooks_to_remove[] = array( $hook, $callback, $priority );
	}

	/**
	 * Helper to build a stripe account record.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function make_account( array $overrides = array() ): array {
		return array_merge(
			array(
				'site_id'           => 'site_x',
				'site_token'        => 'tok_x',
				'account_id'        => 'acct_x',
				'display_name'      => 'X',
				'connection_status' => 'connected',
				'charges_enabled'   => false,
				'webhook_secret'    => 'whsec_x',
				'connected_at'      => gmdate( 'c' ),
			),
			$overrides
		);
	}

	/**
	 * Handler updates the account named by account_id, even with multiple
	 * connected accounts that previously had matching charges_enabled values.
	 */
	public function test_routes_update_to_account_id(): void {
		$this->settings->add_stripe_account( $this->make_account( array(
			'account_id'      => 'acct_a',
			'site_token'      => 'tok_a',
			'charges_enabled' => false,
		) ) );
		$this->settings->add_stripe_account( $this->make_account( array(
			'account_id'      => 'acct_b',
			'site_token'      => 'tok_b',
			'charges_enabled' => false,
		) ) );

		( new AccountUpdatedHandler() )->handle(
			array( 'charges_enabled' => true ),
			'acct_b'
		);

		$a = $this->settings->get_stripe_account_by_id( 'acct_a' );
		$b = $this->settings->get_stripe_account_by_id( 'acct_b' );

		$this->assertFalse( $a['charges_enabled'], 'Account A must not be affected.' );
		$this->assertTrue( $b['charges_enabled'], 'Account B must be updated.' );
	}

	/**
	 * Single-account installs receive events without an account_id (legacy
	 * forwarding behavior). The handler falls back to the only connected
	 * account so charges_enabled still syncs.
	 */
	public function test_single_account_fallback_when_account_id_missing(): void {
		$this->settings->add_stripe_account( $this->make_account( array(
			'account_id'      => 'acct_solo',
			'charges_enabled' => false,
		) ) );

		( new AccountUpdatedHandler() )->handle(
			array( 'charges_enabled' => true ),
			''
		);

		$account = $this->settings->get_stripe_account_by_id( 'acct_solo' );
		$this->assertTrue( $account['charges_enabled'] );
	}

	/**
	 * Multi-account install with an empty account_id is a no-op: we cannot
	 * tell which account to update and must not guess.
	 */
	public function test_multi_account_no_op_when_account_id_missing(): void {
		$this->settings->add_stripe_account( $this->make_account( array(
			'account_id'      => 'acct_a',
			'site_token'      => 'tok_a',
			'charges_enabled' => false,
		) ) );
		$this->settings->add_stripe_account( $this->make_account( array(
			'account_id'      => 'acct_b',
			'site_token'      => 'tok_b',
			'charges_enabled' => false,
		) ) );

		( new AccountUpdatedHandler() )->handle(
			array( 'charges_enabled' => true ),
			''
		);

		$this->assertFalse( $this->settings->get_stripe_account_by_id( 'acct_a' )['charges_enabled'] );
		$this->assertFalse( $this->settings->get_stripe_account_by_id( 'acct_b' )['charges_enabled'] );
	}

	/**
	 * Unknown account_id is a no-op (does NOT silently mis-update a different
	 * account when more than one is connected).
	 */
	public function test_unknown_account_id_is_no_op_when_multiple_connected(): void {
		$this->settings->add_stripe_account( $this->make_account( array(
			'account_id'      => 'acct_a',
			'site_token'      => 'tok_a',
			'charges_enabled' => false,
		) ) );
		$this->settings->add_stripe_account( $this->make_account( array(
			'account_id'      => 'acct_b',
			'site_token'      => 'tok_b',
			'charges_enabled' => false,
		) ) );

		( new AccountUpdatedHandler() )->handle(
			array( 'charges_enabled' => true ),
			'acct_does_not_exist'
		);

		$this->assertFalse( $this->settings->get_stripe_account_by_id( 'acct_a' )['charges_enabled'] );
		$this->assertFalse( $this->settings->get_stripe_account_by_id( 'acct_b' )['charges_enabled'] );
	}

	/**
	 * Idempotency: an event that does not change charges_enabled is a no-op
	 * and does not fire the charges_enabled/disabled action.
	 */
	public function test_no_op_when_charges_enabled_unchanged(): void {
		$this->settings->add_stripe_account( $this->make_account( array(
			'account_id'      => 'acct_a',
			'charges_enabled' => true,
		) ) );

		$fired = false;
		$this->add_action_with_cleanup(
			'mission_stripe_charges_enabled',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		( new AccountUpdatedHandler() )->handle(
			array( 'charges_enabled' => true ),
			'acct_a'
		);

		$this->assertFalse( $fired );
	}

	/**
	 * The charges_enabled action receives the affected account_id.
	 */
	public function test_charges_enabled_action_receives_account_id(): void {
		$this->settings->add_stripe_account( $this->make_account( array(
			'account_id'      => 'acct_a',
			'charges_enabled' => false,
		) ) );

		$received = null;
		$this->add_action_with_cleanup(
			'mission_stripe_charges_enabled',
			static function ( $account_id ) use ( &$received ) {
				$received = $account_id;
			}
		);

		( new AccountUpdatedHandler() )->handle(
			array( 'charges_enabled' => true ),
			'acct_a'
		);

		$this->assertSame( 'acct_a', $received );
	}

	/**
	 * Events that omit charges_enabled are ignored. The handler only cares
	 * about that one field today.
	 */
	public function test_event_without_charges_enabled_is_ignored(): void {
		$this->settings->add_stripe_account( $this->make_account( array(
			'account_id'      => 'acct_a',
			'charges_enabled' => false,
		) ) );

		( new AccountUpdatedHandler() )->handle(
			array( 'details_submitted' => true ),
			'acct_a'
		);

		$this->assertFalse( $this->settings->get_stripe_account_by_id( 'acct_a' )['charges_enabled'] );
	}
}
