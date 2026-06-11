<?php
/**
 * Contract for source-plugin migrators.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * A migrator knows how to detect one source plugin, scan its data, and read
 * it out in batches as normalized records that the source-agnostic writers
 * understand.
 *
 * Normalized records are associative arrays of Mission model fields plus:
 * - `source_id` (int, required): the source row ID, used for cursoring and
 *   idempotency stamping.
 * - `meta` (array, optional): extra meta key/value pairs to store.
 * - resolution hints (`source_donor_id`, `source_campaign_id`,
 *   `source_subscription_id`, `source_parent_id`, `campaign_title`) that the
 *   writers resolve to Mission IDs via the source-ID meta maps.
 */
interface MigratorInterface {

	/**
	 * Unique source slug (e.g. 'givewp').
	 */
	public function get_id(): string;

	/**
	 * Human-readable source name (e.g. 'GiveWP').
	 */
	public function get_name(): string;

	/**
	 * Short description of what gets migrated, for the source list.
	 */
	public function get_description(): string;

	/**
	 * Whether the source plugin's data is present on this site.
	 *
	 * Should detect data (tables/options), not just an active plugin, so a
	 * migration still works after the source plugin was deactivated.
	 */
	public function is_available(): bool;

	/**
	 * Detected source plugin version, or null if unknown.
	 */
	public function get_source_version(): ?string;

	/**
	 * Run the pre-flight scan.
	 *
	 * @param array<string, mixed> $options Migration options (e.g. include_test).
	 *
	 * @return array{
	 *     checks: array<int, array{id: string, label: string, status: string}>,
	 *     counts: array<string, int>,
	 *     warnings: string[]
	 * }
	 */
	public function scan( array $options ): array;

	/**
	 * Entity phases in processing order (e.g. campaigns, donors, subscriptions, transactions).
	 *
	 * @return string[]
	 */
	public function get_phases(): array;

	/**
	 * Count migratable items for one entity phase.
	 *
	 * Must use the same filters as read_batch() so progress totals reconcile.
	 *
	 * @param string               $entity  Entity phase name.
	 * @param array<string, mixed> $options Migration options.
	 */
	public function count_items( string $entity, array $options ): int;

	/**
	 * Read the next batch of normalized records for an entity phase.
	 *
	 * Cursor-based: returns records with source_id > $after_id in ascending
	 * source_id order. An empty return means the phase is done.
	 *
	 * @param string               $entity   Entity phase name.
	 * @param int                  $after_id Last processed source ID.
	 * @param int                  $limit    Max records to return.
	 * @param array<string, mixed> $options  Migration options.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function read_batch( string $entity, int $after_id, int $limit, array $options ): array;
}
