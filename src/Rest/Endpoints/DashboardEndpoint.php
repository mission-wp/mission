<?php
/**
 * REST endpoint for the admin dashboard.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Models\ActivityLog;
use Mission\Reporting\ReportingService;
use Mission\Rest\RestModule;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard endpoint class.
 *
 * Returns all dashboard data in a single request: stats, chart data,
 * top campaigns, activity feed, and Stripe connection status.
 */
class DashboardEndpoint {

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
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/dashboard',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_dashboard' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'period' => [
						'type'              => 'string',
						'default'           => 'month',
						'enum'              => [ 'today', 'week', 'month' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Permission check — requires manage_options.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view the dashboard.', 'mission' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * GET handler — returns all dashboard data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_dashboard( WP_REST_Request $request ): WP_REST_Response {
		$period = $request->get_param( 'period' ) ?? 'month';

		[ $current_start, $current_end ]   = $this->get_date_range( $period );
		[ $previous_start, $previous_end ] = $this->get_previous_date_range( $period );

		$data = [
			'stats'            => $this->get_stats( $current_start, $current_end, $previous_start, $previous_end ),
			'chart'            => $this->reporting->chart_data( $current_start, $current_end, $period ),
			'campaigns'        => $this->reporting->top_campaigns(),
			'activity'         => $this->get_activity(),
			'stripe_connected' => 'connected' === $this->settings->get( 'stripe_connection_status', 'disconnected' ),
			'currency'         => $this->settings->get( 'currency', 'USD' ),
			'review_banner'    => $this->get_review_banner_data(),
		];

		/**
		 * Filters the dashboard response data before returning.
		 *
		 * @param array  $data   Dashboard data.
		 * @param string $period The requested period.
		 */
		$data = apply_filters( 'mission_dashboard_data', $data, $period );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Get donation stats for a date range.
	 *
	 * @param string $current_start  Current period start (Y-m-d H:i:s).
	 * @param string $current_end    Current period end (Y-m-d H:i:s).
	 * @param string $previous_start Previous period start (Y-m-d H:i:s).
	 * @param string $previous_end   Previous period end (Y-m-d H:i:s).
	 * @return array<string, int>
	 */
	private function get_stats( string $current_start, string $current_end, string $previous_start, string $previous_end ): array {
		$current  = $this->reporting->period_stats( $current_start, $current_end );
		$previous = $this->reporting->period_stats( $previous_start, $previous_end );

		return [
			'total_donations'           => (int) $current['total_amount'],
			'total_donors'              => (int) $current['donor_count'],
			'average_donation'          => (int) $current['avg_amount'],
			'total_donations_previous'  => (int) $previous['total_amount'],
			'total_donors_previous'     => (int) $previous['donor_count'],
			'average_donation_previous' => (int) $previous['avg_amount'],
		];
	}

	/**
	 * Get recent activity feed entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_activity(): array {
		$entries = ActivityLog::query(
			[
				'per_page' => 30,
				'orderby'  => 'date_created',
				'order'    => 'DESC',
				'is_test'  => (int) (bool) $this->settings->get( 'test_mode' ),
			]
		);

		/**
		 * Filters the object types excluded from the dashboard activity feed.
		 *
		 * Settings events are excluded by default because they're noisy, but
		 * developers can add or remove types as needed.
		 *
		 * @param string[] $excluded Object types to hide (e.g. 'settings', 'donor').
		 */
		$excluded_types = apply_filters( 'mission_dashboard_activity_excluded_types', [ 'settings' ] );

		/**
		 * Filters the event names excluded from the dashboard activity feed.
		 *
		 * @param string[] $excluded Event names to hide (e.g. 'admin_notification_sent').
		 */
		$excluded_events = apply_filters(
			'mission_dashboard_activity_excluded_events',
			[ 'admin_notification_sent' ]
		);

		// System events that should always appear despite their object_type.
		$always_show = [ 'plugin_installed', 'plugin_activated', 'plugin_updated' ];

		$entries = array_filter(
			$entries,
			static fn( $entry ) =>
				in_array( $entry->event, $always_show, true )
				|| (
					! in_array( $entry->object_type, $excluded_types, true )
					&& ! in_array( $entry->event, $excluded_events, true )
				),
		);
		$entries = array_slice( $entries, 0, 20 );

		return array_map(
			static function ( $entry ) {
				return [
					'id'           => $entry->id,
					'event'        => $entry->event,
					'object_type'  => $entry->object_type,
					'object_id'    => $entry->object_id,
					'data'         => $entry->data ? json_decode( $entry->data, true ) : null,
					'date_created' => $entry->date_created,
				];
			},
			$entries
		);
	}

	/**
	 * Get review banner data for the current user.
	 *
	 * The banner shows when: 25+ live donations, 14+ days since install,
	 * and the current user hasn't dismissed it.
	 *
	 * @return array{show: bool, total_raised?: int}
	 */
	private function get_review_banner_data(): array {
		$user_id = get_current_user_id();

		if ( get_user_meta( $user_id, 'mission_review_banner_dismissed', true ) ) {
			return [ 'show' => false ];
		}

		// Check activation date (14+ days ago).
		$installed_at = get_option( 'mission_installed_at' );

		if ( ! $installed_at ) {
			$installed_at = $this->backfill_installed_at();
		}

		$days_since = ( time() - strtotime( $installed_at ) ) / DAY_IN_SECONDS;

		if ( $days_since < 14 ) {
			return [ 'show' => false ];
		}

		$stats = $this->reporting->review_banner_stats();

		if ( $stats['donation_count'] < 25 ) {
			return [ 'show' => false ];
		}

		// Round down to nearest hundred dollars (amounts stored in cents).
		$total_raised = (int) ( floor( $stats['total_raised'] / 10000 ) * 10000 );

		return [
			'show'         => true,
			'total_raised' => $total_raised,
		];
	}

	/**
	 * Backfill the mission_installed_at option from the activity log.
	 *
	 * For sites that were installed before this option existed, we look up
	 * the plugin_installed event timestamp. Falls back to now.
	 *
	 * @return string Date string (Y-m-d H:i:s in UTC).
	 */
	private function backfill_installed_at(): string {
		$entries = ActivityLog::query(
			[
				'event'    => 'plugin_installed',
				'per_page' => 1,
				'orderby'  => 'date_created',
				'order'    => 'ASC',
			]
		);

		$date = ! empty( $entries ) ? $entries[0]->date_created : current_time( 'mysql', true );

		update_option( 'mission_installed_at', $date );

		return $date;
	}

	/**
	 * Get the date range for the requested period.
	 *
	 * @param string $period Period identifier (today, week, month).
	 * @return array{0: string, 1: string} Start and end dates.
	 */
	private function get_date_range( string $period ): array {
		$now = current_time( 'mysql', true );
		$end = $now;

		$start = match ( $period ) {
			'today' => gmdate( 'Y-m-d 00:00:00' ),
			'week'  => gmdate( 'Y-m-d 00:00:00', strtotime( '-6 days' ) ),
			default => gmdate( 'Y-m-d 00:00:00', strtotime( '-29 days' ) ),
		};

		return [ $start, $end ];
	}

	/**
	 * Get the previous comparison date range.
	 *
	 * @param string $period Period identifier (today, week, month).
	 * @return array{0: string, 1: string} Start and end dates.
	 */
	private function get_previous_date_range( string $period ): array {
		$end = match ( $period ) {
			'today' => gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day' ) ),
			'week'  => gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) ),
			default => gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days' ) ),
		};

		$start = match ( $period ) {
			'today' => gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day' ) ),
			'week'  => gmdate( 'Y-m-d 00:00:00', strtotime( '-13 days' ) ),
			default => gmdate( 'Y-m-d 00:00:00', strtotime( '-59 days' ) ),
		};

		return [ $start, $end ];
	}
}
