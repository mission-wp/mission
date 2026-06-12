<?php
/**
 * Donation frequency constants.
 *
 * @package MissionDP
 */

namespace MissionDP\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical frequency values shared by transaction types, subscription
 * billing frequencies, and donation form configuration.
 */
class Frequency {

	public const ONE_TIME  = 'one_time';
	public const WEEKLY    = 'weekly';
	public const MONTHLY   = 'monthly';
	public const QUARTERLY = 'quarterly';
	public const ANNUALLY  = 'annually';

	/**
	 * Recurring (subscription) frequencies.
	 *
	 * @var string[]
	 */
	public const RECURRING = [
		self::WEEKLY,
		self::MONTHLY,
		self::QUARTERLY,
		self::ANNUALLY,
	];

	/**
	 * Every frequency the donation form accepts, including one-time.
	 *
	 * @var string[]
	 */
	public const ALL = [
		self::ONE_TIME,
		self::WEEKLY,
		self::MONTHLY,
		self::QUARTERLY,
		self::ANNUALLY,
	];
}
