<?php
/**
 * Donor dashboard overview endpoint.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints\DonorDashboard;

use Mission\Models\Transaction;
use Mission\Reporting\ReportingService;
use Mission\Rest\RestModule;
use Mission\Rest\Traits\DonorDashboardPrepareTrait;
use Mission\Rest\Traits\ResolveDonorTrait;
use Mission\Settings\SettingsService;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the donor dashboard overview route.
 */
class OverviewEndpoint {

	use ResolveDonorTrait;
	use DonorDashboardPrepareTrait;

	/**
	 * Constructor.
	 *
	 * @param ReportingService $reporting Reporting service.
	 * @param SettingsService  $settings  Settings service.
	 */
	public function __construct(
		private readonly ReportingService $reporting,
		private readonly SettingsService $settings,
	) {}

	/**
	 * Register REST routes.
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/overview',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_overview' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);
	}

	/**
	 * GET /donor-dashboard/overview
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_overview(): WP_REST_Response|WP_Error {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$is_test = $this->settings->get( 'test_mode', false );

		$total_donated     = $is_test ? $donor->test_total_donated : $donor->total_donated;
		$transaction_count = $is_test ? $donor->test_transaction_count : $donor->transaction_count;
		$average_donation  = $transaction_count > 0 ? (int) round( $total_donated / $transaction_count ) : 0;

		$recent = $donor->transactions(
			[
				'per_page' => 5,
				'status'   => 'completed',
				'is_test'  => $is_test,
				'orderby'  => 'date_completed',
				'order'    => 'DESC',
			]
		);

		$active_subs = $donor->subscriptions(
			[
				'status__in' => [ 'active', 'paused' ],
				'is_test'    => $is_test,
			]
		);

		$this->preload_campaigns(
			array_merge(
				array_map( static fn( $t ) => $t->campaign_id, $recent ),
				array_map( static fn( $s ) => $s->campaign_id, $active_subs ),
			)
		);

		return new WP_REST_Response(
			[
				'stats'                => [
					'total_donated'     => $total_donated,
					'transaction_count' => $transaction_count,
					'average_donation'  => $average_donation,
				],
				'recent_transactions'  => array_map( [ $this, 'prepare_transaction' ], $recent ),
				'active_subscriptions' => array_map( [ $this, 'prepare_subscription' ], $active_subs ),
			]
		);
	}
}
