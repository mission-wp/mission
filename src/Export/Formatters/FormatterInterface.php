<?php
/**
 * Export formatter interface.
 *
 * @package Mission
 */

namespace Mission\Export\Formatters;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for export format handlers.
 */
interface FormatterInterface {

	/**
	 * Format export data as a string.
	 *
	 * @param array<int, array{key: string, label: string, type: string}> $columns Column definitions.
	 * @param array<int, array<string, mixed>>                            $rows    Row data keyed by column key.
	 * @param string                                                      $type    Data type being exported.
	 *
	 * @return string Formatted output.
	 */
	public function format( array $columns, array $rows, string $type ): string;

	/**
	 * Get the MIME content type for this format.
	 *
	 * @return string
	 */
	public function get_content_type(): string;

	/**
	 * Get the file extension for this format.
	 *
	 * @return string
	 */
	public function get_extension(): string;
}
