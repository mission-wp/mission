<?php
/**
 * Registry of available source-plugin migrators.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration;

use MissionDP\Migration\Migrators\GiveWP\GiveWPMigrator;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the built-in migrators and the list of planned sources shown as
 * "coming soon" in the UI.
 */
class MigratorRegistry {

	/**
	 * Instantiated migrators keyed by ID.
	 *
	 * @var array<string, MigratorInterface>|null
	 */
	private ?array $migrators = null;

	/**
	 * Get all registered migrators keyed by ID.
	 *
	 * @return array<string, MigratorInterface>
	 */
	public function get_all(): array {
		if ( null === $this->migrators ) {
			$migrators = [
				new GiveWPMigrator(),
			];

			/**
			 * Filters the registered migration sources.
			 *
			 * @param MigratorInterface[] $migrators Migrator instances.
			 */
			$migrators = apply_filters( 'mission_migration_migrators', $migrators );

			$this->migrators = [];
			foreach ( $migrators as $migrator ) {
				if ( $migrator instanceof MigratorInterface ) {
					$this->migrators[ $migrator->get_id() ] = $migrator;
				}
			}
		}

		return $this->migrators;
	}

	/**
	 * Get one migrator by ID.
	 *
	 * @param string $id Source slug.
	 */
	public function get( string $id ): ?MigratorInterface {
		return $this->get_all()[ $id ] ?? null;
	}

	/**
	 * Sources planned but not yet implemented, shown disabled in the UI.
	 *
	 * @return array<int, array{id: string, name: string, description: string}>
	 */
	public function get_coming_soon(): array {
		return [
			[
				'id'          => 'charitable',
				'name'        => 'Charitable',
				'description' => __( 'Donors, donations, campaigns, recurring', 'mission-donation-platform' ),
			],
			[
				'id'          => 'donorbox',
				'name'        => 'Donorbox',
				'description' => __( 'Donors, donations, campaigns, plans', 'mission-donation-platform' ),
			],
		];
	}

	/**
	 * Build the payload for the REST sources endpoint.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_sources_payload(): array {
		$sources = [];

		foreach ( $this->get_all() as $migrator ) {
			$sources[] = [
				'id'          => $migrator->get_id(),
				'name'        => $migrator->get_name(),
				'description' => $migrator->get_description(),
				'available'   => $migrator->is_available(),
				'coming_soon' => false,
				'version'     => $migrator->get_source_version(),
			];
		}

		foreach ( $this->get_coming_soon() as $source ) {
			$sources[] = array_merge(
				$source,
				[
					'available'   => false,
					'coming_soon' => true,
					'version'     => null,
				]
			);
		}

		return $sources;
	}
}
