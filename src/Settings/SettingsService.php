<?php
/**
 * Settings service — single source of truth for plugin settings.
 *
 * @package Mission
 */

namespace Mission\Settings;

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
	public const OPTION_NAME = 'mission_settings';

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
		do_action( 'mission_settings_updated', $updated, $values, $current );

		return $updated;
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
		return apply_filters( 'mission_settings_defaults', $defaults );
	}
}
