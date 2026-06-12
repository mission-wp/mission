<?php
/**
 * Shared pagination args for collection REST routes.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the standard page/per_page/orderby/order/search arg set.
 */
class CollectionParams {

	/**
	 * Standard paginated-collection args. Callers merge endpoint-specific
	 * filters on top with array_merge().
	 *
	 * @param string[] $orderby         Allowed orderby values; empty = no orderby/order args.
	 * @param string   $default_orderby Default orderby (required when $orderby is non-empty).
	 * @param int      $per_page        Default per_page.
	 * @param int      $max_per_page    Maximum per_page.
	 * @param bool     $search          Whether to include the search arg.
	 * @return array<string, array<string, mixed>>
	 */
	public static function base(
		array $orderby = [],
		string $default_orderby = '',
		int $per_page = 25,
		int $max_per_page = 100,
		bool $search = true
	): array {
		$params = [
			'page'     => Args::integer(
				[
					'default' => 1,
					'minimum' => 1,
				]
			),
			'per_page' => Args::integer(
				[
					'default' => $per_page,
					'minimum' => 1,
					'maximum' => $max_per_page,
				]
			),
		];

		if ( $orderby ) {
			$params['orderby'] = Args::enum( $orderby, [ 'default' => $default_orderby ] );
			$params['order']   = Args::enum( [ 'ASC', 'DESC' ], [ 'default' => 'DESC' ] );
		}

		if ( $search ) {
			$params['search'] = Args::string();
		}

		return $params;
	}
}
