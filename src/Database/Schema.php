<?php
/**
 * Database schema definitions.
 *
 * @package Mission
 */

namespace Mission\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Schema class.
 */
class Schema {

	/**
	 * Get all table schemas.
	 *
	 * @return array<string, string> Array of table names to SQL CREATE statements.
	 */
	public function get_table_schemas(): array {
		// Table schemas will be defined here as the plugin is built out.
		return array();
	}

	/**
	 * Get all custom table names (fully prefixed).
	 *
	 * @return string[] Array of table names.
	 */
	public function get_table_names(): array {
		return array_keys( $this->get_table_schemas() );
	}
}
