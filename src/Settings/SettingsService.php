<?php
/**
 * Settings service — single source of truth for plugin settings.
 *
 * @package MissionDP
 */

namespace MissionDP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settings service class.
 */
class SettingsService {

	/**
	 * Option name in wp_options.
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'missiondp_settings';

	/**
	 * Get all settings, merged with defaults so new keys always resolve.
	 *
	 * @return array<string, mixed>
	 */
	public function get_all(): array {
		$stored = get_option( self::OPTION_NAME, [] );

		return array_replace_recursive( $this->get_defaults(), is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		$all = $this->get_all();

		return $all[ $key ] ?? $fallback;
	}

	/**
	 * Update settings (partial merge).
	 *
	 * @param array<string, mixed> $values Key-value pairs to update.
	 * @return array<string, mixed> The full settings array after update.
	 */
	public function update( array $values ): array {
		$current = $this->get_all();
		$updated = array_replace_recursive( $current, $values );

		update_option( self::OPTION_NAME, $updated );

		/**
		 * Fires after plugin settings are updated.
		 *
		 * @param array<string, mixed> $updated  Full settings after update.
		 * @param array<string, mixed> $values   Only the changed values.
		 * @param array<string, mixed> $current  Settings before update.
		 */
		do_action( 'missiondp_settings_updated', $updated, $values, $current );

		return $updated;
	}

	/**
	 * Get the list of connected Stripe accounts.
	 *
	 * Lazily migrates the legacy single-account flat keys into the new
	 * `stripe_accounts` array on first read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_stripe_accounts(): array {
		$accounts = $this->get( 'stripe_accounts', [] );

		if ( ! is_array( $accounts ) ) {
			$accounts = [];
		}

		if ( empty( $accounts ) && $this->get( 'stripe_account_id' ) ) {
			$accounts = [
				$this->build_legacy_account_record(),
			];

			update_option( self::OPTION_NAME, array_replace_recursive( $this->get_all(), [ 'stripe_accounts' => $accounts ] ) );
		}

		return $accounts;
	}

	/**
	 * Get the default Stripe account, or the first if none is flagged.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_default_stripe_account(): ?array {
		$accounts = $this->get_stripe_accounts();

		if ( empty( $accounts ) ) {
			return null;
		}

		foreach ( $accounts as $account ) {
			if ( ! empty( $account['is_default'] ) ) {
				return $account;
			}
		}

		return $accounts[0];
	}

	/**
	 * Find a connected account by its Stripe account ID.
	 *
	 * @param string $account_id Stripe account ID (acct_...).
	 * @return array<string, mixed>|null
	 */
	public function get_stripe_account_by_id( string $account_id ): ?array {
		if ( '' === $account_id ) {
			return null;
		}

		foreach ( $this->get_stripe_accounts() as $account ) {
			if ( ( $account['account_id'] ?? '' ) === $account_id ) {
				return $account;
			}
		}

		return null;
	}

	/**
	 * Append a connected Stripe account. Marks it default if it is the first.
	 *
	 * @param array<string, mixed> $account Account record.
	 * @return void
	 */
	public function add_stripe_account( array $account ): void {
		$accounts = $this->get_stripe_accounts();

		// Replace any existing record with the same account_id.
		$accounts = array_values(
			array_filter(
				$accounts,
				static fn( $a ) => ( $a['account_id'] ?? '' ) !== ( $account['account_id'] ?? '' )
			)
		);

		$account['is_default'] = empty( $accounts ) ? true : ! empty( $account['is_default'] );

		$accounts[] = $account;

		$this->write_stripe_accounts( $accounts );
	}

	/**
	 * Remove a connected Stripe account. Promotes another to default if needed.
	 *
	 * @param string $account_id Stripe account ID.
	 * @return void
	 */
	public function remove_stripe_account( string $account_id ): void {
		$accounts    = $this->get_stripe_accounts();
		$was_default = false;
		$remaining   = [];

		foreach ( $accounts as $account ) {
			if ( ( $account['account_id'] ?? '' ) === $account_id ) {
				$was_default = ! empty( $account['is_default'] );
				continue;
			}
			$remaining[] = $account;
		}

		if ( $was_default && ! empty( $remaining ) ) {
			$remaining[0]['is_default'] = true;
		}

		$this->write_stripe_accounts( $remaining );
	}

	/**
	 * Mark the given account as the default.
	 *
	 * @param string $account_id Stripe account ID.
	 * @return bool True if the default changed, false if the account was not found.
	 */
	public function set_default_stripe_account( string $account_id ): bool {
		$accounts = $this->get_stripe_accounts();
		$found    = false;

		foreach ( $accounts as &$account ) {
			$is_match              = ( $account['account_id'] ?? '' ) === $account_id;
			$account['is_default'] = $is_match;
			if ( $is_match ) {
				$found = true;
			}
		}
		unset( $account );

		if ( ! $found ) {
			return false;
		}

		$this->write_stripe_accounts( $accounts );

		return true;
	}

	/**
	 * Remove every connected Stripe account.
	 *
	 * @return void
	 */
	public function clear_stripe_accounts(): void {
		$this->write_stripe_accounts( [] );
	}

	/**
	 * Merge updates into a connected Stripe account record.
	 *
	 * @param string               $account_id Stripe account ID.
	 * @param array<string, mixed> $fields     Fields to merge into the account record.
	 * @return bool True if the account was found and updated.
	 */
	public function update_stripe_account( string $account_id, array $fields ): bool {
		$accounts = $this->get_stripe_accounts();
		$found    = false;

		foreach ( $accounts as &$account ) {
			if ( ( $account['account_id'] ?? '' ) === $account_id ) {
				$account = array_replace( $account, $fields );
				$found   = true;
				break;
			}
		}
		unset( $account );

		if ( ! $found ) {
			return false;
		}

		$this->write_stripe_accounts( $accounts );

		return true;
	}

	/**
	 * Persist the stripe_accounts list, bypassing the recursive merge in update().
	 *
	 * Recursive merge keeps stale indexes when the list shrinks, so we write the
	 * option directly. Also syncs the legacy flat keys from the new default.
	 *
	 * @param array<int, array<string, mixed>> $accounts Accounts list.
	 * @return void
	 */
	private function write_stripe_accounts( array $accounts ): void {
		$current = $this->get_all();
		$legacy  = $this->derive_legacy_keys_from( $accounts );

		$updated                    = array_replace( $current, $legacy );
		$updated['stripe_accounts'] = array_values( $accounts );

		update_option( self::OPTION_NAME, $updated );

		$changed = array_merge( [ 'stripe_accounts' => $updated['stripe_accounts'] ], $legacy );

		/**
		 * Fires after plugin settings are updated.
		 *
		 * @param array<string, mixed> $updated Full settings after update.
		 * @param array<string, mixed> $changed Only the changed values.
		 * @param array<string, mixed> $current Settings before update.
		 */
		do_action( 'missiondp_settings_updated', $updated, $changed, $current );
	}

	/**
	 * Derive the legacy single-account flat keys from the default account
	 * in the given accounts list, so consumers that haven't migrated to
	 * stripe_accounts keep working.
	 *
	 * @param array<int, array<string, mixed>> $accounts Accounts list.
	 * @return array<string, mixed>
	 */
	private function derive_legacy_keys_from( array $accounts ): array {
		$default = null;
		foreach ( $accounts as $account ) {
			if ( ! empty( $account['is_default'] ) ) {
				$default = $account;
				break;
			}
		}
		$default ??= $accounts[0] ?? null;

		if ( ! $default ) {
			return [
				'stripe_site_id'           => '',
				'stripe_site_token'        => '',
				'stripe_account_id'        => '',
				'stripe_display_name'      => '',
				'stripe_connection_status' => 'disconnected',
				'stripe_charges_enabled'   => false,
				'stripe_webhook_secret'    => '',
			];
		}

		return [
			'stripe_site_id'           => (string) ( $default['site_id'] ?? '' ),
			'stripe_site_token'        => (string) ( $default['site_token'] ?? '' ),
			'stripe_account_id'        => (string) ( $default['account_id'] ?? '' ),
			'stripe_display_name'      => (string) ( $default['display_name'] ?? '' ),
			'stripe_connection_status' => (string) ( $default['connection_status'] ?? 'connected' ),
			'stripe_charges_enabled'   => ! empty( $default['charges_enabled'] ),
			'stripe_webhook_secret'    => (string) ( $default['webhook_secret'] ?? '' ),
		];
	}

	/**
	 * Resolve the site_token for a given account ID, falling back to the default.
	 *
	 * @param string $account_id Optional Stripe account ID.
	 * @return string Empty string if no account matches.
	 */
	public function resolve_site_token( string $account_id = '' ): string {
		if ( '' !== $account_id ) {
			$account = $this->get_stripe_account_by_id( $account_id );
			if ( $account && ! empty( $account['site_token'] ) ) {
				return (string) $account['site_token'];
			}
		}

		$default = $this->get_default_stripe_account();
		if ( $default && ! empty( $default['site_token'] ) ) {
			return (string) $default['site_token'];
		}

		// Legacy single-account fallback.
		return (string) $this->get( 'stripe_site_token', '' );
	}

	/**
	 * Sanitized account list for REST and JS contexts (no tokens or secrets).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_stripe_accounts_public(): array {
		return array_map(
			static fn( $account ) => [
				'account_id'        => $account['account_id'] ?? '',
				'display_name'      => $account['display_name'] ?? '',
				'connection_status' => $account['connection_status'] ?? 'connected',
				'charges_enabled'   => ! empty( $account['charges_enabled'] ),
				'is_default'        => ! empty( $account['is_default'] ),
				'connected_at'      => $account['connected_at'] ?? '',
			],
			$this->get_stripe_accounts()
		);
	}

	/**
	 * Build an account record from the legacy single-account flat keys.
	 *
	 * @return array<string, mixed>
	 */
	private function build_legacy_account_record(): array {
		return [
			'site_id'           => (string) $this->get( 'stripe_site_id', '' ),
			'site_token'        => (string) $this->get( 'stripe_site_token', '' ),
			'account_id'        => (string) $this->get( 'stripe_account_id', '' ),
			'display_name'      => (string) $this->get( 'stripe_display_name', '' ),
			'connection_status' => (string) $this->get( 'stripe_connection_status', 'connected' ),
			'charges_enabled'   => (bool) $this->get( 'stripe_charges_enabled', false ),
			'webhook_secret'    => (string) $this->get( 'stripe_webhook_secret', '' ),
			'is_default'        => true,
			'connected_at'      => gmdate( 'c' ),
		];
	}

	/**
	 * Format the organization address as a single display string.
	 *
	 * Composes a comma-separated address from the structured address settings,
	 * skipping any empty parts.
	 *
	 * @return string e.g. "123 Main St, Springfield, IL 62701"
	 */
	public function format_org_address(): string {
		return implode(
			', ',
			array_filter(
				[
					$this->get( 'org_street', '' ),
					$this->get( 'org_city', '' ),
					trim( $this->get( 'org_state', '' ) . ' ' . $this->get( 'org_zip', '' ) ),
				]
			)
		);
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_defaults(): array {
		$defaults = [
			'currency'                 => 'USD',
			'primary_color'            => '#2fa36b',
			'show_powered_by'          => false,
			'test_mode'                => true,
			// Connected Stripe accounts. Each record:
			// site_id, site_token, account_id, display_name, connection_status,
			// charges_enabled, webhook_secret, is_default, connected_at.
			'stripe_accounts'          => [],
			// Legacy single-account fields, kept for backward-compat reads only.
			// New code reads/writes stripe_accounts; these are migrated on first read.
			'stripe_site_id'           => '',
			'stripe_site_token'        => '',
			'stripe_account_id'        => '',
			'stripe_connection_status' => 'disconnected',
			'stripe_display_name'      => '',
			'stripe_charges_enabled'   => false,
			'stripe_webhook_secret'    => '',
			'stripe_fee_percent'       => 2.9,
			'stripe_fee_fixed'         => 30,
			'org_name'                 => get_bloginfo( 'name' ),
			'org_street'               => '',
			'org_city'                 => '',
			'org_state'                => '',
			'org_zip'                  => '',
			'org_country'              => 'US',
			'org_ein'                  => '',
			'onboarding_completed'     => false,
			'delete_data_on_uninstall' => false,
			'email_from_name'          => get_bloginfo( 'name' ),
			'email_from_address'       => get_bloginfo( 'admin_email' ),
			'email_reply_to'           => '',
			'donor_portal_enabled'     => true,
			'portal_features'          => [
				'donation_history'   => true,
				'manage_recurring'   => true,
				'update_payment'     => true,
				'profile_editing'    => true,
				'annual_tax_summary' => true,
			],
			'emails'                   => [
				'donation_receipt'             => [
					'enabled' => true,
					'subject' => '',
					'body'    => '',
				],
				'subscription_activated'       => [
					'enabled' => true,
					'subject' => '',
					'body'    => '',
				],
				'renewal_receipt'              => [
					'enabled' => true,
					'subject' => '',
					'body'    => '',
				],
				'payment_failed'               => [
					'enabled' => true,
					'subject' => '',
					'body'    => '',
				],
				'subscription_cancelled'       => [
					'enabled' => true,
					'subject' => '',
					'body'    => '',
				],
				'account_activation'           => [
					'enabled' => true,
					'subject' => '',
					'body'    => '',
				],
				'password_reset'               => [
					'enabled' => true,
					'subject' => '',
					'body'    => '',
				],
				'email_change_verification'    => [
					'enabled' => true,
					'subject' => '',
					'body'    => '',
				],
				'donor_note'                   => [
					'enabled' => true,
					'subject' => '',
					'body'    => '',
				],
				'tribute_notification'         => [
					'enabled' => true,
					'subject' => '',
					'body'    => '',
				],

				// Admin notifications — no subject/body (system-generated), just enabled + recipients.
				'admin_new_donation'           => [
					'enabled'    => true,
					'recipients' => [],
				],
				'admin_recurring_renewal'      => [
					'enabled'    => true,
					'recipients' => [],
				],
				'admin_refund'                 => [
					'enabled'    => true,
					'recipients' => [],
				],
				'admin_payment_failed'         => [
					'enabled'    => true,
					'recipients' => [],
				],
				'admin_subscription_cancelled' => [
					'enabled'    => true,
					'recipients' => [],
				],
				'admin_milestone'              => [
					'enabled'    => true,
					'recipients' => [],
				],
				'admin_mail_dedication'        => [
					'enabled'    => true,
					'recipients' => [],
				],
			],
		];

		/**
		 * Filters the default plugin settings.
		 *
		 * @param array<string, mixed> $defaults Default settings.
		 */
		return apply_filters( 'missiondp_settings_defaults', $defaults );
	}
}
