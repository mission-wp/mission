<?php
/**
 * Tests for the SettingsService class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Settings;

use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * SettingsService test class.
 */
class SettingsServiceTest extends WP_UnitTestCase {

	/**
	 * Service instance.
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->service = new SettingsService();
		delete_option( SettingsService::OPTION_NAME );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( SettingsService::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Test get_all returns defaults when no option exists.
	 */
	public function test_get_all_returns_defaults_when_no_option(): void {
		$all = $this->service->get_all();

		$this->assertSame( 'USD', $all['currency'] );
		$this->assertSame( '', $all['stripe_site_id'] );
		$this->assertSame( '', $all['stripe_site_token'] );
		$this->assertSame( 'disconnected', $all['stripe_connection_status'] );
		$this->assertSame( '', $all['stripe_display_name'] );
		$this->assertTrue( $all['test_mode'] );
	}

	/**
	 * Test get_all merges stored settings with defaults.
	 */
	public function test_get_all_merges_stored_with_defaults(): void {
		update_option( SettingsService::OPTION_NAME, array( 'currency' => 'EUR' ) );

		$all = $this->service->get_all();

		$this->assertSame( 'EUR', $all['currency'] );
		// Defaults still present for keys not in stored option.
		$this->assertSame( '', $all['stripe_site_id'] );
	}

	/**
	 * Test get returns a single setting value.
	 */
	public function test_get_returns_single_setting(): void {
		update_option( SettingsService::OPTION_NAME, array( 'currency' => 'GBP' ) );

		$this->assertSame( 'GBP', $this->service->get( 'currency' ) );
	}

	/**
	 * Test get returns default for unknown key.
	 */
	public function test_get_returns_default_for_unknown_key(): void {
		$this->assertSame( 'fallback', $this->service->get( 'nonexistent', 'fallback' ) );
	}

	/**
	 * Test update merges and saves settings.
	 */
	public function test_update_merges_and_saves(): void {
		$result = $this->service->update( array( 'currency' => 'CAD' ) );

		$this->assertSame( 'CAD', $result['currency'] );
		$this->assertSame( '', $result['stripe_site_id'] );

		// Verify persisted to database.
		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertSame( 'CAD', $stored['currency'] );
	}

	/**
	 * Test the default fixed fee follows the stored currency.
	 */
	public function test_get_all_scales_default_fixed_fee_for_currency(): void {
		update_option( SettingsService::OPTION_NAME, array( 'currency' => 'KWD' ) );

		$this->assertSame( 300, $this->service->get( 'stripe_fee_fixed' ) );

		update_option( SettingsService::OPTION_NAME, array( 'currency' => 'JPY' ) );

		$this->assertSame( 0, $this->service->get( 'stripe_fee_fixed' ) );
	}

	/**
	 * Test a stored fixed fee is not overridden by the currency default.
	 */
	public function test_get_all_prefers_stored_fixed_fee(): void {
		update_option(
			SettingsService::OPTION_NAME,
			array(
				'currency'         => 'JPY',
				'stripe_fee_fixed' => 50,
			)
		);

		$this->assertSame( 50, $this->service->get( 'stripe_fee_fixed' ) );
	}

	/**
	 * Test a currency change resets the fixed fee to the new currency's default.
	 */
	public function test_update_currency_change_resets_fixed_fee(): void {
		update_option(
			SettingsService::OPTION_NAME,
			array(
				'currency'         => 'USD',
				'stripe_fee_fixed' => 30,
			)
		);

		$result = $this->service->update( array( 'currency' => 'JPY' ) );

		$this->assertSame( 0, $result['stripe_fee_fixed'] );
	}

	/**
	 * Test an explicit fixed fee in the same update is not reset.
	 */
	public function test_update_currency_change_keeps_explicit_fixed_fee(): void {
		update_option(
			SettingsService::OPTION_NAME,
			array(
				'currency'         => 'USD',
				'stripe_fee_fixed' => 30,
			)
		);

		$result = $this->service->update(
			array(
				'currency'         => 'KWD',
				'stripe_fee_fixed' => 250,
			)
		);

		$this->assertSame( 250, $result['stripe_fee_fixed'] );
	}

	/**
	 * Test update fires action hook.
	 */
	public function test_update_fires_action_hook(): void {
		$fired = false;

		add_action(
			'mission_settings_updated',
			static function ( $updated, $values, $previous ) use ( &$fired ) {
				$fired = true;
			},
			10,
			3
		);

		$this->service->update( array( 'currency' => 'JPY' ) );

		$this->assertTrue( $fired );
	}

	/**
	 * Test get_defaults is filterable.
	 */
	public function test_get_defaults_is_filterable(): void {
		add_filter(
			'mission_settings_defaults',
			static function ( $defaults ) {
				$defaults['custom_setting'] = 'custom_value';
				return $defaults;
			}
		);

		$defaults = $this->service->get_defaults();

		$this->assertSame( 'custom_value', $defaults['custom_setting'] );

		remove_all_filters( 'mission_settings_defaults' );
	}

	// =========================================================================
	// Multi-account Stripe storage
	// =========================================================================

	/**
	 * Helper to build a stripe account record.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function make_account( array $overrides = array() ): array {
		return array_merge(
			array(
				'site_id'           => 'site_a',
				'site_token'        => 'tok_a',
				'account_id'        => 'acct_a',
				'display_name'      => 'Account A',
				'connection_status' => 'connected',
				'charges_enabled'   => true,
				'webhook_secret'    => 'whsec_a',
				'connected_at'      => gmdate( 'c' ),
			),
			$overrides
		);
	}

	/**
	 * First account added becomes the default automatically.
	 */
	public function test_add_stripe_account_first_becomes_default(): void {
		$this->service->add_stripe_account( $this->make_account() );

		$accounts = $this->service->get_stripe_accounts();

		$this->assertCount( 1, $accounts );
		$this->assertTrue( $accounts[0]['is_default'] );
	}

	/**
	 * Second account added does NOT automatically become default.
	 */
	public function test_add_stripe_account_second_does_not_become_default(): void {
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_a' ) ) );
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_b', 'site_token' => 'tok_b' ) ) );

		$accounts = $this->service->get_stripe_accounts();
		$default  = $this->service->get_default_stripe_account();

		$this->assertCount( 2, $accounts );
		$this->assertSame( 'acct_a', $default['account_id'] );
	}

	/**
	 * Re-adding an account with the same ID replaces the existing record.
	 */
	public function test_add_stripe_account_deduplicates_by_account_id(): void {
		$this->service->add_stripe_account( $this->make_account( array( 'display_name' => 'Old Name' ) ) );
		$this->service->add_stripe_account( $this->make_account( array( 'display_name' => 'New Name' ) ) );

		$accounts = $this->service->get_stripe_accounts();

		$this->assertCount( 1, $accounts );
		$this->assertSame( 'New Name', $accounts[0]['display_name'] );
	}

	/**
	 * Regression: remove_stripe_account actually removes the record.
	 *
	 * Caught a production bug where SettingsService::update() used
	 * array_replace_recursive, which merged the new (shorter) accounts list
	 * with the old one by index — leaving the "removed" account in place.
	 */
	public function test_remove_stripe_account_actually_removes(): void {
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_a' ) ) );
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_b', 'site_token' => 'tok_b' ) ) );

		$this->service->remove_stripe_account( 'acct_b' );

		$accounts = $this->service->get_stripe_accounts();

		$this->assertCount( 1, $accounts );
		$this->assertSame( 'acct_a', $accounts[0]['account_id'] );
	}

	/**
	 * Removing the default account promotes another to default.
	 */
	public function test_remove_default_promotes_another(): void {
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_a' ) ) );
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_b', 'site_token' => 'tok_b' ) ) );

		$this->service->remove_stripe_account( 'acct_a' );

		$default = $this->service->get_default_stripe_account();

		$this->assertSame( 'acct_b', $default['account_id'] );
		$this->assertTrue( $default['is_default'] );
	}

	/**
	 * Removing a non-default account leaves the existing default in place.
	 */
	public function test_remove_non_default_leaves_default_intact(): void {
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_a' ) ) );
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_b', 'site_token' => 'tok_b' ) ) );

		$this->service->remove_stripe_account( 'acct_b' );

		$default = $this->service->get_default_stripe_account();

		$this->assertSame( 'acct_a', $default['account_id'] );
	}

	/**
	 * set_default_stripe_account marks exactly one account as default.
	 */
	public function test_set_default_marks_exactly_one(): void {
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_a' ) ) );
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_b', 'site_token' => 'tok_b' ) ) );

		$result = $this->service->set_default_stripe_account( 'acct_b' );

		$accounts     = $this->service->get_stripe_accounts();
		$default_ids  = array_filter( $accounts, static fn( $a ) => ! empty( $a['is_default'] ) );

		$this->assertTrue( $result );
		$this->assertCount( 1, $default_ids );
		$this->assertSame( 'acct_b', $this->service->get_default_stripe_account()['account_id'] );
	}

	/**
	 * set_default_stripe_account returns false for unknown ID.
	 */
	public function test_set_default_returns_false_for_unknown(): void {
		$this->service->add_stripe_account( $this->make_account() );

		$this->assertFalse( $this->service->set_default_stripe_account( 'acct_does_not_exist' ) );

		// Original default still intact.
		$this->assertSame( 'acct_a', $this->service->get_default_stripe_account()['account_id'] );
	}

	/**
	 * update_stripe_account merges fields into the existing record.
	 */
	public function test_update_stripe_account_merges_fields(): void {
		$this->service->add_stripe_account( $this->make_account( array( 'charges_enabled' => false ) ) );

		$result = $this->service->update_stripe_account( 'acct_a', array( 'charges_enabled' => true ) );

		$account = $this->service->get_stripe_account_by_id( 'acct_a' );

		$this->assertTrue( $result );
		$this->assertTrue( $account['charges_enabled'] );
		// Other fields preserved.
		$this->assertSame( 'tok_a', $account['site_token'] );
		$this->assertSame( 'Account A', $account['display_name'] );
	}

	/**
	 * update_stripe_account returns false for unknown ID.
	 */
	public function test_update_stripe_account_returns_false_for_unknown(): void {
		$this->assertFalse( $this->service->update_stripe_account( 'acct_missing', array( 'charges_enabled' => true ) ) );
	}

	/**
	 * clear_stripe_accounts wipes the list and clears the legacy flat keys.
	 */
	public function test_clear_stripe_accounts_clears_legacy_keys(): void {
		$this->service->add_stripe_account( $this->make_account() );

		$this->service->clear_stripe_accounts();

		$this->assertSame( array(), $this->service->get_stripe_accounts() );
		$this->assertSame( '', $this->service->get( 'stripe_site_token' ) );
		$this->assertSame( '', $this->service->get( 'stripe_account_id' ) );
		$this->assertSame( '', $this->service->get( 'stripe_webhook_secret' ) );
		$this->assertSame( 'disconnected', $this->service->get( 'stripe_connection_status' ) );
	}

	/**
	 * After every mutation, the legacy flat keys mirror the default account.
	 *
	 * Consumers that haven't been migrated to stripe_accounts (e.g. the donation
	 * form render which reads stripe_charges_enabled directly) rely on this.
	 */
	public function test_legacy_keys_track_default_account(): void {
		$this->service->add_stripe_account( $this->make_account( array(
			'account_id'      => 'acct_a',
			'site_token'      => 'tok_a',
			'webhook_secret'  => 'whsec_a',
			'charges_enabled' => true,
			'display_name'    => 'A',
		) ) );

		$this->assertSame( 'tok_a', $this->service->get( 'stripe_site_token' ) );
		$this->assertSame( 'acct_a', $this->service->get( 'stripe_account_id' ) );
		$this->assertSame( 'whsec_a', $this->service->get( 'stripe_webhook_secret' ) );
		$this->assertTrue( $this->service->get( 'stripe_charges_enabled' ) );
		$this->assertSame( 'A', $this->service->get( 'stripe_display_name' ) );

		// Add a second account and switch default to it — flat keys should follow.
		$this->service->add_stripe_account( $this->make_account( array(
			'account_id'      => 'acct_b',
			'site_token'      => 'tok_b',
			'webhook_secret'  => 'whsec_b',
			'charges_enabled' => false,
			'display_name'    => 'B',
		) ) );
		$this->service->set_default_stripe_account( 'acct_b' );

		$this->assertSame( 'tok_b', $this->service->get( 'stripe_site_token' ) );
		$this->assertSame( 'acct_b', $this->service->get( 'stripe_account_id' ) );
		$this->assertSame( 'whsec_b', $this->service->get( 'stripe_webhook_secret' ) );
		$this->assertFalse( $this->service->get( 'stripe_charges_enabled' ) );
	}

	/**
	 * resolve_site_token returns the token of the named account when present.
	 */
	public function test_resolve_site_token_honors_explicit_account_id(): void {
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_a', 'site_token' => 'tok_a' ) ) );
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_b', 'site_token' => 'tok_b' ) ) );

		$this->assertSame( 'tok_b', $this->service->resolve_site_token( 'acct_b' ) );
	}

	/**
	 * resolve_site_token falls back to the default account for empty input.
	 */
	public function test_resolve_site_token_falls_back_to_default_for_empty(): void {
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_a', 'site_token' => 'tok_a' ) ) );
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_b', 'site_token' => 'tok_b' ) ) );

		$this->assertSame( 'tok_a', $this->service->resolve_site_token( '' ) );
	}

	/**
	 * resolve_site_token falls back to the default when account_id is unknown.
	 *
	 * This is the security-relevant fallback: a donor passing a foreign acct_xxx
	 * must NOT cause us to attempt to use an attacker-controlled identifier.
	 */
	public function test_resolve_site_token_falls_back_on_unknown_id(): void {
		$this->service->add_stripe_account( $this->make_account( array( 'account_id' => 'acct_a', 'site_token' => 'tok_a' ) ) );

		$this->assertSame( 'tok_a', $this->service->resolve_site_token( 'acct_unknown_xxx' ) );
	}

	/**
	 * resolve_site_token falls back to the legacy flat key when no accounts exist.
	 */
	public function test_resolve_site_token_falls_back_to_legacy_key(): void {
		update_option(
			SettingsService::OPTION_NAME,
			array(
				'stripe_site_token' => 'legacy_tok',
				'stripe_accounts'   => array(),
			)
		);

		$this->assertSame( 'legacy_tok', $this->service->resolve_site_token( '' ) );
	}

	/**
	 * Lazy migration: legacy single-account settings get promoted into the
	 * stripe_accounts array on first read.
	 */
	public function test_lazy_migration_of_legacy_single_account(): void {
		update_option(
			SettingsService::OPTION_NAME,
			array(
				'stripe_site_id'           => 'site_legacy',
				'stripe_site_token'        => 'tok_legacy',
				'stripe_account_id'        => 'acct_legacy',
				'stripe_display_name'      => 'Legacy Org',
				'stripe_connection_status' => 'connected',
				'stripe_charges_enabled'   => true,
				'stripe_webhook_secret'    => 'whsec_legacy',
			)
		);

		$accounts = $this->service->get_stripe_accounts();

		$this->assertCount( 1, $accounts );
		$this->assertSame( 'acct_legacy', $accounts[0]['account_id'] );
		$this->assertSame( 'tok_legacy', $accounts[0]['site_token'] );
		$this->assertSame( 'whsec_legacy', $accounts[0]['webhook_secret'] );
		$this->assertTrue( $accounts[0]['charges_enabled'] );
		$this->assertTrue( $accounts[0]['is_default'] );

		// Migration is persisted, not just computed.
		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertCount( 1, $stored['stripe_accounts'] );
	}

	/**
	 * Migration must NOT fire if only `stripe_account_id` is set without a
	 * site_token. A multi-step legacy configuration can transiently hit
	 * this state (account_id written, token written next) and migrating
	 * early would lock in an account record with an empty site_token that
	 * the subsequent token write cannot repair.
	 */
	public function test_lazy_migration_skips_when_token_is_missing(): void {
		update_option(
			SettingsService::OPTION_NAME,
			array(
				'stripe_site_token'        => '',
				'stripe_account_id'        => 'acct_partial',
				'stripe_connection_status' => 'connected',
			)
		);

		$accounts = $this->service->get_stripe_accounts();

		$this->assertSame( array(), $accounts );

		// Once the token is also set, migration fires on next read.
		update_option(
			SettingsService::OPTION_NAME,
			array_merge(
				get_option( SettingsService::OPTION_NAME ),
				array( 'stripe_site_token' => 'tok_finally_here' )
			)
		);

		$accounts = $this->service->get_stripe_accounts();

		$this->assertCount( 1, $accounts );
		$this->assertSame( 'tok_finally_here', $accounts[0]['site_token'] );
		$this->assertSame( 'acct_partial', $accounts[0]['account_id'] );
	}

	/**
	 * Legacy migration also fires when only `stripe_site_token` is set (no
	 * account_id). Old connect responses occasionally left account_id empty;
	 * the upstream API routed by site_token alone in that case, so we need
	 * to preserve that path post-migration.
	 */
	public function test_lazy_migration_when_only_site_token_is_set(): void {
		update_option(
			SettingsService::OPTION_NAME,
			array(
				'stripe_site_id'           => 'site_legacy',
				'stripe_site_token'        => 'tok_legacy_only',
				'stripe_account_id'        => '',
				'stripe_display_name'      => '',
				'stripe_connection_status' => 'connected',
				'stripe_charges_enabled'   => true,
				'stripe_webhook_secret'    => 'whsec_legacy',
			)
		);

		$accounts = $this->service->get_stripe_accounts();

		$this->assertCount( 1, $accounts );
		$this->assertSame( 'tok_legacy_only', $accounts[0]['site_token'] );
		$this->assertSame( '', $accounts[0]['account_id'] );
		$this->assertTrue( $accounts[0]['is_default'] );

		// Resolving the token still works (this is the main reason the
		// fallback exists).
		$this->assertSame( 'tok_legacy_only', $this->service->resolve_site_token( '' ) );
	}

	/**
	 * get_stripe_accounts_public strips tokens and webhook secrets.
	 */
	public function test_get_stripe_accounts_public_strips_secrets(): void {
		$this->service->add_stripe_account( $this->make_account() );

		$public = $this->service->get_stripe_accounts_public();

		$this->assertCount( 1, $public );
		$this->assertArrayNotHasKey( 'site_token', $public[0] );
		$this->assertArrayNotHasKey( 'webhook_secret', $public[0] );
		$this->assertArrayNotHasKey( 'site_id', $public[0] );
		$this->assertSame( 'acct_a', $public[0]['account_id'] );
		$this->assertSame( 'Account A', $public[0]['display_name'] );
	}
}
