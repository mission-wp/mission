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
		$stored = get_option( self::OPTION_NAME, array() );

		return array_merge( $this->get_defaults(), is_array( $stored ) ? $stored : array() );
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
		$updated = array_merge( $current, $values );

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
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_defaults(): array {
		$defaults = array(
			'currency'                 => 'USD',
			'tip_enabled'              => true,
			'tip_default_percentage'   => 15,
			'stripe_publishable_key'   => '',
			'stripe_secret_key'        => '',
			'stripe_account_id'        => '',
			'stripe_connection_status' => 'disconnected',
			'email_from_name'          => get_bloginfo( 'name' ),
			'email_from_address'       => get_bloginfo( 'admin_email' ),
		);

		/**
		 * Filters the default plugin settings.
		 *
		 * @param array<string, mixed> $defaults Default settings.
		 */
		return apply_filters( 'mission_settings_defaults', $defaults );
	}
}
