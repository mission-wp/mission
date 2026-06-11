<?php
/**
 * Shared access to GiveWP's database tables.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Migrators\GiveWP;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reads a foreign plugin's tables; no higher-level API exists.

defined( 'ABSPATH' ) || exit;

/**
 * Table names, existence checks, and batch meta loading for GiveWP data.
 * Works whether or not the GiveWP plugin is still active.
 */
class GiveWPSource {

	/**
	 * Cached table existence results keyed by table name.
	 *
	 * @var array<string, bool>
	 */
	private array $table_exists = [];

	/**
	 * Cached form ID => campaign ID junction map.
	 *
	 * @var array<int, int>|null
	 */
	private ?array $form_campaign_map = null;

	/**
	 * Get a fully-prefixed GiveWP table name.
	 *
	 * @param string $suffix Table suffix, e.g. 'donors' for wp_give_donors.
	 */
	public function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . 'give_' . $suffix;
	}

	/**
	 * Whether a GiveWP table exists.
	 *
	 * Probes with a suppressed-error SELECT instead of SHOW TABLES so it
	 * also works on SQLite-based WordPress.
	 *
	 * @param string $suffix Table suffix.
	 */
	public function table_exists( string $suffix ): bool {
		global $wpdb;

		$table = $this->table( $suffix );

		if ( isset( $this->table_exists[ $table ] ) ) {
			return $this->table_exists[ $table ];
		}

		$suppress = $wpdb->suppress_errors();
		$wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$exists = '' === $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		$wpdb->last_error = '';

		$this->table_exists[ $table ] = $exists;

		return $exists;
	}

	/**
	 * Whether GiveWP data is present on this site.
	 */
	public function is_available(): bool {
		return $this->table_exists( 'donors' );
	}

	/**
	 * Detected GiveWP version, or null.
	 */
	public function version(): ?string {
		if ( defined( 'GIVE_VERSION' ) ) {
			return GIVE_VERSION;
		}

		$version = get_option( 'give_version' );

		return is_string( $version ) && '' !== $version ? $version : null;
	}

	/**
	 * Whether the v4+ campaigns tables exist.
	 */
	public function has_campaigns(): bool {
		return $this->table_exists( 'campaigns' ) && $this->table_exists( 'campaign_forms' );
	}

	/**
	 * Get the form ID => campaign ID junction map (cached).
	 *
	 * @return array<int, int>
	 */
	public function form_campaign_map(): array {
		global $wpdb;

		if ( null !== $this->form_campaign_map ) {
			return $this->form_campaign_map;
		}

		$this->form_campaign_map = [];

		if ( $this->has_campaigns() ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT form_id, campaign_id FROM %i', $this->table( 'campaign_forms' ) ),
				ARRAY_A
			);

			foreach ( $rows ?: [] as $row ) {
				$this->form_campaign_map[ (int) $row['form_id'] ] = (int) $row['campaign_id'];
			}
		}

		return $this->form_campaign_map;
	}

	/**
	 * Batch-load donation meta for a set of donation IDs.
	 *
	 * @param int[]    $donation_ids Donation post IDs.
	 * @param string[] $meta_keys    Meta keys to load.
	 *
	 * @return array<int, array<string, string>> Map of donation ID => meta key => value.
	 */
	public function donation_meta( array $donation_ids, array $meta_keys ): array {
		return $this->load_meta( 'donationmeta', 'donation_id', $donation_ids, $meta_keys );
	}

	/**
	 * Batch-load donor meta for a set of donor IDs.
	 *
	 * @param int[]    $donor_ids Donor IDs.
	 * @param string[] $meta_keys Meta keys to load.
	 *
	 * @return array<int, array<string, string>> Map of donor ID => meta key => value.
	 */
	public function donor_meta( array $donor_ids, array $meta_keys ): array {
		return $this->load_meta( 'donormeta', 'donor_id', $donor_ids, $meta_keys );
	}

	/**
	 * Batch-load form meta for a set of form post IDs.
	 *
	 * @param int[]    $form_ids  Form post IDs.
	 * @param string[] $meta_keys Meta keys to load.
	 *
	 * @return array<int, array<string, string>> Map of form ID => meta key => value.
	 */
	public function form_meta( array $form_ids, array $meta_keys ): array {
		return $this->load_meta( 'formmeta', 'form_id', $form_ids, $meta_keys );
	}

	/**
	 * Batch-load meta rows from one of GiveWP's meta tables.
	 *
	 * @param string   $table_suffix GiveWP meta table suffix.
	 * @param string   $id_column    Object ID column name in that table.
	 * @param int[]    $object_ids   Object IDs to load.
	 * @param string[] $meta_keys    Meta keys to load.
	 *
	 * @return array<int, array<string, string>> Map of object ID => meta key => value.
	 */
	private function load_meta( string $table_suffix, string $id_column, array $object_ids, array $meta_keys ): array {
		global $wpdb;

		$object_ids = array_values( array_filter( array_map( 'intval', $object_ids ) ) );
		$meta_keys  = array_values( array_filter( $meta_keys ) );

		if ( empty( $object_ids ) || empty( $meta_keys ) ) {
			return [];
		}

		$id_placeholders  = implode( ', ', array_fill( 0, count( $object_ids ), '%d' ) );
		$key_placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

		$sql = "SELECT %i AS object_id, meta_key, meta_value FROM %i WHERE %i IN ( {$id_placeholders} ) AND meta_key IN ( {$key_placeholders} )";

		$prepare_args = array_merge(
			[ $id_column, $this->table( $table_suffix ), $id_column ],
			$object_ids,
			$meta_keys
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers use %i, values use counted %d/%s placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

		$map = [];
		foreach ( $rows ?: [] as $row ) {
			$map[ (int) $row['object_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}

		return $map;
	}
}
