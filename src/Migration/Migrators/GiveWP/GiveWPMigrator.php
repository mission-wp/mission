<?php
/**
 * GiveWP migrator.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Migrators\GiveWP;

use MissionDP\Database\DatabaseModule;
use MissionDP\Migration\MigratorInterface;
use MissionDP\Migration\Migrators\GiveWP\Readers\CampaignReader;
use MissionDP\Migration\Migrators\GiveWP\Readers\DonorReader;
use MissionDP\Migration\Migrators\GiveWP\Readers\PaymentReader;
use MissionDP\Migration\Migrators\GiveWP\Readers\SubscriptionReader;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Detects GiveWP data and reads it out as normalized records. Detection is
 * table-based so migration still works after GiveWP was deactivated.
 */
class GiveWPMigrator implements MigratorInterface {

	private GiveWPSource $source;

	/**
	 * Readers keyed by entity phase.
	 *
	 * @var array<string, CampaignReader|DonorReader|SubscriptionReader|PaymentReader>|null
	 */
	private ?array $readers = null;

	/**
	 * Constructor.
	 *
	 * @param GiveWPSource|null $source Source DB access (injectable for tests).
	 */
	public function __construct( ?GiveWPSource $source = null ) {
		$this->source = $source ?? new GiveWPSource();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'givewp';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'GiveWP';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Donors, donations, campaigns, subscriptions', 'mission-donation-platform' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return $this->source->is_available();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_source_version(): ?string {
		return $this->source->version();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_phases(): array {
		return [ 'campaigns', 'donors', 'subscriptions', 'transactions' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function scan( array $options ): array {
		$counts = [];
		foreach ( $this->get_phases() as $entity ) {
			$counts[ $entity ] = $this->count_items( $entity, $options );
		}

		/** @var PaymentReader $payment_reader */
		$payment_reader = $this->readers()['transactions'];
		/** @var SubscriptionReader $subscription_reader */
		$subscription_reader = $this->readers()['subscriptions'];

		$counts['test_transactions']  = $payment_reader->count_test_mode();
		$counts['test_subscriptions'] = $subscription_reader->count_test_mode();

		return [
			'checks'   => $this->build_checks(),
			'counts'   => $counts,
			'warnings' => $this->build_warnings(),
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function count_items( string $entity, array $options ): int {
		$reader = $this->readers()[ $entity ] ?? null;

		return $reader ? $reader->count( $options ) : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function read_batch( string $entity, int $after_id, int $limit, array $options ): array {
		$reader = $this->readers()[ $entity ] ?? null;

		return $reader ? $reader->read_batch( $after_id, $limit, $options ) : [];
	}

	/**
	 * Lazily build the entity readers.
	 *
	 * @return array<string, CampaignReader|DonorReader|SubscriptionReader|PaymentReader>
	 */
	private function readers(): array {
		if ( null === $this->readers ) {
			$currency = (string) ( new SettingsService() )->get( 'currency', 'USD' );

			$this->readers = [
				'campaigns'     => new CampaignReader( $this->source, $currency ),
				'donors'        => new DonorReader( $this->source ),
				'subscriptions' => new SubscriptionReader( $this->source, $currency ),
				'transactions'  => new PaymentReader( $this->source, $currency ),
			];
		}

		return $this->readers;
	}

	/**
	 * Build the system status checks for the scan results screen.
	 *
	 * @return array<int, array{id: string, label: string, status: string}>
	 */
	private function build_checks(): array {
		$version = $this->get_source_version();

		$checks = [
			[
				'id'     => 'source',
				'label'  => $version
					/* translators: %s: GiveWP version number. */
					? sprintf( __( 'GiveWP %s detected', 'mission-donation-platform' ), $version )
					: __( 'GiveWP data detected', 'mission-donation-platform' ),
				'status' => 'ok',
			],
			[
				'id'     => 'mission',
				'label'  => __( 'Mission is active and up to date', 'mission-donation-platform' ),
				'status' => 'ok',
			],
		];

		$db_ready = version_compare(
			(string) get_option( DatabaseModule::DB_VERSION_OPTION, '0.0.0' ),
			DatabaseModule::DB_VERSION,
			'>='
		);

		$checks[] = [
			'id'     => 'database',
			'label'  => $db_ready
				? __( 'Database tables ready', 'mission-donation-platform' )
				: __( 'Database tables need updating. Reload this page to run the update.', 'mission-donation-platform' ),
			'status' => $db_ready ? 'ok' : 'warn',
		];

		$checks[] = [
			'id'     => 'environment',
			'label'  => sprintf(
				/* translators: 1: PHP version, 2: PHP memory limit. */
				__( 'PHP %1$s · Memory limit %2$s', 'mission-donation-platform' ),
				PHP_VERSION,
				(string) ini_get( 'memory_limit' )
			),
			'status' => 'info',
		];

		return $checks;
	}

	/**
	 * Build scan warnings.
	 *
	 * @return string[]
	 */
	private function build_warnings(): array {
		$warnings = [];

		if ( ! $this->source->has_campaigns() ) {
			$warnings[] = __( 'This GiveWP version has no campaigns. Each donation form will become a Mission campaign instead.', 'mission-donation-platform' );
		}

		return $warnings;
	}
}
