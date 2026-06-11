<?php
/**
 * GiveWP billing period mapping.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration\Migrators\GiveWP\Maps;

defined( 'ABSPATH' ) || exit;

/**
 * Maps GiveWP (period, frequency multiplier) pairs to Mission frequencies.
 *
 * GiveWP supports arbitrary cadences (every 2 weeks, every 6 months, daily)
 * that Mission does not. Those map lossily to 'monthly'; the writer records
 * the original cadence in subscription meta and surfaces a warning.
 */
class FrequencyMap {

	/**
	 * Map a GiveWP period + frequency to a Mission frequency.
	 *
	 * @param string $period    GiveWP period (day, week, month, quarter, year).
	 * @param int    $frequency GiveWP frequency multiplier (1 = every period).
	 *
	 * @return array{frequency: string, lossy: bool}
	 */
	public static function map( string $period, int $frequency ): array {
		$exact = match ( true ) {
			'week' === $period && 1 === $frequency => 'weekly',
			'month' === $period && 1 === $frequency => 'monthly',
			'month' === $period && 3 === $frequency, 'quarter' === $period && 1 === $frequency => 'quarterly',
			'year' === $period && 1 === $frequency => 'annually',
			default => null,
		};

		if ( null !== $exact ) {
			return [
				'frequency' => $exact,
				'lossy'     => false,
			];
		}

		return [
			'frequency' => 'monthly',
			'lossy'     => true,
		];
	}
}
